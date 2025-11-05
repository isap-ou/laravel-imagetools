<?php

declare(strict_types = 1);

namespace Isapp\ImageTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Isapp\ImageTools\Facades\ImageTools;
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

        collect($wanted)->unique()->each(fn ($path) => ImageTools::generate($path));

        return self::SUCCESS;
    }

    /**
     * Parse a PHP string to AST and collect occurrences of ImageTools::asset('...') static calls.
     *
     * @param string $php The PHP source to analyze.
     * @param \PhpParser\Parser $parser Parser instance.
     * @param array $wanted Collected "path?query" strings (by reference).
     * @param string $origin Optional filename used for warnings.
     */
    private function collectFromPhp(string $php, $parser, array &$wanted, string $origin = ''): void
    {
        xdebug_break();
        try {
            $ast = $parser->parse($php);
        } catch (\Throwable $e) {
            $this->warn("Parse error in {$origin}: " . $e->getMessage());

            return;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(
            new class($wanted) extends NodeVisitorAbstract {
                public function __construct(public array &$wanted)
                {
                }

                public function enterNode(Node $node): void
                {
                    // Match static calls: ImageTools::asset('...')
                    if ($node instanceof Node\Expr\StaticCall && $this->nameIs($node->name, 'asset')) {
                        $class = $node->class;
                        if ($class instanceof Node\Name && $class->toString() === 'ImageTools') {
                            $this->recordCall($node->args);
                            return;
                        }
                    }

                    // Support container-resolved instance calls: app(...)->asset(...) / App::make(...)->asset(...)
                    // Match method calls on a resolved service: app(...)->asset('...') or App::make(...)->asset('...')
                    if ($node instanceof Node\Expr\MethodCall && $this->nameIs($node->name, 'asset')) {
                        $target = $node->var;

                        // app(ImageTools::class|'image-tools')
                        if ($target instanceof Node\Expr\FuncCall && $this->nameIs($target->name, 'app')) {
                            if ($this->isImageToolsServiceArg($target->args[0] ?? null)) {
                                $this->recordCall($node->args);
                                return;
                            }
                        }

                        // App::make(ImageTools::class|'image-tools')
                        if ($target instanceof Node\Expr\StaticCall && $this->nameIs($target->name, 'make')) {
                            $className = $target->class instanceof Node\Name ? $target->class->toString() : null;
                            if (in_array($className, ['App', 'Illuminate\\Support\\Facades\\App'], true) &&
                                $this->isImageToolsServiceArg($target->args[0] ?? null)
                            ) {
                                $this->recordCall($node->args);
                                return;
                            }
                        }
                    }
                }

                protected function nameIs($nameNode, string $expected): bool
                {
                    if ($nameNode instanceof Node\Name || $nameNode instanceof Node\Identifier) {
                        return $nameNode->toString() === $expected;
                    }

                    return false;
                }

                protected function recordCall(array $args): void
                {
                    if (empty($args)) {
                        return;
                    }
                    /** @var Node\Scalar\String_ $pathArg */
                    $pathArg = $args[0]->value ?? null;
                    if (! ($pathArg instanceof Node\Scalar\String_)) {
                        return;
                    }
                    $this->wanted[] = $pathArg->value;
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
