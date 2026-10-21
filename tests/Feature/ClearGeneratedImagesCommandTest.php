<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Tests\TestCase;

use function app;
use function base_path;

class ClearGeneratedImagesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));
        $png = $this->pngBytes();
        File::put(base_path('public/images/clear.png'), $png);

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
    }

    public function test_clear_deletes_generated_files_and_manifest(): void
    {
        $info = app(ImageTools::class)->generate('public/images/clear.png?w=8');

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        $this->assertFileExists($manifestFile);
        Storage::disk('public')->assertExists($info['path']);

        $this->artisan('imagetools:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($manifestFile);
        Storage::disk('public')->assertMissing($info['path']);
    }

    public function test_clear_skips_an_entry_without_a_file(): void
    {
        File::put(base_path('public/images/clear-small.png'), $this->pngBytes(40, 20));

        // The entry with no file comes first, so a failure on it would stop the loop early.
        $oversize = app(ImageTools::class)->generate('public/images/clear-small.png?w=80');
        $info = app(ImageTools::class)->generate('public/images/clear.png?w=8');
        $this->assertNull($oversize['path']);

        $this->artisan('imagetools:clear')->assertSuccessful();

        $this->assertFileDoesNotExist(base_path('bootstrap/cache/image-tools.php'));
        Storage::disk('public')->assertMissing($info['path']);
    }

    public function test_clear_keeps_a_file_that_a_request_adopted_after_the_manifest_was_deleted(): void
    {
        File::put(base_path('public/images/clear-b.png'), $this->pngBytes());
        $a = app(ImageTools::class)->generate('public/images/clear.png?w=8');
        $b = app(ImageTools::class)->generate('public/images/clear-b.png?w=8');

        // Right after the manifest is deleted, a request misses on B and adopts its
        // file, which the command has not deleted yet.
        $this->app->bind(Manifest::class, fn () => new class(base_path('bootstrap/cache/image-tools.php')) extends Manifest
        {
            public function clear(): array
            {
                $entries = parent::clear();

                app(ImageTools::class)->generate('public/images/clear-b.png?w=8');

                return $entries;
            }
        });

        $this->artisan('imagetools:clear')->assertSuccessful();

        Storage::disk('public')->assertMissing($a['path']);
        Storage::disk('public')->assertExists($b['path']);
        $this->assertSame($b['path'], (require base_path('bootstrap/cache/image-tools.php'))['public/images/clear-b.png?w=8']['path']);
    }

    public function test_clear_is_a_noop_when_manifest_is_missing(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        $this->assertFileDoesNotExist($manifestFile);

        $this->artisan('imagetools:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($manifestFile);
    }
}
