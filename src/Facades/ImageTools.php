<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Isapp\ImageTools\ImageTools disk(string $disk)
 * @method static void generate(string $path, string $manifest = 'default')
 * @method static string asset(string $path, string $manifest = 'default')
 */
class ImageTools extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'image-tools';
    }
}
