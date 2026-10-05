<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests;

use Illuminate\Support\Facades\Config;
use Isapp\ImageTools\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

use function imagecreatetruecolor;
use function imagepng;
use function ob_get_clean;
use function ob_start;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        Config::set('image-tools', [
            'disk' => 'public',
            'allow_upscale' => false,
            'manifest_path' => 'bootstrap/cache/image-tools.php',
            'blade_paths' => [],
            'php_paths' => [],
            'queue' => false,
            'queue_fallback' => 'original',
            'queue_connection' => null,
            'queue_name' => null,
            'unique_for' => 3600,
        ]);
    }

    /**
     * The bytes of a PNG of the given size. Tests resize a fixture down, so the
     * default is larger than every w or h a test asks for.
     */
    protected function pngBytes(int $width = 128, int $height = 128): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
