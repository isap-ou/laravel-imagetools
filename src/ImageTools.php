<?php

declare(strict_types = 1);

namespace Isapp\ImageTools;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

use function array_key_exists;
use function array_pad;
use function base_path;
use function config;
use function explode;
use function http_build_query;
use function ksort;
use function parse_str;
use function pathinfo;
use function sha1;
use function sprintf;
use function storage_path;
use function substr;

/**
 * ImageTools: deterministic, query‑driven image generator (vite‑imagetools‑like).
 *
 * Usage:
 *  - ImageTools::asset('path/to/img.jpg?w=1200&h=630&fit=contain&format=webp&q=82')
 *    returns a URL from the configured filesystem disk and records the mapping in a PHP manifest.
 *
 * Key ideas:
 *  - The query string defines simple transforms (w, h, fit, q, format).
 *  - A canonical "seed" (sorted query) ensures deterministic filenames and manifest keys.
 */
class ImageTools
{
    protected array $manifests = ['default' => []];

    protected string $manifestPath;

    public function __construct()
    {
        // Resolve manifest path from config and preload the default manifest (if present).
        $this->manifestPath = base_path(config('image-tools.manifest_path'));
        $this->loadManifest();
    }

    /**
     * Load a manifest into memory.
     * - When $path is provided, load that file (if it exists) under its own key.
     * - Otherwise, load the default manifest configured by 'image-tools.manifest_path' into 'default'.
     */
    public function loadManifest(?string $path = null): void
    {
        // Load an explicit manifest file if a path is provided.
        if (! empty($path)) {
            if (File::exists($path)) {
                $this->manifests[$path] = require $path;
            }

            return;
        }

        // Default case: if the default manifest doesn't exist yet, nothing to load.
        if (! File::exists($this->manifestPath)) {
            return;
        }

        // Load the default manifest array.
        $this->manifests['default'] = require $this->manifestPath;
    }

    /**
     * Return a public URL for a given "path?query".
     * If the canonical key is missing in the manifest, generate the image first and then return its URL.
     *
     * @param string $path Source path with query (e.g., 'resources/img/hero.jpg?w=1200&format=webp')
     * @param string $manifest Manifest namespace ('default' by default)
     */
    public function asset(string $path, string $manifest = 'default'): string
    {
        if (! isset($this->manifests[$manifest])) {
            // TODO throw exception;
            return '';
        }

        // Snapshot current manifest data for readability.
        $manifestData = $this->manifests[$manifest];

        // Canonicalize the query string (sort keys) to get a deterministic key.
        $pathSeed = $this->getPathSeed($path);

        // If not yet generated, create the derivative and refresh manifest cache.
        if (! array_key_exists($pathSeed, $manifestData)) {
            $info = $this->generate($pathSeed, $manifest);

            if ($info === null) {
                return '';
            }

            // Refresh local cache after manifest was updated
            $manifestData = $this->manifests[$manifest];
        }

        $fileInformation = $manifestData[$pathSeed];

        // Resolve a URL from the configured disk using the manifest mapping.
        return Storage::disk($fileInformation['disk'])->url($fileInformation['path']);
    }

    /**
     * Generate a processed image for the given "path?query" and store it on the configured disk.
     * Supported options (validated):
     *  - w (int), h (int): target dimensions
     *  - fit (Spatie\Image\Enums\Fit): if present, both w and h are required
     *  - q (1..100): quality
     *  - format: one of jpeg, png, gif, webp, avif
     *
     * Returns an array with ['path' => string, 'disk' => string] or null on failure.
     */
    public function generate(string $path, string $manifest = 'default'): ?array
    {
        $disk = config('image-tools.disk');

        // Split into file path and query; if no '?', $params becomes an empty string.
        [$filepath, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        // Resolve absolute path to the source file within the project.
        $fullPath = base_path($filepath);

        // Bail out early if the source file is missing.
        if (! File::exists($fullPath)) {
            // TODO: throw exception
            return null;
        }

        // Validate supported query options. 'w' and 'h' are required together when 'fit' is used.
        $validatedOptions = Validator::validate($options, [
            'w' => ['required_with:fit', 'integer', 'min:1'],
            'h' => ['required_with:fit', 'integer', 'min:1'],
            'q' => ['max:100', 'min:1', 'integer'],
            'fit' => ['nullable', Rule::enum(Fit::class)],
            'format' => ['nullable', Rule::in(['jpeg', 'png', 'gif', 'webp', 'avif'])],
        ]);

        // Load the image using Spatie Image.
        $image = new Image($fullPath);

        // Extract filename/extension for defaulting and for output naming.
        $pathInfo = pathinfo($filepath);

        // Apply geometry:
        // - if 'fit' is provided: resize to exactly w x h using the chosen Fit mode
        // - else if only 'w' or only 'h' is present: resize proportionally by that side
        // Cast to int to satisfy the image driver type hints.
        if (! empty($validatedOptions['fit'])) {
            $fit = Fit::from($validatedOptions['fit']);
            $image->fit($fit, (int) $validatedOptions['w'], (int) $validatedOptions['h']);
        } elseif (! empty($validatedOptions['w'])) {
            $image->width((int) $validatedOptions['w']);
        } elseif (! empty($validatedOptions['h'])) {
            $image->height((int) $validatedOptions['h']);
        }
        // Apply output quality (1..100).
        if (! empty($validatedOptions['q'])) {
            $image->quality((int) $validatedOptions['q']);
        }

        // Decide output extension: overridden by 'format', else source extension.
        $extension = $pathInfo['extension'];
        if (! empty($validatedOptions['format'])) {
            $extension = $validatedOptions['format'];
        }

        // Build a deterministic name seed from the sorted options to keep filenames stable.
        ksort($validatedOptions);
        $nameSeed = $filepath . '?' . http_build_query($validatedOptions);

        $fileName = sprintf(
            '%s--%s.%s',
            str($pathInfo['filename'])->slug('-')->toString(),
            substr(sha1($nameSeed), 0, 10),
            $extension
        );

        $savePath = 'image-tools/' . $fileName;
        $tmpPath = storage_path($savePath);

        // Ensure temporary directory exists before saving.
        File::ensureDirectoryExists(\dirname($tmpPath));

        // Save to a temporary path (optimize() is a no-op if optimizers are not installed).
        $image->optimize()->save($tmpPath);

        $file = new \Illuminate\Http\File($tmpPath);

        // Move the temp file to the target filesystem disk under a stable name.
        $stored = Storage::disk($disk)->putFileAs('image-tools', $file, $fileName);
        File::delete($tmpPath);

        // If storing failed, abort without updating the manifest.
        if (! $stored) {
            return null;
        }

        // Record the canonical seed -> stored path mapping in the manifest.
        $this->updateManifest($nameSeed, $savePath, $disk, $manifest);

        return [
            'path' => $savePath,
            'disk' => $disk,
        ];
    }

    /**
     * Update the in-memory manifest and write it to disk.
     * The manifest stores: 'seed' => ['path' => 'image-tools/name.ext', 'disk' => 'public'].
     * Opcache is invalidated to ensure fresh reads after deployment.
     */
    protected function updateManifest(string $path, string $savePath, string $disk, string $manifest): void
    {
        $this->manifests[$manifest][$path] = [
            'path' => $savePath,
            'disk' => $disk,
        ];

        $files = app(Filesystem::class);

        $contents = "<?php\n\nreturn " . var_export($this->manifests[$manifest], true) . ";\n";

        $files->replace($this->manifestPath, $contents);

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->manifestPath, true);
        }
    }

    /**
     * Return a canonical "seed" for a path by sorting its query parameters.
     * This ensures that different parameter orders produce the same key.
     */
    protected function getPathSeed(string $path): string
    {
        // Split original input into "filepath" and "param string".
        [$filepath, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        // Sort query keys to normalize the seed.
        ksort($options);

        if (empty($options)) {
            return $filepath;
        }

        return $filepath . '?' . http_build_query($options);
    }
}
