<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Tests\TestCase;
use League\Flysystem\UnableToDeleteFile;
use Mockery;

use function base_path;
use function time;
use function touch;

class PruneImagesCommandTest extends TestCase
{
    /** A manifest in shared storage, the setup prune is made for. */
    private const MANIFEST = 'storage/app/image-tools-prune-test.php';

    private string $manifestFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        config()->set('image-tools.manifest_path', self::MANIFEST);
        $this->manifestFile = base_path(self::MANIFEST);
        File::ensureDirectoryExists(\dirname($this->manifestFile));
        File::delete([$this->manifestFile, $this->manifestFile . '.lock']);
    }

    protected function tearDown(): void
    {
        File::delete([$this->manifestFile, $this->manifestFile . '.lock']);

        parent::tearDown();
    }

    public function test_an_old_orphan_is_deleted_and_every_other_file_is_kept(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/orphan--0000000003.webp', age: 7200);
        $this->file('image-tools/new--0000000004.webp', age: 60);
        $this->file('image-tools/notes.txt', age: 7200);

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('Deleted [image-tools/orphan--0000000003.webp].')
            ->expectsOutputToContain('Listed 4, orphans 1, deleted 1, skipped 0, kept as too new 1, kept with no time 0, failed 0.')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing('image-tools/orphan--0000000003.webp');
        Storage::disk('public')->assertExists('image-tools/a--0000000001.webp');
        Storage::disk('public')->assertExists('image-tools/b--0000000002.webp');
        Storage::disk('public')->assertExists('image-tools/new--0000000004.webp');
        Storage::disk('public')->assertExists('image-tools/notes.txt');
    }

    public function test_an_entry_on_another_disk_does_not_reference_a_file_on_this_one(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/c--0000000003.webp', age: 7200);
        $this->entry('c', 'image-tools/c--0000000003.webp', 's3');

        $this->artisan('imagetools:prune')->assertSuccessful();

        Storage::disk('public')->assertMissing('image-tools/c--0000000003.webp');
    }

    public function test_an_empty_manifest_deletes_nothing(): void
    {
        $this->file('image-tools/orphan--0000000003.webp', age: 7200);

        $this->artisan('imagetools:prune', ['--force' => true])
            ->expectsOutputToContain('The manifest is empty')
            ->assertFailed();

        Storage::disk('public')->assertExists('image-tools/orphan--0000000003.webp');
    }

    public function test_a_per_release_manifest_needs_force(): void
    {
        config()->set('image-tools.manifest_path', 'bootstrap/cache/image-tools.php');
        $this->manifestFile = base_path('bootstrap/cache/image-tools.php');
        File::delete($this->manifestFile);

        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/orphan--0000000003.webp', age: 7200);

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('bootstrap/cache')
            ->assertFailed();
        Storage::disk('public')->assertExists('image-tools/orphan--0000000003.webp');

        $this->artisan('imagetools:prune', ['--force' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing('image-tools/orphan--0000000003.webp');
    }

    public function test_more_than_half_orphans_needs_force(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->file('image-tools/x--0000000003.webp', age: 7200);
        $this->file('image-tools/y--0000000004.webp', age: 7200);

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('2 of 3 files are orphans')
            ->assertFailed();
        Storage::disk('public')->assertExists('image-tools/x--0000000003.webp');

        $this->artisan('imagetools:prune', ['--force' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing('image-tools/x--0000000003.webp');
        Storage::disk('public')->assertMissing('image-tools/y--0000000004.webp');
        Storage::disk('public')->assertExists('image-tools/a--0000000001.webp');
    }

    public function test_dry_run_lists_the_orphans_and_changes_nothing(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/orphan--0000000003.webp', age: 7200);
        $before = File::get($this->manifestFile);

        $this->artisan('imagetools:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete [image-tools/orphan--0000000003.webp].')
            ->expectsOutputToContain('to delete 1')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('image-tools/orphan--0000000003.webp');
        $this->assertSame($before, File::get($this->manifestFile));
    }

    public function test_a_file_that_an_entry_references_by_the_time_of_the_delete_is_kept(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->referencedFile('image-tools/c--0000000003.webp');

        // The command's first read misses the entry that a request wrote since.
        $this->app->bind(Manifest::class, fn () => new class(base_path(self::MANIFEST)) extends Manifest
        {
            public function all(string $namespace = 'default'): array
            {
                $entries = parent::all($namespace);
                unset($entries['image-tools/c--0000000003.webp']);

                return $entries;
            }
        });

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('Skipped [image-tools/c--0000000003.webp]: an entry references it now.')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('image-tools/c--0000000003.webp');
    }

    public function test_a_delete_that_throws_is_logged_and_the_run_goes_on(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/x--0000000003.webp', age: 7200);
        $this->file('image-tools/y--0000000004.webp', age: 7200);

        // A disk that refuses one delete and does the other.
        $real = Storage::disk('public');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturnUsing(function (string $path) use ($real) {
            if ($path === 'image-tools/x--0000000003.webp') {
                throw UnableToDeleteFile::atLocation($path, 'Access denied');
            }

            return $real->delete($path);
        });
        Storage::set('public', $disk);

        Log::spy();

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('orphans 2, deleted 1, skipped 0, kept as too new 0, kept with no time 0, failed 1.')
            ->assertFailed();

        Storage::disk('public')->assertMissing('image-tools/y--0000000004.webp');
        Log::shouldHaveReceived('warning')
            ->with('ImageTools: an orphan could not be deleted.', Mockery::type('array'))
            ->once();
    }

    public function test_a_delete_that_returns_false_is_logged_and_the_run_goes_on(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/x--0000000003.webp', age: 7200);
        $this->file('image-tools/y--0000000004.webp', age: 7200);

        // A disk with its 'throw' option off refuses one delete and does the other.
        $real = Storage::disk('public');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturnUsing(
            fn (string $path) => $path === 'image-tools/x--0000000003.webp' ? false : $real->delete($path)
        );
        Storage::set('public', $disk);

        Log::spy();

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('orphans 2, deleted 1, skipped 0, kept as too new 0, kept with no time 0, failed 1.')
            ->assertFailed();

        Storage::disk('public')->assertMissing('image-tools/y--0000000004.webp');

        Log::shouldHaveReceived('warning')
            ->with('ImageTools: an orphan could not be deleted.', Mockery::type('array'))
            ->once();
    }

    public function test_a_file_in_a_subdirectory_or_with_another_name_is_not_touched(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/old/c--0000000003.webp', age: 7200);
        $this->file('image-tools/short--00000003.webp', age: 7200);
        $this->file('image-tools/upper--000000000A.webp', age: 7200);

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('Listed 2, orphans 0')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('image-tools/old/c--0000000003.webp');
        Storage::disk('public')->assertExists('image-tools/short--00000003.webp');
        Storage::disk('public')->assertExists('image-tools/upper--000000000A.webp');
    }

    public function test_the_first_line_names_the_manifest_and_its_entry_count(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('Manifest [' . $this->manifestFile . ']: 1 entries. Disk [public].')
            ->assertSuccessful();
    }

    public function test_dry_run_lists_the_orphans_also_when_more_than_half_are_orphans(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->file('image-tools/x--0000000003.webp', age: 7200);
        $this->file('image-tools/y--0000000004.webp', age: 7200);

        $this->artisan('imagetools:prune', ['--dry-run' => true])
            ->expectsOutputToContain('2 of 3 files are orphans')
            ->expectsOutputToContain('Would delete [image-tools/x--0000000003.webp].')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('image-tools/x--0000000003.webp');
    }

    public function test_a_file_that_changed_since_the_listing_is_kept(): void
    {
        $this->referencedFile('image-tools/a--0000000001.webp');
        $this->referencedFile('image-tools/b--0000000002.webp');
        $this->file('image-tools/x--0000000003.webp', age: 7200);

        // A worker encodes the file again between the listing and the delete; its entry comes later.
        $file = Storage::disk('public')->path('image-tools/x--0000000003.webp');
        $this->app->bind(Manifest::class, fn () => new class(base_path(self::MANIFEST), $file) extends Manifest
        {
            public function __construct(string $path, private string $file)
            {
                parent::__construct($path);
            }

            public function whileLocked(callable $callback): mixed
            {
                touch($this->file, time());

                return parent::whileLocked($callback);
            }
        });

        $this->artisan('imagetools:prune')
            ->expectsOutputToContain('Skipped [image-tools/x--0000000003.webp]: the file changed since the listing.')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('image-tools/x--0000000003.webp');
    }

    /**
     * An old file on the output disk with a manifest entry that references it.
     */
    private function referencedFile(string $path): void
    {
        $this->file($path, age: 7200);
        $this->entry($path, $path, 'public');
    }

    private function file(string $path, int $age): void
    {
        Storage::disk('public')->put($path, 'bytes');
        touch(Storage::disk('public')->path($path), time() - $age);
    }

    private function entry(string $key, string $path, string $disk): void
    {
        (new Manifest($this->manifestFile))->put('default', $key, [
            'path' => $path,
            'disk' => $disk,
            'source' => 'public/images/x.png?w=8',
            'source_disk' => null,
        ]);
    }
}
