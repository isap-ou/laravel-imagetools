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
use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Tests\TestCase;
use Mockery;

use function app;
use function base_path;
use function preg_replace_callback;
use function serialize;
use function str_replace;
use function unserialize;

class ImageToolsMediaTest extends TestCase
{
    private string $manifestFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // output disk
        Storage::fake('s3');     // the Media's disk

        Storage::disk('s3')->put('42/slide.png', $this->pngBytes());

        $this->manifestFile = base_path('bootstrap/cache/image-tools.php');
        File::delete($this->manifestFile);
        ImageToolsFacade::loadManifest();
    }

    public function test_media_is_the_same_call_as_disk_asset_and_records_the_media_key(): void
    {
        $url = ImageToolsFacade::media($this->media(), 'w=8&format=webp');

        $this->assertSame(app(ImageTools::class)->disk('s3')->asset('42/slide.png?w=8&format=webp'), $url);

        $seed = app(PathResolver::class)->seed('42/slide.png?w=8&format=webp', 's3');
        $entry = (require $this->manifestFile)[$seed];

        $this->assertSame(42, $entry['media_id']);
        $this->assertSame('s3', $entry['source_disk']);
        $this->assertSame('42/slide.png?w=8&format=webp', $entry['source']);
        Storage::disk('public')->assertExists($entry['path']);
    }

    public function test_media_takes_a_query_with_a_leading_question_mark_or_none(): void
    {
        $with = ImageToolsFacade::media($this->media(), '?w=8');
        $without = ImageToolsFacade::media($this->media(), 'w=8');

        $this->assertNotSame('', $with);
        $this->assertSame($with, $without);

        // No query is the original's seed, as asset('path') has.
        ImageToolsFacade::media($this->media());
        $this->assertArrayHasKey('s3:42/slide.png', require $this->manifestFile);
    }

    public function test_asset_entries_have_no_media_key(): void
    {
        app(ImageTools::class)->disk('s3')->generate('42/slide.png?w=8');

        $entry = (require $this->manifestFile)['s3:42/slide.png?w=8'];

        $this->assertArrayNotHasKey('media_id', $entry);
    }

    public function test_an_oversize_media_request_records_the_media_key(): void
    {
        $this->assertNull(ImageToolsFacade::media($this->media(), 'w=800'));

        $entry = (require $this->manifestFile)['s3:42/slide.png?w=800'];

        $this->assertNull($entry['path']);
        $this->assertSame(42, $entry['media_id']);
    }

    public function test_an_adopted_file_records_the_media_key(): void
    {
        $stored = app(PathResolver::class)->storedFile('42/slide.png?w=8', 's3');
        Storage::disk('public')->put($stored['path'], 'bytes of an earlier encode');

        ImageToolsFacade::media($this->media(), 'w=8');

        $entry = (require $this->manifestFile)['s3:42/slide.png?w=8'];

        $this->assertSame($stored['path'], $entry['path']);
        $this->assertSame(42, $entry['media_id']);
    }

    public function test_a_queued_media_miss_carries_the_media_key_to_the_job(): void
    {
        config()->set('image-tools.queue_connection', 'database');
        Bus::fake();

        ImageToolsFacade::media($this->media(), 'w=8&queue=1');

        Bus::assertDispatched(GenerateImageJob::class, function (GenerateImageJob $job) {
            return $job->path === '42/slide.png?w=8&queue=1' && $job->sourceDisk === 's3' && $job->mediaId === 42;
        });
    }

    public function test_the_job_records_the_media_key(): void
    {
        (new GenerateImageJob('42/slide.png?w=8', 'default', 's3', 42))->handle();

        $this->assertSame(42, (require $this->manifestFile)['s3:42/slide.png?w=8']['media_id']);
    }

    public function test_a_job_that_1_5_queued_still_runs(): void
    {
        // The payload of a job queued before the Media key existed: it has no mediaId.
        $job = unserialize($this->withoutMediaId(serialize(new GenerateImageJob('42/slide.png?w=8', 'default', 's3'))));

        $this->assertNull($job->mediaId);

        $job->handle();

        $this->assertArrayHasKey('s3:42/slide.png?w=8', require $this->manifestFile);
    }

    public function test_the_media_key_does_not_change_the_unique_id(): void
    {
        $this->assertSame(
            (new GenerateImageJob('42/slide.png?w=8', 'default', 's3'))->uniqueId(),
            (new GenerateImageJob('42/slide.png?w=8', 'default', 's3', 42))->uniqueId()
        );
    }

    public function test_regenerate_keeps_the_media_key(): void
    {
        ImageToolsFacade::media($this->media(), 'w=8');

        $this->artisan('imagetools:regenerate', ['--all' => true])->assertSuccessful();

        $this->assertSame(42, (require $this->manifestFile)['s3:42/slide.png?w=8']['media_id']);
    }

    public function test_a_file_name_with_a_question_mark_gives_an_empty_string(): void
    {
        Log::spy();

        $media = new class
        {
            public string $disk = 's3';

            public function getPathRelativeToRoot(): string
            {
                return '42/faq?.png';
            }

            public function getKey(): int
            {
                return 42;
            }
        };

        $this->assertSame('', ImageToolsFacade::media($media, 'w=8'));
        $this->assertFileDoesNotExist($this->manifestFile);
        Log::shouldHaveReceived('warning')
            ->with('ImageTools: the Media file name contains a "?"; media() returns an empty string.', Mockery::type('array'))
            ->once();
    }

    public function test_an_object_that_is_not_a_media_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageToolsFacade::media(new class
        {
            public string $disk = 's3';
        }, 'w=8');
    }

    public function test_a_media_without_a_disk_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageToolsFacade::media($this->media(disk: null), 'w=8');
    }

    /**
     * A serialized job without its mediaId property, the shape a 1.5 payload has.
     */
    private function withoutMediaId(string $serialized): string
    {
        $this->assertStringContainsString('s:7:"mediaId";N;', $serialized);

        $serialized = str_replace('s:7:"mediaId";N;', '', $serialized);

        // The object header counts its properties: one fewer now.
        return preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+)(:)/',
            fn (array $m) => $m[1] . ((int) $m[2] - 1) . $m[3],
            $serialized
        );
    }

    /**
     * A stand-in for a medialibrary Media: the attribute and the two methods
     * that media() reads.
     */
    private function media(?string $disk = 's3'): object
    {
        return new class($disk)
        {
            public function __construct(public ?string $disk) {}

            public function getPathRelativeToRoot(): string
            {
                return '42/slide.png';
            }

            public function getKey(): int
            {
                return 42;
            }
        };
    }
}
