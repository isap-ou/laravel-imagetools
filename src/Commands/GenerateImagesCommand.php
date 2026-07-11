<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Isapp\ImageTools\Facades\ImageTools;
use Isapp\ImageTools\Support\PathResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

use function app;
use function base_path;
use function str;

/**
 * Scans Blade templates and PHP files for ImageTools::asset(...) usages
 * and generates images ahead of time (e.g. during CI/build).
 */
class GenerateImagesCommand extends Command
{
    protected $signature = 'imagetools:generate';

    protected $description = 'Scan Blade & PHP for ImageTools::asset(...) and generate images.';

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function handle(): int
    {
        $this->call(ClearGeneratedImagesCommand::class);

        $fs = new Filesystem;
        $bladeCompiler = app('blade.compiler');
        $config = app('config')->get('image-tools');

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        $wanted = [];

        foreach ($config['blade_paths'] as $bladeDir) {
            $bladeDir = base_path(str($bladeDir)->trim()->toString());
            if (empty($bladeDir) || ! File::isDirectory($bladeDir)) {
                continue;
            }
            $bladeFiles = (new Finder)->files()
                ->in($bladeDir)
                ->name('*.blade.php');

            foreach ($bladeFiles as $file) {
                $src = $fs->get($file->getRealPath());
                $php = $bladeCompiler->compileString($src);
                $this->collectFromPhp($php, $parser, $wanted, $file->getRelativePathname());
            }
        }
        foreach ($config['php_paths'] as $dir) {
            $path = base_path(str($dir)->trim()->toString());
            if (empty($path) || ! File::isDirectory($path)) {
                continue;
            }
            $phpFiles = (new Finder)->files()->in($path)->name('*.php');
            foreach ($phpFiles as $file) {
                $php = $fs->get($file->getRealPath());
                $this->collectFromPhp($php, $parser, $wanted, $file->getRelativePathname());
            }
        }

        $resolver = app(PathResolver::class);

        collect($wanted)
            ->unique(fn (array $want) => $resolver->seed($want['path'], $want['disk']))
            ->each(function (array $want) {
                $tool = $want['disk'] !== null ? ImageTools::disk($want['disk']) : app('image-tools');
                $tool->generate($want['path']);
            });

        return self::SUCCESS;
    }

    /**
     * Parse a PHP string to AST and collect occurrences of ImageTools::asset('...') static calls.
     *
     * @param  string  $php  The PHP source to analyze.
     * @param  \PhpParser\Parser  $parser  Parser instance.
     * @param  array  $wanted  Collected ['path' => "path?query", 'disk' => ?string] pairs (by reference).
     * @param  string  $origin  Optional filename used for warnings.
     */
    private function collectFromPhp(string $php, $parser, array &$wanted, string $origin = ''): void
    {
        try {
            $ast = $parser->parse($php);
        } catch (\Throwable $e) {
            $this->warn("Parse error in {$origin}: " . $e->getMessage());

            return;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(
            new class($wanted) extends NodeVisitorAbstract
            {
                public function __construct(public array &$wanted) {}

                public function enterNode(Node $node): void
                {
                    // Match static facade calls: ImageTools::asset('...')
                    if ($node instanceof Node\Expr\StaticCall && $this->nameIs($node->name, 'asset')
                        && $node->class instanceof Node\Name && $node->class->toString() === 'ImageTools'
                    ) {
                        $this->recordCall($node->args, null);

                        return;
                    }

                    // Instance calls: <accessor>->asset('...'), where <accessor> is
                    // app(...)/App::make(...)/ImageTools, optionally wrapped in one
                    // ->disk('x') / ::disk('x') so disk-sourced images are pre-generated too.
                    if ($node instanceof Node\Expr\MethodCall && $this->nameIs($node->name, 'asset')) {
                        $ctx = $this->resolveReceiver($node->var);
                        if ($ctx !== null) {
                            $this->recordCall($node->args, $ctx['disk']);
                        }
                    }
                }

                /**
                 * If $recv resolves to an ImageTools accessor (app('image-tools'),
                 * App::make(...) or the ImageTools facade), optionally wrapped in a
                 * single ->disk('x') / ::disk('x'), return ['disk' => ?string];
                 * otherwise null. A non-literal disk($var) yields null so it is
                 * skipped rather than mistakenly generated as a local source.
                 *
                 * @return array{disk: ?string}|null
                 */
                protected function resolveReceiver(?Node $recv): ?array
                {
                    if ($recv === null) {
                        return null;
                    }

                    // <accessor>->disk('x')
                    if ($recv instanceof Node\Expr\MethodCall && $this->nameIs($recv->name, 'disk')) {
                        if ($this->resolveReceiver($recv->var) === null) {
                            return null;
                        }
                        $disk = $this->stringArg($recv->args[0] ?? null);

                        return $disk === null ? null : ['disk' => $disk];
                    }

                    // ImageTools::disk('x')
                    if ($recv instanceof Node\Expr\StaticCall && $this->nameIs($recv->name, 'disk')
                        && $recv->class instanceof Node\Name && $recv->class->toString() === 'ImageTools'
                    ) {
                        $disk = $this->stringArg($recv->args[0] ?? null);

                        return $disk === null ? null : ['disk' => $disk];
                    }

                    // app(ImageTools::class|'image-tools')
                    if ($recv instanceof Node\Expr\FuncCall && $this->nameIs($recv->name, 'app')
                        && $this->isImageToolsServiceArg($recv->args[0] ?? null)
                    ) {
                        return ['disk' => null];
                    }

                    // App::make(ImageTools::class|'image-tools')
                    if ($recv instanceof Node\Expr\StaticCall && $this->nameIs($recv->name, 'make')) {
                        $className = $recv->class instanceof Node\Name ? $recv->class->toString() : null;
                        if (\in_array($className, ['App', 'Illuminate\\Support\\Facades\\App'], true) &&
                            $this->isImageToolsServiceArg($recv->args[0] ?? null)
                        ) {
                            return ['disk' => null];
                        }
                    }

                    return null;
                }

                /**
                 * Return the literal string value of an argument, or null when the
                 * argument is missing or not a plain string literal.
                 */
                protected function stringArg(?Node\Arg $arg): ?string
                {
                    if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                        return $arg->value->value;
                    }

                    return null;
                }

                protected function nameIs($nameNode, string $expected): bool
                {
                    if ($nameNode instanceof Node\Name || $nameNode instanceof Node\Identifier) {
                        return $nameNode->toString() === $expected;
                    }

                    return false;
                }

                protected function recordCall(array $args, ?string $disk): void
                {
                    if (empty($args)) {
                        return;
                    }
                    /** @var Node\Scalar\String_ $pathArg */
                    $pathArg = $args[0]->value ?? null;
                    // Only explicit, literal image paths are pre-generated; dynamic
                    // arguments (variables, interpolation, concatenation) are skipped.
                    if (! ($pathArg instanceof Node\Scalar\String_)) {
                        return;
                    }
                    $this->wanted[] = ['path' => $pathArg->value, 'disk' => $disk];
                }

                private function isImageToolsServiceArg(?Node\Arg $arg): bool
                {
                    if (! $arg instanceof Node\Arg) {
                        return false;
                    }

                    $value = $arg->value;

                    // String service id: 'image-tools'
                    if ($value instanceof Node\Scalar\String_) {
                        return $value->value === 'image-tools';
                    }

                    // Class reference: ImageTools::class or \Isapp\ImageTools\ImageTools::class
                    if ($value instanceof Node\Expr\ClassConstFetch &&
                        $value->name instanceof Node\Identifier &&
                        $value->name->toString() === 'class' &&
                        $value->class instanceof Node\Name
                    ) {
                        $fqcn = $value->class->toString();

                        return $fqcn === 'ImageTools' ||
                            $fqcn === 'Isapp\\ImageTools\\ImageTools' ||
                            str_ends_with($fqcn, '\\ImageTools');
                    }

                    return false;
                }
            }
        );

        $traverser->traverse($ast ?? []);
    }
}
