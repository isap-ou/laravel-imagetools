<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests;

use Illuminate\Support\Facades\Config;
use Isapp\ImageTools\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

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
            'manifest_path' => 'bootstrap/cache/image-tools.php',
            'blade_paths' => [],
            'php_paths' => [],
            'queue_connection' => null,
            'queue_name' => null,
            'unique_for' => 3600,
        ]);
    }
}
