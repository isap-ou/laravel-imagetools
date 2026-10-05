<?php

declare(strict_types=1);

namespace Isapp\ImageTools;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Isapp\ImageTools\Jobs\GenerateImageJob;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\ImageOptimizer\Optimizers\Cwebp;

use function app;
use function array_pad;
use function basename;
use function config;
use function dispatch;
use function explode;
use function filter_var;
use function parse_str;
use function storage_path;
use function str_ends_with;
use function var_export;

/**
 * ImageTools: deterministic, query‑driven image generator (vite‑imagetools‑like).
 *
 * Usage:
 *  - ImageTools::asset('path/to/img.jpg?w=1200&h=630&fit=contain&format=webp&q=82')
 *    returns a URL from the configured filesystem disk and records the mapping in a PHP manifest.
 *    A resize by w or h larger than the source returns null instead (see 'allow_upscale').
 *  - Call disk() to read the original from a Laravel filesystem disk instead of
 *    locally, e.g. ImageTools::disk('s3')->asset('assets/hero.jpg?w=1200').
 *
 * Orchestrates three collaborators: Manifest (state + persistence), PathResolver
 * (canonical seed + destination) and SourceReader (local/disk source resolution).
 */
class ImageTools
{
    /**
     * Laravel disk the source image is read from. null = local filesystem
     * (relative to base_path). Set via disk(); folded into the canonical seed.
     */
    protected ?string $sourceDisk = null;

    public function __construct(
        protected Manifest $manifest,
        protected PathResolver $paths,
        protected SourceReader $source,
    ) {}

    /**
     * Return a copy scoped to read source images from the given Laravel disk.
     * Mirrors Storage::disk(): ImageTools::disk('s3')->asset('assets/hero.jpg?w=800').
     */
    public function disk(string $disk): static
    {
        $clone = clone $this;
        $clone->sourceDisk = $disk;

        return $clone;
    }

    /**
     * Load a manifest into memory (delegates to the Manifest collaborator).
     */
    public function loadManifest(?string $path = null): void
    {
        $this->manifest->load($path);
    }

    /**
     * Whether the manifest has an entry for a "path?query", after it reads a
     * changed manifest file again. An entry with a null path counts.
     */
    public function has(string $path, string $manifest = 'default'): bool
    {
        $this->manifest->refresh($manifest);

        return $this->manifest->has($manifest, $this->paths->seed($path, $this->sourceDisk));
    }

    /**
     * Return a public URL for a given "path?query". If the canonical key is
     * missing in the manifest, generate the image first (or queue it and return
     * the 'queue_fallback' value) and return the URL.
     *
     * @param  string  $path  Source path with query (e.g., 'resources/img/hero.jpg?w=1200&format=webp')
     * @param  string  $manifest  Manifest namespace ('default' by default)
     * @return string|null null when the manifest entry has no file: the request is
     *                     larger than the source and upscaling is off; '' when the
     *                     namespace is unknown, generate() returns null, or a queued
     *                     miss has no fallback URL
     *
     * @throws \InvalidArgumentException for a queued miss with an unknown 'queue_fallback'
     */
    public function asset(string $path, string $manifest = 'default'): ?string
    {
        if (! $this->manifest->exists($manifest)) {
            // TODO throw exception;
            return '';
        }

        $seed = $this->paths->seed($path, $this->sourceDisk);

        if (! $this->manifest->has($manifest, $seed)) {
            // A queue worker may have written the entry since this process read the manifest.
            $this->manifest->refresh($manifest);
        }

        if (! $this->manifest->has($manifest, $seed)) {
            // Deferred mode: queue the job and return the fallback now. A sync
            // connection and a failed dispatch take the synchronous path below.
            if ($this->shouldQueue($path)) {
                // Checked first, so a wrong value fails on a sync connection (tests) too.
                $fallback = $this->queueFallbackMode();

                if (! $this->queueRunsInline() && $this->dispatchGeneration($path, $manifest)) {
                    return $this->queuedFallback($path, $fallback);
                }
            }

            if ($this->generate($path, $manifest) === null) {
                return '';
            }
        }

        $file = $this->manifest->get($manifest, $seed);

        // A request larger than the source has an entry but no file.
        if ($file['path'] === null) {
            return null;
        }

        return Storage::disk($file['disk'])->url($file['path']);
    }

    /**
     * Generate a processed image for the given "path?query" and store it on the
     * configured disk. Supported options (validated):
     *  - w (int), h (int): target dimensions
     *  - fit (Spatie\Image\Enums\Fit): if present, both w and h are required
     *  - q (1..100): quality
     *  - format: one of jpeg, png, gif, webp, avif
     *  - lossless (bool): lossless WebP; see applyLossless() for its two conditions
     *
     * A resize by w or h larger than the source stores no file unless the
     * 'allow_upscale' config allows it; see recordOversize().
     *
     * @return array{path: string|null, disk: string|null}|null null for a missing source, an
     *                                                          empty encode or a failed upload. A null path and
     *                                                          disk for a request larger than the source.
     */
    public function generate(string $path, string $manifest = 'default'): ?array
    {
        $disk = config('image-tools.disk');

        [$filepath, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        // Validate supported query options. 'w' and 'h' are required together when 'fit' is used.
        $validated = Validator::validate($options, [
            'w' => ['required_with:fit', 'integer', 'min:1'],
            'h' => ['required_with:fit', 'integer', 'min:1'],
            'q' => ['max:100', 'min:1', 'integer'],
            'fit' => ['nullable', Rule::enum(Fit::class)],
            'format' => ['nullable', Rule::in(['jpeg', 'png', 'gif', 'webp', 'avif'])],
            // Accepts every spelling FILTER_VALIDATE_BOOLEAN reads, the way the
            // 'queue' flag already does. Laravel's 'boolean' rule takes only
            // 1/0, so 'lossless=true' in a template would raise instead.
            'lossless' => ['nullable', Rule::in(['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'])],
        ]);

        // Resolve the source to a local path (a disk source is streamed to a temp file).
        $source = $this->source->resolve($filepath, $this->sourceDisk);
        if ($source === null) {
            // TODO: throw exception
            return null;
        }

        try {
            $image = new Image($source['path']);

            if (! config('image-tools.allow_upscale') && $this->exceedsSource($image, $validated)) {
                return $this->recordOversize($path, $manifest);
            }

            // Apply geometry: 'fit' resizes to exactly w x h; otherwise resize by
            // whichever single side is present. Cast to int for the driver.
            if (! empty($validated['fit'])) {
                $image->fit(Fit::from($validated['fit']), (int) $validated['w'], (int) $validated['h']);
            } elseif (! empty($validated['w'])) {
                $image->width((int) $validated['w']);
            } elseif (! empty($validated['h'])) {
                $image->height((int) $validated['h']);
            }
            if (! empty($validated['q'])) {
                $image->quality((int) $validated['q']);
            }

            // The same destination backs asset()'s pre-computed URL, so the file
            // written here is exactly the one asset() points to.
            $savePath = $this->paths->storedFile($path, $this->sourceDisk)['path'];
            $fileName = basename($savePath);
            $tmpPath = storage_path($savePath);

            File::ensureDirectoryExists(\dirname($tmpPath));

            $chain = $this->applyLossless($image, $savePath, $validated['lossless'] ?? false);

            $this->writeDerivative($image, $tmpPath, $chain);

            // An optimizer that is killed mid-run (a timeout on a large source)
            // leaves the file it was rewriting in place empty. Uploading that byte
            // count would make the manifest point at a permanently broken image,
            // so the encode counts as a failure instead.
            if (! File::exists($tmpPath) || File::size($tmpPath) === 0) {
                File::delete($tmpPath);

                Log::warning('ImageTools: the encode produced an empty file; nothing was stored.', [
                    'path' => $path,
                    'disk' => $this->sourceDisk,
                ]);

                return null;
            }

            $stored = Storage::disk($disk)->putFileAs('image-tools', new \Illuminate\Http\File($tmpPath), $fileName);
            File::delete($tmpPath);

            if (! $stored) {
                return null;
            }

            // The source is recorded next to the output so a later pass — see the
            // imagetools:regenerate command — can rebuild this exact derivative
            // without re-deriving anything from the key.
            $this->manifest->put($manifest, $this->paths->seed($path, $this->sourceDisk), [
                'path' => $savePath,
                'disk' => $disk,
                'source' => $path,
                'source_disk' => $this->sourceDisk,
            ]);

            return [
                'path' => $savePath,
                'disk' => $disk,
            ];
        } finally {
            // Always remove the temporary copy of a disk-sourced original.
            if ($source['temporary']) {
                File::delete($source['path']);
            }
        }
    }

    /**
     * Whether a resize by one side asks for more than the source holds. Without
     * 'fit' the geometry resizes by 'w' when it is present, else by 'h', so only
     * that side is compared. 'fit' keeps its own sizing rules and never counts.
     * For example, 'contain' and 'max' do not always fill the box. The size comes
     * from the loaded image. The driver has already rotated it by its EXIF
     * orientation (GD only when ext-exif is loaded), so it is the size the resize
     * works on.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function exceedsSource(Image $image, array $validated): bool
    {
        if (! empty($validated['fit'])) {
            return false;
        }

        if (! empty($validated['w'])) {
            return (int) $validated['w'] > $image->getWidth();
        }

        if (! empty($validated['h'])) {
            return (int) $validated['h'] > $image->getHeight();
        }

        return false;
    }

    /**
     * Record a request larger than the source as an entry with no file, so the
     * next asset() call returns null without loading the source again. A file
     * that an earlier, enlarging run stored under this key is deleted: once the
     * entry holds a null path, no command can find that file any more.
     *
     * The file is looked for at its deterministic name as well as in the entry.
     * A manifest can lose the entry while the file stays on the disk — a
     * per-release bootstrap/cache starts empty after each deploy.
     *
     * @return array{path: null, disk: null}
     */
    protected function recordOversize(string $path, string $manifest): array
    {
        $seed = $this->paths->seed($path, $this->sourceDisk);
        $previous = $this->manifest->get($manifest, $seed);
        $stored = $this->paths->storedFile($path, $this->sourceDisk);

        // The entry comes first. If a delete then fails, the worst case is a file
        // that nothing points to, never an entry that points to a deleted file.
        $this->manifest->put($manifest, $seed, [
            'path' => null,
            'disk' => null,
            'source' => $path,
            'source_disk' => $this->sourceDisk,
        ]);

        $this->deleteQuietly($stored['disk'], $stored['path']);

        if (! empty($previous['path']) && [$previous['disk'], $previous['path']] !== [$stored['disk'], $stored['path']]) {
            $this->deleteQuietly($previous['disk'], $previous['path']);
        }

        return [
            'path' => null,
            'disk' => null,
        ];
    }

    /**
     * Delete an old file as a clean-up step. A disk can refuse the delete — for
     * example, bucket credentials without delete rights. A disk with its 'throw'
     * option on throws; with the option off, delete() returns false. Both are
     * logged, and neither fails the request that asked for the image.
     */
    protected function deleteQuietly(?string $disk, string $path): void
    {
        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('ImageTools: an old file could not be deleted.', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($deleted === false) {
            Log::warning('ImageTools: an old file could not be deleted.', [
                'disk' => $disk,
                'path' => $path,
            ]);
        }
    }

    /**
     * Switch the image to lossless WebP and return the optimizer chain that keeps
     * it lossless. Two conditions must hold: the output is a WebP, and the driver
     * is Imagick — the switch is an Imagick option and GD has no equivalent. When
     * either fails, the ordinary encode runs and null is returned.
     *
     * The chain matters as much as the option: the default chain re-encodes every
     * WebP with a lossy cwebp call, which would undo the lossless write.
     */
    protected function applyLossless(Image $image, string $savePath, mixed $lossless): ?OptimizerChain
    {
        if (! filter_var($lossless, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        if (! str_ends_with($savePath, '.webp') || $image->driverName() !== 'imagick') {
            return null;
        }

        $image->image()->setOption('webp:lossless', 'true');

        return $this->losslessOptimizerChain();
    }

    /**
     * A cwebp-only chain that re-encodes losslessly. Only cwebp handles WebP in
     * the default chain, so dropping the other optimizers costs nothing here.
     */
    protected function losslessOptimizerChain(): OptimizerChain
    {
        return OptimizerChainFactory::create([
            Cwebp::class => ['-lossless', '-m 6', '-mt'],
        ]);
    }

    /**
     * Encode the prepared image to a local temporary path. optimize() is a no-op
     * when no optimizer binaries are installed; a chain is passed only when the
     * request needs options the default chain does not carry.
     */
    protected function writeDerivative(Image $image, string $tmpPath, ?OptimizerChain $chain = null): void
    {
        $image->optimize($chain)->save($tmpPath);
    }

    /**
     * Whether a miss is queued: the "queue" query flag when present, else the
     * 'queue' config. A control flag only: it is excluded from the seed.
     */
    protected function shouldQueue(string $path): bool
    {
        [, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        if (\array_key_exists('queue', $options)) {
            return filter_var($options['queue'], FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('image-tools.queue', false);
    }

    /**
     * The 'queue_fallback' config value; an unknown value throws.
     */
    protected function queueFallbackMode(): string
    {
        $fallback = config('image-tools.queue_fallback', 'original');

        if (! \in_array($fallback, ['original', 'none', 'derivative'], true)) {
            throw new \InvalidArgumentException(
                'Unknown image-tools.queue_fallback ' . var_export($fallback, true) . '; use original, none or derivative.'
            );
        }

        return $fallback;
    }

    /**
     * What asset() returns for a queued miss (see the 'queue_fallback' config).
     */
    protected function queuedFallback(string $path, string $fallback): string
    {
        [$filepath] = explode('?', $path, 2);

        return match ($fallback) {
            'original' => $this->source->publicUrl($filepath, $this->sourceDisk) ?? '',
            'none' => '',
            'derivative' => $this->storedFileUrl($path),
        };
    }

    /**
     * The URL of the derivative at its deterministic name, whether or not it exists yet.
     */
    protected function storedFileUrl(string $path): string
    {
        $info = $this->paths->storedFile($path, $this->sourceDisk);

        return Storage::disk($info['disk'])->url($info['path']);
    }

    /**
     * Whether the job's connection runs jobs in the request (the 'sync' driver).
     */
    protected function queueRunsInline(): bool
    {
        $connection = config('image-tools.queue_connection') ?: config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    /**
     * Dispatch a queued job that generates the derivative for the given path.
     * The source disk is carried along so the worker reads from the same origin.
     * Returns false when the dispatch fails; the caller then generates in the request.
     */
    protected function dispatchGeneration(string $path, string $manifest): bool
    {
        $job = new GenerateImageJob($path, $manifest, $this->sourceDisk);

        try {
            // The dispatch() helper takes the unique lock; Bus::dispatch() does not.
            dispatch($job);
        } catch (\Throwable $e) {
            // Laravel keeps the unique lock when the push throws, which would block
            // this seed for unique_for. A duplicate job is harmless: it checks has().
            try {
                (new UniqueLock(app(Cache::class)))->release($job);
            } catch (\Throwable) {
            }

            Log::warning('ImageTools: the generation job could not be queued; the image is generated in the request.', [
                'path' => $path,
                'disk' => $this->sourceDisk,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
