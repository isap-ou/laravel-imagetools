<?php

declare(strict_types=1);

namespace Isapp\ImageTools;

use Illuminate\Foundation\Application;
use Isapp\ImageTools\Commands\ClearGeneratedImagesCommand;
use Isapp\ImageTools\Commands\GenerateImagesCommand;
use Isapp\ImageTools\Support\Manifest;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        // The manifest needs its file path from config; the other collaborators
        // (PathResolver, SourceReader) are auto-resolved by the container.
        $this->app->bind(
            Manifest::class,
            fn (Application $app) => new Manifest(base_path($app['config']->get('image-tools.manifest_path')))
        );

        // Resolve ImageTools through the container so its dependencies are injected.
        $this->app->singleton('image-tools', fn (Application $app) => $app->make(ImageTools::class));

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
