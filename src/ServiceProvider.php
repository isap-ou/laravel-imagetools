<?php

declare(strict_types=1);

namespace Isapp\ImageTools;

use Illuminate\Foundation\Application;
use Isapp\ImageTools\Commands\ClearGeneratedImagesCommand;
use Isapp\ImageTools\Commands\GenerateImagesCommand;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('image-tools', fn (Application $app) => new ImageTools);

        $this->mergeConfigFrom(
            __DIR__ . '/../config/image-tools.php',
            'image-tools'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateImagesCommand::class, ClearGeneratedImagesCommand::class]);
        }

        $this->publishes([
            __DIR__ . '/../config/image-tools.php' => config_path('image-tools.php'),
        ], 'image-tools-config');
    }
}
