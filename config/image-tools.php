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
];
