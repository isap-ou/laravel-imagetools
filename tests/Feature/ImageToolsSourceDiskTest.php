<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;

class ImageToolsSourceDiskTest extends TestCase
{
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // output disk (config image-tools.disk)
        Storage::fake('s3');     // source disk

        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
        ImageToolsFacade::loadManifest();
    }

    public function test_disk_reads_the_source_from_the_filesystem(): void
    {
        // Source lives ONLY on the 's3' disk, not at base_path().
        Storage::disk('s3')->put('images/onepx.png', $this->png);
        $this->assertFileDoesNotExist(base_path('images/onepx.png'));

        $info = app(ImageTools::class)->disk('s3')->generate('images/onepx.png?w=16&format=webp');

        $this->assertIsArray($info);
        Storage::disk('public')->assertExists($info['path']);
        $this->assertNotEmpty(Storage::disk('public')->get($info['path']));
    }

    public function test_fluent_disk_via_facade_returns_a_url(): void
    {
        Storage::disk('s3')->put('images/onepx.png', $this->png);

        $url = ImageToolsFacade::disk('s3')->asset('images/onepx.png?w=16');

        $this->assertNotEmpty($url);
    }

    public function test_same_path_from_a_disk_has_a_distinct_identity_from_local(): void
    {
        // Same relative path present both locally and on 's3'.
        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/dual.png'), $this->png);
        Storage::disk('s3')->put('public/images/dual.png', $this->png);

        $local = app(ImageTools::class)->generate('public/images/dual.png?w=16');
        $fromS3 = app(ImageTools::class)->disk('s3')->generate('public/images/dual.png?w=16');

        $this->assertIsArray($local);
        $this->assertIsArray($fromS3);
        $this->assertNotSame(
            $local['path'],
            $fromS3['path'],
            'A disk-sourced derivative must not collide with the local one.'
        );
    }

    public function test_missing_source_on_disk_returns_null(): void
    {
        $this->assertNull(app(ImageTools::class)->disk('s3')->generate('images/missing.png?w=16'));
    }

    public function test_disk_does_not_mutate_the_base_instance(): void
    {
        $it = app(ImageTools::class);
        $scoped = $it->disk('s3');

        $this->assertNotSame($it, $scoped);

        // The base instance still resolves sources locally.
        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/local.png'), $this->png);
        $this->assertIsArray($it->generate('public/images/local.png?w=8'));
    }
}
