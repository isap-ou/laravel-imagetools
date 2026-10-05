<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Isapp\ImageTools\ImageTools disk(string $disk)
 * @method static void loadManifest(?string $path = null)
 * @method static array{path: string|null, disk: string|null}|null generate(string $path, string $manifest = 'default')
 * @method static string|null asset(string $path, string $manifest = 'default')
 * @method static bool has(string $path, string $manifest = 'default')
 */
class ImageTools extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'image-tools';
    }
}
