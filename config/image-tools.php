<?php

declare(strict_types=1);

/**
 * ImageTools configuration.
 *
 * This file controls where generated images are stored, where the manifest lives,
 * and which directories the generator scans to find usages like:
 *   ImageTools::asset('resources/images/hero.jpg?w=1200&h=630&format=webp');
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Filesystem Disk for Generated Images
    |--------------------------------------------------------------------------
    |
    | The disk where ImageTools will write the processed files and from which
    | they will be served. Must be a disk configured in config/filesystems.php.
    | By default it resolves from IMAGE_TOOLS_DISK, then FILESYSTEM_DISK, else 'public'.
    |
    | Examples: 'public', 's3' etc
    */
    'disk' => env('IMAGE_TOOLS_DISK', env('FILESYSTEM_DISK', 'public')),

    /*
    |--------------------------------------------------------------------------
    | Allow Upscale
    |--------------------------------------------------------------------------
    |
    | Whether a resize by "w" or "h" may enlarge a source that is smaller than
    | the request. When false (the default), such a request stores no file:
    | the manifest records it with a null "path" and asset() returns null, so
    | the template can leave the image out. Requests with "fit" are not
    | affected. The value is not part of the file name: after a change, run
    | "imagetools:regenerate --all" to apply it to the existing entries.
    */
    'allow_upscale' => (bool) env('IMAGE_TOOLS_ALLOW_UPSCALE', false),

    /*
    |--------------------------------------------------------------------------
    | Manifest Path
    |--------------------------------------------------------------------------
    |
    | Path to the PHP manifest file (returning an array) that maps requested
    | "path?query" → generated file information. Used by ImageTools::asset().
    | Relative paths are resolved from the project base path.
    */
    'manifest_path' => env('IMAGE_TOOLS_MANIFEST_PATH', 'bootstrap/cache/image-tools.php'),

    /*
    |--------------------------------------------------------------------------
    | Blade Directories to Scan
    |--------------------------------------------------------------------------
    |
    | Directories with Blade templates that the "imagetools:generate" command
    | will scan to discover ImageTools::asset(...) calls. Provide a comma‑
    | separated list via IMAGE_TOOLS_BLADE_PATHS.
    |
    | Default: "resources/views"
    */
    'blade_paths' => [
        ...array_filter(
            array_map('trim', explode(',', (string) env('IMAGE_TOOLS_BLADE_PATHS', 'resources/views')))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP Directories to Scan (Optional)
    |--------------------------------------------------------------------------
    |
    | Additional directories with PHP files (controllers/services/jobs/etc.)
    | that should be scanned for ImageTools::asset(...) calls. Provide a
    | comma‑separated list via IMAGE_TOOLS_PHP_PATHS. Leave empty to skip.
    */
    'php_paths' => [
        ...array_filter(
            array_map('trim', explode(',', (string) env('IMAGE_TOOLS_PHP_PATHS', '')))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued Generation
    |--------------------------------------------------------------------------
    |
    | When an ImageTools::asset() call includes a truthy "queue" query flag
    | (e.g. 'hero.jpg?w=1200&queue=1') and the image has not been generated
    | yet, the derivative is produced in a queued job instead of synchronously.
    | asset() returns the final (deterministic) URL immediately; the file
    | appears once the worker finishes. Handy for pages with many images.
    | A request larger than the source (see allow_upscale) gets no file: that
    | URL stays a 404, and calls after the worker has run return null.
    |
    | - queue_connection: connection to dispatch the job on. Falls back to the
    |                     app's default queue connection (QUEUE_CONNECTION).
    | - queue_name:       queue to dispatch the job on (defaults to 'default').
    | - unique_for:       seconds the job stays "unique" to avoid duplicate
    |                     dispatches for the same image across requests.
    |                     Requires a cache store with atomic locks
    |                     (file, redis, database, memcached, …).
    */
    'queue_connection' => env('IMAGE_TOOLS_QUEUE_CONNECTION', env('QUEUE_CONNECTION')),

    'queue_name' => env('IMAGE_TOOLS_QUEUE_NAME', 'default'),

    'unique_for' => (int) env('IMAGE_TOOLS_QUEUE_UNIQUE_FOR', 3600),
];
