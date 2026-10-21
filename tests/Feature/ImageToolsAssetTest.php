<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Support\SourceReader;
use Isapp\ImageTools\Tests\TestCase;
use Mockery;
use RuntimeException;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;

use function app;
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
        $png = $this->pngBytes();
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
        $png = $this->pngBytes();
        File::put(base_path('public/images/schema.png'), $png);
        ImageToolsFacade::loadManifest();

        // An unknown key ('foo') is not part of the supported schema and must be
        // ignored: the read key in asset() and the write key in generate() must agree.
        $clean = ImageToolsFacade::asset('public/images/schema.png?w=16');
        $withUnknown = ImageToolsFacade::asset('public/images/schema.png?w=16&foo=bar');

        $this->assertNotEmpty($withUnknown);
        $this->assertSame($clean, $withUnknown, 'Unknown query params must not change the resolved asset URL.');
    }

    public function test_asset_returns_null_for_a_width_above_the_source(): void
    {
        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }

        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/small.png'), $this->pngBytes(40, 20));

        $this->assertNull(ImageToolsFacade::asset('public/images/small.png?w=80'));
        $this->assertSame([], Storage::disk('public')->allFiles('image-tools'));
    }

    public function test_asset_returns_null_for_an_entry_without_a_file(): void
    {
        // The source does not exist: a call to generate() would return '' instead of null.
        $key = 'public/images/gone.png?w=80';
        $manifest = [$key => ['path' => null, 'disk' => null, 'source' => $key, 'source_disk' => null]];

        File::put(base_path('bootstrap/cache/image-tools.php'), '<?php return ' . var_export($manifest, true) . ';');
        ImageToolsFacade::loadManifest();

        $this->assertNull(ImageToolsFacade::asset($key));
    }

    public function test_asset_returns_empty_string_when_generation_fails(): void
    {
        // A failure stays distinct from a request larger than the source, which returns null.
        $this->assertSame('', ImageToolsFacade::asset('public/images/missing.png?w=10'));
    }

    public function test_asset_logs_a_query_that_fails_validation_and_returns_an_empty_string(): void
    {
        Log::spy();

        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/test.png'), $this->pngBytes());

        $this->assertSame('', ImageToolsFacade::asset('public/images/test.png?fit=crop&w=10'));

        // Laravel never reports a ValidationException, so the package logs it itself.
        Log::shouldHaveReceived('warning')
            ->with('ImageTools: the image could not be generated; asset() returns an empty string.', Mockery::on(
                fn (array $context) => $context['path'] === 'public/images/test.png?fit=crop&w=10'
                    && $context['disk'] === null
                    && $context['exception'] === ValidationException::class
                    && isset($context['errors']['h'])
            ))
            ->once();
    }

    public function test_asset_reports_an_encode_that_throws_and_returns_an_empty_string(): void
    {
        Exceptions::fake();

        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/test.png'), $this->pngBytes());

        $it = new class(app(Manifest::class), app(PathResolver::class), app(SourceReader::class)) extends ImageTools
        {
            protected function writeDerivative(Image $image, string $tmpPath, ?OptimizerChain $chain = null): void
            {
                throw new RuntimeException('The encoder failed.');
            }
        };

        Log::spy();

        $this->assertSame('', $it->asset('public/images/test.png?w=10'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The encoder failed.');

        // The report names no image; the warning does.
        Log::shouldHaveReceived('warning')
            ->with('ImageTools: the image could not be generated; asset() returns an empty string.', Mockery::on(
                fn (array $context) => $context['path'] === 'public/images/test.png?w=10'
                    && $context['exception'] === RuntimeException::class
                    && ! isset($context['errors'])
            ))
            ->once();
    }

    public function test_generate_still_throws_for_a_query_that_fails_validation(): void
    {
        $this->expectException(ValidationException::class);

        ImageToolsFacade::generate('public/images/test.png?fit=crop&w=10');
    }

    public function test_asset_returns_empty_string_for_unknown_manifest_namespace(): void
    {
        $this->assertSame('', ImageToolsFacade::asset('public/images/x.png?w=10', 'does-not-exist'));
    }
}
