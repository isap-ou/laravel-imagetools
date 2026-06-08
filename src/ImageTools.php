<?php

declare(strict_types=1);

namespace Isapp\ImageTools;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Isapp\ImageTools\Jobs\GenerateImageJob;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

use function array_pad;
use function basename;
use function config;
use function explode;
use function filter_var;
use function parse_str;
use function storage_path;

/**
 * ImageTools: deterministic, query‑driven image generator (vite‑imagetools‑like).
 *
 * Usage:
 *  - ImageTools::asset('path/to/img.jpg?w=1200&h=630&fit=contain&format=webp&q=82')
 *    returns a URL from the configured filesystem disk and records the mapping in a PHP manifest.
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
     * Return a public URL for a given "path?query". If the canonical key is
     * missing in the manifest, generate the image first (or queue it) and return
     * the URL.
     *
     * @param  string  $path  Source path with query (e.g., 'resources/img/hero.jpg?w=1200&format=webp')
     * @param  string  $manifest  Manifest namespace ('default' by default)
     */
    public function asset(string $path, string $manifest = 'default'): string
    {
        if (! $this->manifest->exists($manifest)) {
            // TODO throw exception;
            return '';
        }

        $seed = $this->paths->seed($path, $this->sourceDisk);

        if (! $this->manifest->has($manifest, $seed)) {
            // Deferred mode: when the request opts in via a truthy "queue" flag,
            // push generation onto the queue and return the final, deterministic
            // URL immediately. The file appears once the worker finishes.
            if ($this->shouldQueue($path)) {
                $this->dispatchGeneration($path, $manifest);

                $info = $this->paths->storedFile($path, $this->sourceDisk);

                return Storage::disk($info['disk'])->url($info['path']);
            }

            if ($this->generate($path, $manifest) === null) {
                return '';
            }
        }

        $file = $this->manifest->get($manifest, $seed);

        return Storage::disk($file['disk'])->url($file['path']);
    }

    /**
     * Generate a processed image for the given "path?query" and store it on the
     * configured disk. Supported options (validated):
     *  - w (int), h (int): target dimensions
     *  - fit (Spatie\Image\Enums\Fit): if present, both w and h are required
     *  - q (1..100): quality
     *  - format: one of jpeg, png, gif, webp, avif
     *
     * @return array{path: string, disk: string}|null null on missing source or storage failure
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
        ]);

        // Resolve the source to a local path (a disk source is streamed to a temp file).
        $source = $this->source->resolve($filepath, $this->sourceDisk);
        if ($source === null) {
            // TODO: throw exception
            return null;
        }

        try {
            $image = new Image($source['path']);

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

            // optimize() is a no-op when no optimizer binaries are installed.
            $image->optimize()->save($tmpPath);

            $stored = Storage::disk($disk)->putFileAs('image-tools', new \Illuminate\Http\File($tmpPath), $fileName);
            File::delete($tmpPath);

            if (! $stored) {
                return null;
            }

            $this->manifest->put($manifest, $this->paths->seed($path, $this->sourceDisk), [
                'path' => $savePath,
                'disk' => $disk,
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
     * Whether the request opts into deferred (queued) generation via a truthy
     * "queue" query flag, e.g. 'hero.jpg?w=1200&queue=1'. A control flag only:
     * it is excluded from the seed, so it never affects the filename or key.
     */
    protected function shouldQueue(string $path): bool
    {
        [, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        return filter_var($options['queue'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Dispatch a queued job that generates the derivative for the given path.
     * The source disk is carried along so the worker reads from the same origin.
     */
    protected function dispatchGeneration(string $path, string $manifest): void
    {
        Bus::dispatch(new GenerateImageJob($path, $manifest, $this->sourceDisk));
    }
}
