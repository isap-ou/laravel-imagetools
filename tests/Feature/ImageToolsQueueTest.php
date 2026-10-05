<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Isapp\ImageTools\Facades\ImageTools as ImageToolsFacade;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Jobs\GenerateImageJob;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Tests\TestCase;
use Mockery;
use RuntimeException;

use function asset;
use function base_path;

class ImageToolsQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));
        $png = $this->pngBytes();
        File::put(base_path('public/images/queue.png'), $png);

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
        ImageToolsFacade::loadManifest();

        // Testbench's default connection is sync, and a sync connection generates inline.
        // These tests are about the dispatch, so they use a connection that queues.
        config()->set('image-tools.queue_connection', 'database');
    }

    public function test_the_queue_config_queues_a_miss_without_a_flag(): void
    {
        config()->set('image-tools.queue', true);
        Bus::fake();

        ImageToolsFacade::asset('public/images/queue.png?w=16');

        Bus::assertDispatched(GenerateImageJob::class);
        $this->assertSame([], Storage::disk('public')->allFiles('image-tools'));
    }

    public function test_a_queue_flag_of_zero_generates_synchronously_when_the_config_queues(): void
    {
        config()->set('image-tools.queue', true);
        Bus::fake();

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=0');

        Bus::assertNotDispatched(GenerateImageJob::class);
        $this->assertNotEmpty($url);
        $this->assertNotSame([], Storage::disk('public')->allFiles('image-tools'));
    }

    public function test_the_queue_flag_does_not_change_the_seed_or_the_stored_file(): void
    {
        // The config and the flag both select queued mode; neither may change the key or the name.
        config()->set('image-tools.queue', true);
        $paths = app(PathResolver::class);

        foreach (['public/images/queue.png?w=16&queue=1', 'public/images/queue.png?queue=0&w=16'] as $path) {
            $this->assertSame($paths->seed('public/images/queue.png?w=16'), $paths->seed($path), $path);
            $this->assertSame($paths->storedFile('public/images/queue.png?w=16'), $paths->storedFile($path), $path);
        }
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
        config()->set('image-tools.queue_fallback', 'derivative');
        Bus::fake();

        // In 'derivative' mode asset() returns the deterministic URL up-front, before the worker runs.
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

    public function test_a_queued_miss_returns_the_public_url_of_a_local_original(): void
    {
        Bus::fake();

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        $this->assertSame(asset('images/queue.png'), $url);
    }

    public function test_a_queued_miss_returns_the_disk_url_of_a_disk_original(): void
    {
        // Its own URL, so a URL built from the output disk ('public') cannot pass.
        Storage::fake('s3', ['url' => 'https://cdn.test']);
        Storage::disk('s3')->put('images/queue.png', $this->pngBytes());
        Bus::fake();

        $url = ImageToolsFacade::disk('s3')->asset('images/queue.png?w=16&queue=1');

        $this->assertSame(Storage::disk('s3')->url('images/queue.png'), $url);
    }

    public function test_a_queued_miss_returns_an_empty_string_for_an_original_without_a_url(): void
    {
        // Files outside the public directory have no URL.
        File::ensureDirectoryExists(base_path('resources/images'));
        File::put(base_path('resources/images/queue.png'), $this->pngBytes());
        Bus::fake();

        $this->assertSame('', ImageToolsFacade::asset('resources/images/queue.png?w=16&queue=1'));
        Bus::assertDispatched(GenerateImageJob::class);
    }

    public function test_the_none_fallback_returns_an_empty_string(): void
    {
        config()->set('image-tools.queue_fallback', 'none');
        Bus::fake();

        $this->assertSame('', ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1'));
        Bus::assertDispatched(GenerateImageJob::class);
    }

    public function test_the_derivative_fallback_returns_the_url_of_the_stored_file(): void
    {
        config()->set('image-tools.queue_fallback', 'derivative');
        Bus::fake();

        $stored = app(PathResolver::class)->storedFile('public/images/queue.png?w=16');

        $this->assertSame(
            Storage::disk($stored['disk'])->url($stored['path']),
            ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1')
        );
    }

    public function test_a_seed_that_is_already_pending_queues_no_second_job(): void
    {
        Bus::fake();

        // The same seed three times: repeated, and with the query in another order.
        // Each render, including the ones that queue nothing, gets the fallback.
        foreach (['?w=16&queue=1', '?w=16&queue=1', '?queue=1&w=16'] as $query) {
            $this->assertSame(asset('images/queue.png'), ImageToolsFacade::asset('public/images/queue.png' . $query));
        }

        Bus::assertDispatchedTimes(GenerateImageJob::class, 1);
    }

    public function test_the_default_sync_connection_returns_the_generated_result(): void
    {
        // No connection of its own: the job uses queue.default, which is sync here.
        config()->set('image-tools.queue_connection', null);
        config()->set('queue.default', 'sync');

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        $stored = app(PathResolver::class)->storedFile('public/images/queue.png?w=16');
        $this->assertSame(Storage::disk($stored['disk'])->url($stored['path']), $url);
        Storage::disk('public')->assertExists($stored['path']);
    }

    public function test_a_sync_connection_returns_the_generated_result(): void
    {
        config()->set('image-tools.queue_connection', 'sync');
        File::put(base_path('public/images/queue-small.png'), $this->pngBytes(40, 20));

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        $stored = app(PathResolver::class)->storedFile('public/images/queue.png?w=16');
        $this->assertSame(Storage::disk($stored['disk'])->url($stored['path']), $url);
        Storage::disk('public')->assertExists($stored['path']);

        // A request larger than the source gets its real result too.
        $this->assertNull(ImageToolsFacade::asset('public/images/queue-small.png?w=80&queue=1'));
    }

    public function test_a_failed_dispatch_generates_in_the_request_and_logs_a_warning(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.'));
        Log::spy();

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        $stored = app(PathResolver::class)->storedFile('public/images/queue.png?w=16');
        $this->assertSame(Storage::disk($stored['disk'])->url($stored['path']), $url);
        Storage::disk('public')->assertExists($stored['path']);

        Log::shouldHaveReceived('warning')
            ->with('ImageTools: the generation job could not be queued; the image is generated in the request.', Mockery::type('array'))
            ->once();
    }

    public function test_a_job_whose_generation_fails_logs_a_warning(): void
    {
        Log::spy();

        (new GenerateImageJob('public/images/missing.png?w=16'))->handle();

        Log::shouldHaveReceived('warning')
            ->with('ImageTools: the queued generation produced no file.', Mockery::type('array'))
            ->once();
        $this->assertFileDoesNotExist(base_path('bootstrap/cache/image-tools.php'));
    }

    public function test_a_queued_miss_sees_an_entry_that_a_worker_wrote_since(): void
    {
        Bus::fake();

        // The facade loaded the manifest in setUp. A worker in another process writes the entry afterwards.
        $paths = app(PathResolver::class);
        $stored = $paths->storedFile('public/images/queue.png?w=16');
        (new Manifest(base_path('bootstrap/cache/image-tools.php')))->put('default', $paths->seed('public/images/queue.png?w=16'), [
            'path' => $stored['path'],
            'disk' => $stored['disk'],
            'source' => 'public/images/queue.png?w=16',
            'source_disk' => null,
        ]);

        $url = ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        $this->assertSame(Storage::disk($stored['disk'])->url($stored['path']), $url);
        Bus::assertNothingDispatched();
    }

    public function test_the_unique_id_has_a_fixed_length(): void
    {
        // The lock key must fit a database cache store (cache_locks.key is 255 characters).
        $job = new GenerateImageJob('public/images/' . str_repeat('a', 300) . '.png?w=16');

        $this->assertSame(40, \strlen($job->uniqueId()));
    }

    public function test_a_unique_for_of_zero_falls_back_to_an_hour(): void
    {
        // A lock of 0 seconds never expires on Redis.
        config()->set('image-tools.unique_for', 0);

        $this->assertSame(3600, (new GenerateImageJob('public/images/queue.png?w=16'))->uniqueFor());
    }

    public function test_a_failed_push_releases_the_unique_lock(): void
    {
        File::delete(base_path('public/images/late.png'));

        // The queue is down, and the generation in the request fails too: the source is missing.
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.'));
        Log::spy();

        $this->assertSame('', ImageToolsFacade::asset('public/images/late.png?w=16&queue=1'));

        // The queue is back, and the source is there now.
        Bus::fake();
        File::put(base_path('public/images/late.png'), $this->pngBytes());

        ImageToolsFacade::asset('public/images/late.png?w=16&queue=1');

        Bus::assertDispatchedTimes(GenerateImageJob::class, 1);
    }

    public function test_an_unknown_fallback_throws_before_anything_is_queued(): void
    {
        config()->set('image-tools.queue_fallback', 'orignal');
        Bus::fake();

        try {
            ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');
            $this->fail('An unknown queue_fallback must throw.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'orignal'", $e->getMessage());
        }

        Bus::assertNothingDispatched();
    }

    public function test_a_job_whose_entry_already_exists_generates_nothing(): void
    {
        // Another process wrote the entry after this job was queued, for example a job from a stale view.
        $paths = app(PathResolver::class);
        (new Manifest(base_path('bootstrap/cache/image-tools.php')))->put('default', $paths->seed('public/images/queue.png?w=16'), [
            'path' => 'image-tools/elsewhere.png',
            'disk' => 'public',
            'source' => 'public/images/queue.png?w=16',
            'source_disk' => null,
        ]);

        (new GenerateImageJob('public/images/queue.png?w=16&queue=1'))->handle();

        $this->assertSame([], Storage::disk('public')->allFiles('image-tools'));
    }

    public function test_an_unknown_fallback_throws_on_a_sync_connection_too(): void
    {
        // A test suite usually runs on sync: a wrong value must fail there, not first in production.
        config()->set('image-tools.queue_connection', 'sync');
        config()->set('image-tools.queue_fallback', 'orignal');

        $this->expectException(InvalidArgumentException::class);

        ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');
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

    public function test_job_for_a_width_above_the_source_stores_no_file(): void
    {
        File::put(base_path('public/images/queue-small.png'), $this->pngBytes(40, 20));

        (new GenerateImageJob('public/images/queue-small.png?w=80&queue=1'))->handle();

        $manifest = require base_path('bootstrap/cache/image-tools.php');
        $this->assertNull($manifest['public/images/queue-small.png?w=80']['path']);
        $this->assertSame([], Storage::disk('public')->allFiles('image-tools'));

        // Once the worker has run, later renders get null and nothing is queued again.
        Bus::fake();

        $this->assertNull(app(ImageTools::class)->asset('public/images/queue-small.png?w=80&queue=1'));
        Bus::assertNotDispatched(GenerateImageJob::class);
    }

    public function test_dispatched_job_uses_configured_connection_and_queue(): void
    {
        config()->set('image-tools.queue_connection', 'redis');
        config()->set('image-tools.queue_name', 'images');
        Bus::fake();

        ImageToolsFacade::asset('public/images/queue.png?w=16&queue=1');

        Bus::assertDispatched(
            GenerateImageJob::class,
            fn (GenerateImageJob $job) => $job->connection === 'redis' && $job->queue === 'images'
        );
    }
}
