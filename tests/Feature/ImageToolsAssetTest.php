<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\Tests\TestCase;

use function base_path;

class ImageToolsAssetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        File::ensureDirectoryExists(base_path('bootstrap/cache'));
    }

    public function test_asset_returns_url_when_manifest_has_key_without_generating(): void
    {
        $key = 'public/images/hero.jpg?w=10';
        $pathInDisk = 'image-tools/hero--testhash.webp';
        $manifest = [$key => ['path' => $pathInDisk, 'disk' => 'public']];

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        File::put($manifestFile, '<?php return ' . var_export($manifest, true) . ';');
        // Ensure the singleton has the latest manifest loaded.
        ImageToolsFacade::loadManifest();

        Storage::disk('public')->put($pathInDisk, 'x');
        // Snapshot files before calling asset() to ensure no new files are created.
        $before = Storage::disk('public')->allFiles('image-tools');
        sort($before);

        $url = ImageToolsFacade::asset($key);

        $this->assertIsString($url);
        $this->assertStringContainsString($pathInDisk, $url);

        $after = Storage::disk('public')->allFiles('image-tools');
        sort($after);
        $this->assertSame($before, $after, 'No new files should be generated when manifest has the key.');
    }

    public function test_asset_triggers_generate_when_missing_in_manifest(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/test.png'), $png);

        $requested = 'public/images/test.png?w=16';

        $url = ImageToolsFacade::asset($requested);

        $this->assertIsString($url);
        $this->assertFileExists($manifestFile);

        /** @var array $manifest */
        $manifest = require $manifestFile;
        $this->assertArrayHasKey($requested, $manifest);
        $storedPath = $manifest[$requested]['path'] ?? null;
        $this->assertNotEmpty($storedPath);
        Storage::disk('public')->assertExists($storedPath);
        $this->assertStringContainsString($storedPath, $url);
    }

    public function test_asset_ignores_query_params_outside_the_schema(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/schema.png'), $png);
        ImageToolsFacade::loadManifest();

        // An unknown key ('foo') is not part of the supported schema and must be
        // ignored: the read key in asset() and the write key in generate() must agree.
        $clean = ImageToolsFacade::asset('public/images/schema.png?w=16');
        $withUnknown = ImageToolsFacade::asset('public/images/schema.png?w=16&foo=bar');

        $this->assertNotEmpty($withUnknown);
        $this->assertSame($clean, $withUnknown, 'Unknown query params must not change the resolved asset URL.');
    }
}
