<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;

class ClearGeneratedImagesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
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

    public function test_clear_is_a_noop_when_manifest_is_missing(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        $this->assertFileDoesNotExist($manifestFile);

        $this->artisan('imagetools:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($manifestFile);
    }
}
