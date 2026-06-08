<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

use function base64_decode;
use function base_path;
use function env;

/**
 * Exercises the real flysystem-s3 adapter against an S3-compatible endpoint
 * (MinIO in CI). Skipped unless AWS_ENDPOINT + AWS_BUCKET are set and the
 * bucket is reachable, so the normal suite stays green without infrastructure.
 *
 * Run locally:
 *   docker run -d -p 9000:9000 -e MINIO_ROOT_USER=minio \
 *     -e MINIO_ROOT_PASSWORD=minio12345 minio/minio server /data
 *   aws --endpoint-url http://127.0.0.1:9000 s3 mb s3://test
 *   AWS_ENDPOINT=http://127.0.0.1:9000 AWS_BUCKET=test AWS_ACCESS_KEY_ID=minio \
 *     AWS_SECRET_ACCESS_KEY=minio12345 AWS_USE_PATH_STYLE_ENDPOINT=true \
 *     vendor/bin/phpunit --group s3
 */
#[Group('s3')]
class ImageToolsS3IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! env('AWS_ENDPOINT') || ! env('AWS_BUCKET')) {
            $this->markTestSkipped('S3/MinIO not configured (set AWS_ENDPOINT, AWS_BUCKET, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY).');
        }

        Config::set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => true,
        ]);
        Config::set('image-tools.disk', 's3');

        // Skip (rather than fail) when the endpoint is configured but unreachable.
        try {
            Storage::disk('s3')->put('image-tools/.probe', 'ok');
            Storage::disk('s3')->delete('image-tools/.probe');
        } catch (\Throwable $e) {
            $this->markTestSkipped('S3 endpoint not reachable: ' . $e->getMessage());
        }

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/s3.png'), $png);

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
        ImageToolsFacade::loadManifest();
    }

    protected function tearDown(): void
    {
        if (env('AWS_ENDPOINT')) {
            try {
                Storage::disk('s3')->deleteDirectory('image-tools');
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        parent::tearDown();
    }

    public function test_generate_uploads_the_derivative_to_s3(): void
    {
        $info = app(ImageTools::class)->generate('public/images/s3.png?w=16&format=webp');

        $this->assertIsArray($info);
        $this->assertSame('s3', $info['disk']);
        Storage::disk('s3')->assertExists($info['path']);
        $this->assertNotEmpty(Storage::disk('s3')->get($info['path']), 'Uploaded object should have content.');
    }

    public function test_asset_returns_s3_url_and_clear_removes_the_object(): void
    {
        $key = 'public/images/s3.png?w=24';
        $url = ImageToolsFacade::asset($key);

        $manifest = require base_path('bootstrap/cache/image-tools.php');
        $this->assertArrayHasKey($key, $manifest);

        $path = $manifest[$key]['path'];
        Storage::disk('s3')->assertExists($path);
        $this->assertStringContainsString($path, $url);

        $this->artisan('imagetools:clear')->assertSuccessful();
        Storage::disk('s3')->assertMissing($path);
    }
}
