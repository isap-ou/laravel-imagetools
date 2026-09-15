<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use Isapp\ImageTools\Tests\TestCase;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Cwebp;

use function app;
use function base64_decode;
use function base_path;
use function sha1;
use function storage_path;
use function str_ends_with;
use function substr;

class ImageToolsGenerateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/onepx.png'), $png);
    }

    public function test_deterministic_name_same_input_same_output(): void
    {
        $it = app(ImageTools::class);
        $a = $it->generate('public/images/onepx.png?w=20&h=20');
        $b = $it->generate('public/images/onepx.png?w=20&h=20');

        $this->assertIsArray($a);
        $this->assertSame($a['path'], $b['path']);
        Storage::disk('public')->assertExists($a['path']);
    }

    public function test_param_order_does_not_change_output(): void
    {
        $it = app(ImageTools::class);
        $a = $it->generate('public/images/onepx.png?w=20&h=20');
        $b = $it->generate('public/images/onepx.png?h=20&w=20');
        $this->assertSame($a['path'], $b['path']);
    }

    public function test_only_width_or_height_is_allowed_and_works(): void
    {
        $it = app(ImageTools::class);
        $wOnly = $it->generate('public/images/onepx.png?w=8');
        $this->assertIsArray($wOnly);
        $hOnly = $it->generate('public/images/onepx.png?h=8');
        $this->assertIsArray($hOnly);
    }

    public function test_fit_requires_both_dimensions(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $it = app(ImageTools::class);
        $it->generate('public/images/onepx.png?fit=contain&w=100'); // missing h
    }

    public function test_quality_is_castable(): void
    {
        $it = app(ImageTools::class);
        $res = $it->generate('public/images/onepx.png?w=8&q=75');
        $this->assertIsArray($res);
    }

    public function test_format_changes_extension(): void
    {
        $it = app(ImageTools::class);
        $res = $it->generate('public/images/onepx.png?format=webp');
        $this->assertIsArray($res);
        $this->assertTrue(str_ends_with($res['path'], '.webp'));
        Storage::disk('public')->assertExists($res['path']);
    }

    public function test_lossless_webp_generates_a_separate_file(): void
    {
        $it = app(ImageTools::class);

        $lossy = $it->generate('public/images/onepx.png?format=webp');
        $lossless = $it->generate('public/images/onepx.png?format=webp&lossless=1');

        $this->assertIsArray($lossy);
        $this->assertIsArray($lossless);
        $this->assertNotSame($lossy['path'], $lossless['path']);
        Storage::disk('public')->assertExists($lossless['path']);
    }

    public function test_every_spelling_of_the_lossless_switch_is_accepted(): void
    {
        $it = app(ImageTools::class);

        // 'true' must behave like '1' — the queue flag already reads it that way.
        $one = $it->generate('public/images/onepx.png?format=webp&lossless=1');
        $true = $it->generate('public/images/onepx.png?format=webp&lossless=true');

        $this->assertIsArray($true);
        $this->assertSame($one['path'], $true['path']);

        // A switch that is off must not earn a file of its own.
        $off = $it->generate('public/images/onepx.png?format=webp&lossless=0');
        $plain = $it->generate('public/images/onepx.png?format=webp');

        $this->assertSame($plain['path'], $off['path']);
    }

    public function test_an_unreadable_lossless_value_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(ImageTools::class)->generate('public/images/onepx.png?format=webp&lossless=maybe');
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

        $it->generate('public/images/onepx.png?format=webp&lossless=1');

        $this->assertNotNull($it->seenChain);
        $this->assertSame('true', $it->seenOption);
    }

    public function test_the_stored_file_really_is_a_lossless_webp_under_imagick(): void
    {
        if (! \extension_loaded('imagick')) {
            $this->markTestSkipped('The lossless switch is an Imagick option; GD has none.');
        }

        $it = app(ImageTools::class);

        $lossless = $it->generate('public/images/onepx.png?format=webp&lossless=1');
        $lossy = $it->generate('public/images/onepx.png?format=webp');

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
        $info = $it->generate('public/images/onepx.png?w=8');

        $entries = require base_path('bootstrap/cache/image-tools.php');
        $seed = app(PathResolver::class)->seed('public/images/onepx.png?w=8');

        $this->assertSame($info['path'], $entries[$seed]['path']);
        $this->assertSame('public/images/onepx.png?w=8', $entries[$seed]['source']);
        $this->assertNull($entries[$seed]['source_disk']);
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

        $res = $it->generate('public/images/onepx.png?w=8');

        $this->assertNull($res);
        $this->assertFileDoesNotExist($manifestFile);
        $this->assertSame([], Storage::disk('public')->files('image-tools'));
        $this->assertFileDoesNotExist(storage_path('image-tools/onepx--' . substr(sha1('public/images/onepx.png?w=8'), 0, 10) . '.png'));
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
}
