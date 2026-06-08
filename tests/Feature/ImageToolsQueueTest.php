<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Jobs\GenerateImageJob;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;

class ImageToolsQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/queue.png'), $png);

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
        ImageToolsFacade::loadManifest();
    }

    public function test_queue_flag_dispatches_job_and_skips_synchronous_generation(): void
    {
        Bus::fake();

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        Bus::assertDispatched(GenerateImageJob::class);

        $this->assertNotEmpty($url);
        $this->assertSame(
            [],
            Storage::disk('public')->allFiles('image-tools'),
            'No file should be generated synchronously in queued mode.'
        );
    }

    public function test_queued_url_matches_eventually_generated_file(): void
    {
        Bus::fake();

        // asset() returns the deterministic URL up-front, before the worker runs.
        $queuedUrl = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        // What the worker will actually produce (note: no "queue" flag in the seed).
        $info = app(ImageTools::class)->generate('public/images/queue.png?w=16');

        $this->assertIsArray($info);
        $realUrl = Storage::disk($info['disk'])->url($info['path']);
        $this->assertSame(
            $realUrl,
            $queuedUrl,
            'The pre-computed queued URL must point at the file the worker produces.'
        );
    }

    public function test_no_queue_flag_keeps_synchronous_generation(): void
    {
        Bus::fake();

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16');

        Bus::assertNotDispatched(GenerateImageJob::class);
        $this->assertNotEmpty($url);
        $this->assertNotSame(
            [],
            Storage::disk('public')->allFiles('image-tools'),
            'A file should be generated synchronously without the queue flag.'
        );
    }

    public function test_job_handle_generates_the_derivative(): void
    {
        (new GenerateImageJob('public/images/queue.png?w=16'))->handle();

        $manifest = require base_path('bootstrap/cache/image-tools.php');
        $this->assertArrayHasKey('public/images/queue.png?w=16', $manifest);
        Storage::disk('public')->assertExists($manifest['public/images/queue.png?w=16']['path']);
    }
}
