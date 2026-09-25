<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use Isapp\ImageTools\Tests\TestCase;
use League\Flysystem\UnableToDeleteFile;
use Mockery;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Cwebp;

use function app;
use function base_path;
use function getimagesizefromstring;
use function sha1;
use function storage_path;
use function str_ends_with;
use function substr;
use function var_export;

class ImageToolsGenerateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));

        $png = $this->pngBytes();
        File::put(base_path('public/images/fixture.png'), $png);

        // A 40×20 source for the checks against the source size.
        File::put(base_path('public/images/small.png'), $this->pngBytes(40, 20));
    }

    public function test_deterministic_name_same_input_same_output(): void
    {
        $it = app(ImageTools::class);
        $a = $it->generate('public/images/fixture.png?w=20&h=20');
        $b = $it->generate('public/images/fixture.png?w=20&h=20');

        $this->assertIsArray($a);
        $this->assertSame($a['path'], $b['path']);
        Storage::disk('public')->assertExists($a['path']);
    }

    public function test_param_order_does_not_change_output(): void
    {
        $it = app(ImageTools::class);
        $a = $it->generate('public/images/fixture.png?w=20&h=20');
        $b = $it->generate('public/images/fixture.png?h=20&w=20');
        $this->assertSame($a['path'], $b['path']);
    }

    public function test_only_width_or_height_is_allowed_and_works(): void
    {
        $it = app(ImageTools::class);
        $wOnly = $it->generate('public/images/fixture.png?w=8');
        $this->assertSame([8, 8], $this->storedSize($wOnly['path']));
        $hOnly = $it->generate('public/images/fixture.png?h=8');
        $this->assertSame([8, 8], $this->storedSize($hOnly['path']));
    }

    public function test_fit_requires_both_dimensions(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $it = app(ImageTools::class);
        $it->generate('public/images/fixture.png?fit=contain&w=100'); // missing h
    }

    public function test_quality_is_castable(): void
    {
        $it = app(ImageTools::class);
        $res = $it->generate('public/images/fixture.png?w=8&q=75');
        $this->assertIsArray($res);
    }

    public function test_format_changes_extension(): void
    {
        $it = app(ImageTools::class);
        $res = $it->generate('public/images/fixture.png?format=webp');
        $this->assertIsArray($res);
        $this->assertTrue(str_ends_with($res['path'], '.webp'));
        Storage::disk('public')->assertExists($res['path']);
    }

    public function test_lossless_webp_generates_a_separate_file(): void
    {
        $it = app(ImageTools::class);

        $lossy = $it->generate('public/images/fixture.png?format=webp');
        $lossless = $it->generate('public/images/fixture.png?format=webp&lossless=1');

        $this->assertIsArray($lossy);
        $this->assertIsArray($lossless);
        $this->assertNotSame($lossy['path'], $lossless['path']);
        Storage::disk('public')->assertExists($lossless['path']);
    }

    public function test_every_spelling_of_the_lossless_switch_is_accepted(): void
    {
        $it = app(ImageTools::class);

        // 'true' must behave like '1' — the queue flag already reads it that way.
        $one = $it->generate('public/images/fixture.png?format=webp&lossless=1');
        $true = $it->generate('public/images/fixture.png?format=webp&lossless=true');

        $this->assertIsArray($true);
        $this->assertSame($one['path'], $true['path']);

        // A switch that is off must not earn a file of its own.
        $off = $it->generate('public/images/fixture.png?format=webp&lossless=0');
        $plain = $it->generate('public/images/fixture.png?format=webp');

        $this->assertSame($plain['path'], $off['path']);
    }

    public function test_an_unreadable_lossless_value_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(ImageTools::class)->generate('public/images/fixture.png?format=webp&lossless=maybe');
    }

    public function test_the_lossless_chain_carries_the_cwebp_switch(): void
    {
        $it = new class(app(Manifest::class), app(PathResolver::class), app(SourceReader::class)) extends ImageTools
        {
            public function chain(): OptimizerChain
            {
                return $this->losslessOptimizerChain();
            }
        };

        $optimizers = $it->chain()->getOptimizers();

        $this->assertCount(1, $optimizers);
        $this->assertInstanceOf(Cwebp::class, $optimizers[0]);
        $this->assertContains('-lossless', $optimizers[0]->options);
    }

    public function test_lossless_is_applied_to_the_image_under_imagick(): void
    {
        if (! \extension_loaded('imagick')) {
            $this->markTestSkipped('The lossless switch is an Imagick option; GD has none.');
        }

        $it = new class(app(Manifest::class), app(PathResolver::class), app(SourceReader::class)) extends ImageTools
        {
            public ?OptimizerChain $seenChain = null;

            public ?string $seenOption = null;

            protected function writeDerivative(Image $image, string $tmpPath, ?OptimizerChain $chain = null): void
            {
                $this->seenChain = $chain;
                $this->seenOption = $image->image()->getOption('webp:lossless');

                parent::writeDerivative($image, $tmpPath, $chain);
            }
        };

        $it->generate('public/images/fixture.png?format=webp&lossless=1');

        $this->assertNotNull($it->seenChain);
        $this->assertSame('true', $it->seenOption);
    }

    public function test_the_stored_file_really_is_a_lossless_webp_under_imagick(): void
    {
        if (! \extension_loaded('imagick')) {
            $this->markTestSkipped('The lossless switch is an Imagick option; GD has none.');
        }

        $it = app(ImageTools::class);

        $lossless = $it->generate('public/images/fixture.png?format=webp&lossless=1');
        $lossy = $it->generate('public/images/fixture.png?format=webp');

        $losslessBytes = Storage::disk('public')->get($lossless['path']);
        $lossyBytes = Storage::disk('public')->get($lossy['path']);

        // A WebP file names its coding in the chunk after the RIFF header:
        // 'VP8L' is the lossless bitstream, 'VP8 ' the lossy one. Read the
        // header only — the byte sequence could occur in compressed data.
        $this->assertSame('RIFF', substr($losslessBytes, 0, 4));
        $this->assertSame('WEBP', substr($losslessBytes, 8, 4));
        $this->assertStringContainsString('VP8L', substr($losslessBytes, 0, 64));

        // The switch must actually change what is written, not only what is set.
        $this->assertStringNotContainsString('VP8L', substr($lossyBytes, 0, 64));
    }

    public function test_manifest_entry_records_the_source_it_was_built_from(): void
    {
        $it = app(ImageTools::class);
        $info = $it->generate('public/images/fixture.png?w=8');

        $entries = require base_path('bootstrap/cache/image-tools.php');
        $seed = app(PathResolver::class)->seed('public/images/fixture.png?w=8');

        $this->assertSame($info['path'], $entries[$seed]['path']);
        $this->assertSame('public/images/fixture.png?w=8', $entries[$seed]['source']);
        $this->assertNull($entries[$seed]['source_disk']);
    }

    public function test_a_width_above_the_source_stores_no_file(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?w=80');

        $this->assertSame(['path' => null, 'disk' => null], $res);
        $this->assertSame([], Storage::disk('public')->files('image-tools'));

        // The entry is still written, so the next asset() call does not load the source again.
        $entries = require base_path('bootstrap/cache/image-tools.php');
        $entry = $entries['public/images/small.png?w=80'];

        $this->assertNull($entry['path']);
        $this->assertNull($entry['disk']);
        $this->assertSame('public/images/small.png?w=80', $entry['source']);
        $this->assertNull($entry['source_disk']);
    }

    public function test_a_height_above_the_source_stores_no_file(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?h=30');

        $this->assertSame(['path' => null, 'disk' => null], $res);
        $this->assertSame([], Storage::disk('public')->files('image-tools'));

        $entries = require base_path('bootstrap/cache/image-tools.php');
        $this->assertNull($entries['public/images/small.png?h=30']['path']);
        $this->assertNull(app(ImageTools::class)->asset('public/images/small.png?h=30'));
    }

    public function test_a_width_below_the_source_resizes_and_keeps_the_aspect_ratio(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?w=20');

        $this->assertSame([20, 10], $this->storedSize($res['path']));
    }

    public function test_a_height_below_the_source_resizes_and_keeps_the_aspect_ratio(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?h=10');

        $this->assertSame([20, 10], $this->storedSize($res['path']));
    }

    public function test_a_width_equal_to_the_source_is_stored_at_the_source_size(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?w=40');

        $this->assertSame([40, 20], $this->storedSize($res['path']));
    }

    public function test_only_the_width_counts_when_w_and_h_come_without_fit(): void
    {
        // Without fit the geometry resizes by w alone, so h=30 does not make the request oversize.
        $res = app(ImageTools::class)->generate('public/images/small.png?w=20&h=30');

        $this->assertSame([20, 10], $this->storedSize($res['path']));
    }

    public function test_fit_still_produces_the_exact_box_above_the_source(): void
    {
        $res = app(ImageTools::class)->generate('public/images/small.png?fit=crop&w=80&h=60');

        $this->assertSame([80, 60], $this->storedSize($res['path']));
    }

    public function test_allow_upscale_enlarges_the_source_as_before(): void
    {
        config()->set('image-tools.allow_upscale', true);

        $res = app(ImageTools::class)->generate('public/images/small.png?w=80');

        $this->assertSame([80, 40], $this->storedSize($res['path']));
    }

    public function test_an_oversize_request_deletes_the_file_its_entry_held(): void
    {
        // The entry and the file an earlier, enlarging version left behind.
        config()->set('image-tools.allow_upscale', true);
        $old = app(ImageTools::class)->generate('public/images/small.png?w=80');
        Storage::disk('public')->assertExists($old['path']);

        config()->set('image-tools.allow_upscale', false);
        $res = app(ImageTools::class)->generate('public/images/small.png?w=80');

        $this->assertNull($res['path']);
        Storage::disk('public')->assertMissing($old['path']);

        $entries = require base_path('bootstrap/cache/image-tools.php');
        $this->assertNull($entries['public/images/small.png?w=80']['path']);
    }

    public function test_an_oversize_request_deletes_the_file_at_its_stored_name_when_no_entry_holds_it(): void
    {
        // A per-release manifest starts empty, but the enlarged file of the last release is still on the disk.
        File::delete(base_path('bootstrap/cache/image-tools.php'));

        $stored = app(PathResolver::class)->storedFile('public/images/small.png?w=80');
        Storage::disk('public')->put($stored['path'], 'enlarged');

        app(ImageTools::class)->generate('public/images/small.png?w=80');

        Storage::disk('public')->assertMissing($stored['path']);
    }

    public function test_an_oversize_request_deletes_the_file_its_entry_records_under_another_name(): void
    {
        // An entry whose file is not at the stored name, for example one written under an older naming.
        Storage::disk('public')->put('image-tools/legacy-small.png', 'enlarged');

        $seed = app(PathResolver::class)->seed('public/images/small.png?w=80');
        $entries = [$seed => [
            'path' => 'image-tools/legacy-small.png',
            'disk' => 'public',
            'source' => 'public/images/small.png?w=80',
            'source_disk' => null,
        ]];
        File::put(base_path('bootstrap/cache/image-tools.php'), "<?php\n\nreturn " . var_export($entries, true) . ";\n");

        app(ImageTools::class)->generate('public/images/small.png?w=80');

        Storage::disk('public')->assertMissing('image-tools/legacy-small.png');
    }

    public function test_a_delete_that_fails_does_not_fail_an_oversize_request(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        File::delete($manifestFile);

        // A disk whose credentials may write but not delete, with its 'throw' option on.
        // The delete records the entry it sees; an assertion inside it would be caught
        // together with the exception, so the check runs after generate().
        $entryAtDelete = null;
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->andReturnUsing(function () use ($manifestFile, &$entryAtDelete) {
            $entryAtDelete = (require $manifestFile)['public/images/small.png?w=80'] ?? 'no entry';

            throw UnableToDeleteFile::atLocation('image-tools/small.png', 'Access denied');
        });
        Storage::set('public', $disk);

        Log::spy();

        $res = app(ImageTools::class)->generate('public/images/small.png?w=80');

        $this->assertSame(['path' => null, 'disk' => null], $res);

        // The entry was written before the delete ran.
        $this->assertIsArray($entryAtDelete);
        $this->assertNull($entryAtDelete['path']);

        Log::shouldHaveReceived('warning')
            ->with('ImageTools: an old file could not be deleted.', Mockery::type('array'))
            ->once();
    }

    public function test_empty_encode_is_not_uploaded_and_does_not_touch_manifest(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }

        // Stands in for a killed optimizer: the encode leaves a zero-byte file behind.
        $it = new class(app(Manifest::class), app(PathResolver::class), app(SourceReader::class)) extends ImageTools
        {
            protected function writeDerivative(Image $image, string $tmpPath, ?OptimizerChain $chain = null): void
            {
                File::put($tmpPath, '');
            }
        };

        Log::spy();

        $res = $it->generate('public/images/fixture.png?w=8');

        $this->assertNull($res);
        $this->assertFileDoesNotExist($manifestFile);
        $this->assertSame([], Storage::disk('public')->files('image-tools'));
        $this->assertFileDoesNotExist(storage_path('image-tools/fixture--' . substr(sha1('public/images/fixture.png?w=8'), 0, 10) . '.png'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_nonexistent_source_returns_null_and_does_not_touch_manifest(): void
    {
        $it = app(ImageTools::class);
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (file_exists($manifestFile)) {
            unlink($manifestFile);
        }
        $res = $it->generate('public/images/missing.png?w=10');
        $this->assertNull($res);
        $this->assertFileDoesNotExist($manifestFile);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function storedSize(string $path): array
    {
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        return [$width, $height];
    }
}
