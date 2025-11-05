<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;
use function str_ends_with;

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
