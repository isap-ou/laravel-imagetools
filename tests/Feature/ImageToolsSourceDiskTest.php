<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Tests\TestCase;

use function base_path;
use function glob;
use function storage_path;

class ImageToolsSourceDiskTest extends TestCase
{
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // output disk (config image-tools.disk)
        Storage::fake('s3');     // source disk

        $this->png = $this->pngBytes();

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
        ImageToolsFacade::loadManifest();
    }

    public function test_disk_reads_the_source_from_the_filesystem(): void
    {
        // Source lives ONLY on the 's3' disk, not at base_path().
        Storage::disk('s3')->put('images/fixture.png', $this->png);
        $this->assertFileDoesNotExist(base_path('images/fixture.png'));

        $info = app(ImageTools::class)->disk('s3')->generate('images/fixture.png?w=16&format=webp');

        $this->assertIsArray($info);
        Storage::disk('public')->assertExists($info['path']);
        $this->assertNotEmpty(Storage::disk('public')->get($info['path']));
    }

    public function test_a_width_above_a_disk_source_stores_no_file(): void
    {
        Storage::disk('s3')->put('images/small.png', $this->pngBytes(40, 20));

        $this->assertNull(ImageToolsFacade::disk('s3')->asset('images/small.png?w=80'));

        // The entry is keyed with the source disk, like every other disk-sourced entry.
        $entries = require base_path('bootstrap/cache/image-tools.php');
        $entry = $entries['s3:images/small.png?w=80'];

        $this->assertNull($entry['path']);
        $this->assertSame('s3', $entry['source_disk']);
        $this->assertSame([], Storage::disk('public')->allFiles('image-tools'));

        // The early return still removes the temporary copy of the source.
        $this->assertSame([], glob(storage_path('image-tools/source-*')));
    }

    public function test_fluent_disk_via_facade_returns_a_url(): void
    {
        Storage::disk('s3')->put('images/fixture.png', $this->png);

        $url = ImageToolsFacade::disk('s3')->asset('images/fixture.png?w=16');

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

    public function test_manifest_entry_records_the_source_disk(): void
    {
        Storage::disk('s3')->put('images/fixture.png', $this->png);

        app(ImageTools::class)->disk('s3')->generate('images/fixture.png?w=16');

        $entries = require base_path('bootstrap/cache/image-tools.php');
        $seed = app(PathResolver::class)->seed('images/fixture.png?w=16', 's3');

        $this->assertSame('images/fixture.png?w=16', $entries[$seed]['source']);
        $this->assertSame('s3', $entries[$seed]['source_disk']);
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
