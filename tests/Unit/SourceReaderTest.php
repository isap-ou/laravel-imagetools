<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Unit;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Support\SourceReader;
use Isapp\ImageTools\Tests\TestCase;
use Mockery;
use RuntimeException;

use function app;
use function asset;
use function base_path;

class SourceReaderTest extends TestCase
{
    public function test_a_disk_that_cannot_make_urls_gives_no_public_url(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('url')->andThrow(new RuntimeException('This driver does not support retrieving URLs.'));
        Storage::set('no-urls', $disk);

        $this->assertNull(app(SourceReader::class)->publicUrl('images/a.png', 'no-urls'));
    }

    public function test_a_path_that_leaves_the_public_directory_gives_no_public_url(): void
    {
        $this->assertNull(app(SourceReader::class)->publicUrl('public/../composer.json'));
    }

    public function test_a_parent_segment_on_a_disk_gives_no_public_url(): void
    {
        // A browser resolves each of these to the parent directory, so the URL would leave the disk.
        Storage::fake('public');

        foreach (['../x.png', '..\\x.png', '%2e%2e/x.png', 'a/%2E./x.png'] as $path) {
            $this->assertNull(app(SourceReader::class)->publicUrl($path, 'public'), $path);
        }
    }

    public function test_a_directory_or_a_missing_file_gives_no_public_url(): void
    {
        // An empty CMS field gives 'public/', which would otherwise link the home page.
        File::ensureDirectoryExists(base_path('public/images'));

        $this->assertNull(app(SourceReader::class)->publicUrl('public/'));
        $this->assertNull(app(SourceReader::class)->publicUrl('public/images'));
        $this->assertNull(app(SourceReader::class)->publicUrl('public/images/missing.png'));
    }

    public function test_a_local_url_is_encoded_and_has_no_empty_segment(): void
    {
        // A space would end a srcset candidate; "//" would start a URL with another host.
        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/a b.png'), 'png');

        try {
            $this->assertSame(asset('images/a%20b.png'), app(SourceReader::class)->publicUrl('public/images/a b.png'));
            $this->assertSame(asset('images/a%20b.png'), app(SourceReader::class)->publicUrl('public//images/a b.png'));
            // Without the filter this one gives '//images/…', a URL of the host "images".
            $this->assertSame(asset('images/a%20b.png'), app(SourceReader::class)->publicUrl('public///images/a b.png'));
        } finally {
            File::delete(base_path('public/images/a b.png'));
        }
    }
}
