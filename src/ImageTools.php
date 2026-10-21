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
use Illuminate\Validation\ValidationException;
use Isapp\ImageTools\Jobs\GenerateImageJob;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use League\Flysystem\FilesystemException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\ImageOptimizer\Optimizers\Cwebp;

use function app;
use function array_keys;
use function array_pad;
use function basename;
use function config;
use function explode;
use function filter_var;
use function ltrim;
use function method_exists;
use function parse_str;
use function report;
use function storage_path;
use function str_contains;
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

    /**
     * Key of the medialibrary Media the source belongs to, recorded in the
     * manifest entry as 'media_id'. Set via media() or withMediaId(); never
     * part of the seed.
     */
    protected int|string|null $mediaId = null;

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
     * Return a copy that records the given medialibrary Media key in the entries
     * it writes. media() sets it; the queued job and imagetools:regenerate use it
     * to carry a recorded key along, because they have only the key, not the
     * Media. The key does not change the seed. Apps call media() instead.
     *
     * @internal
     */
    public function withMediaId(int|string|null $id): static
    {
        $clone = clone $this;
        $clone->mediaId = $id;

        return $clone;
    }

    /**
     * Return a URL for the original of a spatie/laravel-medialibrary Media:
     * ImageTools::media($media, 'fit=crop&w=1400&h=600&format=avif&q=75').
     * The call is the same as disk($media->disk)->asset($media->getPathRelativeToRoot() . '?' . $query)
     * and returns what asset() returns; the entry it writes also records the
     * Media key as 'media_id'. Any object with a 'disk' attribute and the methods
     * getPathRelativeToRoot() and getKey() works; medialibrary is not required.
     *
     * @param  \Spatie\MediaLibrary\MediaCollections\Models\Media|object  $media
     *
     * @throws \InvalidArgumentException when the object is not such a Media
     */
    public function media(object $media, string $query = '', string $manifest = 'default'): ?string
    {
        if (! method_exists($media, 'getPathRelativeToRoot') || ! method_exists($media, 'getKey')
            || ! \is_string($media->disk ?? null) || $media->disk === '') {
            throw new \InvalidArgumentException(
                'ImageTools::media() takes a medialibrary Media: an object with a "disk" attribute and the methods getPathRelativeToRoot() and getKey().'
            );
        }

        $file = $media->getPathRelativeToRoot();

        // The first '?' starts the query, so a '?' in an uploaded file name would
        // cut the path. The page gets '' as for any image that cannot be made.
        if (str_contains($file, '?')) {
            Log::warning('ImageTools: the Media file name contains a "?"; media() returns an empty string.', [
                'path' => $file,
                'disk' => $media->disk,
                'media_id' => $media->getKey(),
            ]);

            return '';
        }

        $query = ltrim($query, '?');
        $path = $file . ($query === '' ? '' : '?' . $query);

        return $this->disk($media->disk)->withMediaId($media->getKey())->asset($path, $manifest);
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
     *                     namespace is unknown, generate() returns null or throws
     *                     (the exception is reported), or a queued miss has no
     *                     fallback URL
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

            // A page must not fail because one image cannot be made: a query that
            // fails validation or a driver or encoder error is reported, and the
            // render gets ''. A queued job calls generate() directly and still fails.
            try {
                $generated = $this->generate($path, $manifest);
            } catch (\Throwable $e) {
                report($e);

                // The report names no image, and Laravel's handler never reports a
                // ValidationException at all; the warning says which image failed.
                Log::warning('ImageTools: the image could not be generated; asset() returns an empty string.', [
                    'path' => $path,
                    'disk' => $this->sourceDisk,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    ...($e instanceof ValidationException ? ['errors' => $e->errors()] : []),
                ]);

                return '';
            }

            if ($generated === null) {
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
     *  - optimize (bool, default on): off skips the optimizer chain; see optimizerChain()
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
            'optimize' => ['nullable', Rule::in(['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'])],
        ]);

        // Resolve the source to a local path (a disk source is streamed to a temp file).
        $source = $this->source->resolve($filepath, $this->sourceDisk);
        if ($source === null) {
            // TODO: throw exception
            return null;
        }

        try {
            $image = $this->loadImage($source['path']);

            if (! config('image-tools.allow_upscale') && $this->exceedsSource($image, $validated)) {
                return $this->recordOversize($path, $manifest);
            }

            // The same destination backs asset()'s pre-computed URL, so the file
            // written here is exactly the one asset() points to.
            $savePath = $this->paths->storedFile($path, $this->sourceDisk)['path'];

            // A manifest can lose an entry while its file stays on the disk (a
            // manifest that was deleted or rebuilt). A file at the deterministic
            // name is then adopted instead of encoded again. The check and the
            // write share the manifest lock, so prune cannot delete the file in
            // between. An entry that exists (regenerate) is always encoded again.
            // The first check runs without the lock: on a real miss there is no
            // file, and the disk calls must not hold up every other writer.
            if ($this->isStored($disk, $savePath) && $this->manifest->putIfAbsent(
                $manifest,
                $this->paths->seed($path, $this->sourceDisk),
                $this->entry($savePath, $disk, $path),
                fn (): bool => $this->isStored($disk, $savePath)
            )) {
                return [
                    'path' => $savePath,
                    'disk' => $disk,
                ];
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

            $fileName = basename($savePath);
            $tmpPath = storage_path($savePath);

            File::ensureDirectoryExists(\dirname($tmpPath));

            // The lossless switch is set on the image even when the chain is skipped.
            $losslessChain = $this->applyLossless($image, $savePath, $validated['lossless'] ?? false);

            $chain = filter_var($validated['optimize'] ?? true, FILTER_VALIDATE_BOOLEAN)
                ? ($losslessChain ?? $this->optimizerChain())
                : null;

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

            $stored = Storage::disk($disk)->putFileAs(
                'image-tools',
                new \Illuminate\Http\File($tmpPath),
                $fileName,
                (array) config('image-tools.put_options', [])
            );
            File::delete($tmpPath);

            // A disk with its 'throw' option off (the Laravel default) returns
            // false instead of throwing, and reports nothing unless 'report' is on.
            if (! $stored) {
                Log::warning('ImageTools: the upload of the generated file failed; nothing was stored.', [
                    'path' => $path,
                    'disk' => $disk,
                    'stored_as' => $savePath,
                    'put_options' => array_keys((array) config('image-tools.put_options', [])),
                ]);

                return null;
            }

            // The source is recorded next to the output so a later pass — see the
            // imagetools:regenerate command — can rebuild this exact derivative
            // without re-deriving anything from the key.
            $this->manifest->put($manifest, $this->paths->seed($path, $this->sourceDisk), $this->entry($savePath, $disk, $path));

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
     * Load the local source with the configured image driver. With no 'driver'
     * config, spatie/image picks it: Imagick when the extension is loaded, else GD.
     * A value names a driver ('gd', 'imagick', 'vips') or an ImageDriver class;
     * spatie/image throws for any other value.
     */
    protected function loadImage(string $path): Image
    {
        $driver = config('image-tools.driver');

        if ($driver === null || $driver === '') {
            return new Image($path);
        }

        return Image::useImageDriver($driver)->loadFile($path);
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
        $this->manifest->put($manifest, $seed, $this->entry(null, null, $path));

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
     * The manifest entry for a stored file, or for no file (a null path and disk).
     * A Media key is added only when there is one (see media()).
     *
     * @return array{path: string|null, disk: string|null, source: string, source_disk: string|null, media_id?: int|string}
     */
    protected function entry(?string $path, ?string $disk, string $source): array
    {
        $entry = [
            'path' => $path,
            'disk' => $disk,
            'source' => $source,
            'source_disk' => $this->sourceDisk,
        ];

        if ($this->mediaId !== null) {
            $entry['media_id'] = $this->mediaId;
        }

        return $entry;
    }

    /**
     * Whether a file is on the disk with at least one byte in it. An empty file
     * is what an optimizer killed mid-write leaves behind; it is not a derivative.
     */
    protected function isStored(?string $disk, string $path): bool
    {
        $storage = Storage::disk($disk);

        // A disk that cannot answer (for example S3 credentials without the right
        // to list, which turn a missing key into a 403) must not stop generation:
        // the file is then encoded as when nothing is stored.
        try {
            return $storage->fileExists($path) && $storage->size($path) > 0;
        } catch (FilesystemException $e) {
            Log::warning('ImageTools: the stored file could not be checked; the image is encoded again.', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
     * The chain that runs after an ordinary encode: the 'optimizers' config, or
     * the spatie/image-optimizer default chain when it is null. The chain is
     * built here rather than with OptimizerChainFactory::create($config),
     * because the factory ignores a config that has none of its own seven
     * optimizer classes and returns the default chain instead.
     */
    protected function optimizerChain(): OptimizerChain
    {
        $optimizers = config('image-tools.optimizers');

        if ($optimizers === null) {
            return OptimizerChainFactory::create();
        }

        $chain = new OptimizerChain;

        foreach ($optimizers as $optimizer => $options) {
            $chain->addOptimizer(new $optimizer($options));
        }

        return $chain;
    }

    /**
     * Encode the prepared image to a local temporary path, then run the chain
     * on it. A null chain runs no optimizer (the query has 'optimize=0').
     * optimize() is a no-op when no optimizer binaries are installed.
     */
    protected function writeDerivative(Image $image, string $tmpPath, ?OptimizerChain $chain = null): void
    {
        if ($chain !== null) {
            $image->optimize($chain);
        }

        $image->save($tmpPath);
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
        try {
            // The PendingDispatch takes the unique lock and pushes the job when it is
            // destroyed, at the end of this statement. Bus::dispatch() skips the lock.
            GenerateImageJob::dispatch($path, $manifest, $this->sourceDisk, $this->mediaId);
        } catch (\Throwable $e) {
            // Laravel keeps the unique lock when the push throws, which would block
            // this seed for unique_for. A duplicate job is harmless: it checks has().
            try {
                (new UniqueLock(app(Cache::class)))->release(new GenerateImageJob($path, $manifest, $this->sourceDisk, $this->mediaId));
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
