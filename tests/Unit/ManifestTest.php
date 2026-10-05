<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Isapp\ImageTools\Support\Manifest;
use Isapp\ImageTools\Tests\TestCase;

use function base_path;
use function chmod;
use function fclose;
use function flock;
use function fopen;
use function posix_geteuid;
use function realpath;
use function var_export;

class ManifestTest extends TestCase
{
    private string $path;

    private string $otherPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = base_path('bootstrap/cache/manifest-test.php');
        $this->otherPath = base_path('bootstrap/cache/manifest-other.php');

        File::delete([$this->path, $this->otherPath]);
    }

    protected function tearDown(): void
    {
        File::delete([$this->path, $this->otherPath, $this->path . '.lock', $this->otherPath . '.lock']);

        parent::tearDown();
    }

    public function test_a_write_starts_from_the_file_and_not_from_an_older_copy(): void
    {
        (new Manifest($this->path))->put('default', 'a.png?w=8', $this->entry('a.png?w=8', 'image-tools/a.png'));

        // A long-lived process, such as a queue worker, loads the manifest now.
        $worker = new Manifest($this->path);

        // Another process changes that entry afterwards.
        $changed = $this->entry('a.png?w=8', null);
        (new Manifest($this->path))->put('default', 'a.png?w=8', $changed);

        // The worker writes an unrelated entry. It must not bring back its old copy of the first one.
        $worker->put('default', 'b.png?w=8', $this->entry('b.png?w=8', 'image-tools/b.png'));

        $entries = require $this->path;

        $this->assertSame($changed, $entries['a.png?w=8']);
        $this->assertArrayHasKey('b.png?w=8', $entries);
    }

    public function test_a_namespace_loaded_from_another_file_is_written_to_that_file(): void
    {
        $default = ['x.png?w=8' => $this->entry('x.png?w=8', 'image-tools/x.png')];
        File::put($this->path, "<?php\n\nreturn " . var_export($default, true) . ";\n");
        File::put($this->otherPath, "<?php\n\nreturn [];\n");

        $manifest = new Manifest($this->path);
        $manifest->load($this->otherPath);
        $manifest->put($this->otherPath, 'c.png?w=8', $this->entry('c.png?w=8', 'image-tools/c.png'));

        $this->assertSame($default, require $this->path);
        $this->assertArrayHasKey('c.png?w=8', require $this->otherPath);
    }

    public function test_a_lock_file_without_write_permission_does_not_stop_a_write(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root opens a read-only file for writing, so the read-only fallback is not reached.');
        }

        // Another user created the lock file, for example a deploy that ran as root.
        File::put($this->path . '.lock', '');
        chmod($this->path . '.lock', 0444);

        Log::spy();

        (new Manifest($this->path))->put('default', 'a.png?w=8', $this->entry('a.png?w=8', 'image-tools/a.png'));

        $this->assertArrayHasKey('a.png?w=8', require $this->path);

        // The write took the lock through the read-only handle; it did not go ahead without it.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_write_and_a_clear_read_the_file_under_the_lock(): void
    {
        // The read is the start of each read-modify-write, so the lock must be held from there on.
        $manifest = new class($this->path) extends Manifest
        {
            /** @var list<bool> */
            public array $lockHeldAtRead = [];

            protected function read(string $path): array
            {
                $this->lockHeldAtRead[] = ! ManifestTest::lockIsFree($path);

                return parent::read($path);
            }
        };

        // Each call releases the lock. The check runs before the next call: a lock that
        // this process still held would make that call wait for ever.
        $manifest->put('default', 'a.png?w=8', $this->entry('a.png?w=8', 'image-tools/a.png'));
        $this->assertTrue(self::lockIsFree($this->path), 'put() did not release the lock.');

        $manifest->clear();
        $this->assertTrue(self::lockIsFree($this->path), 'clear() did not release the lock.');

        $this->assertSame([true, true], $manifest->lockHeldAtRead);
    }

    /**
     * Whether another handle can take the lock of a manifest file now. The lock
     * file is found the way Manifest::lock() finds it.
     */
    public static function lockIsFree(string $path): bool
    {
        $handle = fopen((realpath($path) ?: $path) . '.lock', 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $free;
    }

    public function test_refresh_reads_an_entry_that_another_process_wrote(): void
    {
        // A web process loads the manifest; a queue worker writes an entry afterwards.
        $web = new Manifest($this->path);
        (new Manifest($this->path))->put('default', 'a.png?w=8', $this->entry('a.png?w=8', 'image-tools/a.png'));

        $this->assertFalse($web->has('default', 'a.png?w=8'));

        $web->refresh('default');

        $this->assertTrue($web->has('default', 'a.png?w=8'));
    }

    public function test_refresh_reads_the_file_when_load_got_an_older_copy(): void
    {
        $a = $this->entry('a.png?w=8', 'image-tools/a.png');
        (new Manifest($this->path))->put('default', 'a.png?w=8', $a);
        (new Manifest($this->path))->put('default', 'b.png?w=8', $this->entry('b.png?w=8', 'image-tools/b.png'));

        // Under opcache (PHP-FPM with validate_timestamps=0), the `require` in load() can return
        // a copy compiled before the last write. This subclass loads such an older copy.
        $manifest = new class($this->path, ['a.png?w=8' => $a]) extends Manifest
        {
            public function __construct(string $path, private array $older)
            {
                parent::__construct($path);
            }

            public function load(?string $path = null): void
            {
                parent::load($path);

                if ($path === null) {
                    $this->namespaces['default'] = $this->older;
                }
            }
        };

        $this->assertFalse($manifest->has('default', 'b.png?w=8'));

        $manifest->refresh('default');

        $this->assertTrue($manifest->has('default', 'b.png?w=8'));
    }

    public function test_refresh_does_not_read_a_file_that_did_not_change(): void
    {
        (new Manifest($this->path))->put('default', 'a.png?w=8', $this->entry('a.png?w=8', 'image-tools/a.png'));

        $manifest = new class($this->path) extends Manifest
        {
            public int $reads = 0;

            protected function read(string $path): array
            {
                $this->reads++;

                return parent::read($path);
            }
        };

        $manifest->refresh('default');
        $manifest->refresh('default');

        $this->assertSame(0, $manifest->reads);
    }

    public function test_clear_returns_the_entries_and_removes_the_file(): void
    {
        $entries = ['a.png?w=8' => $this->entry('a.png?w=8', 'image-tools/a.png')];
        File::put($this->path, "<?php\n\nreturn " . var_export($entries, true) . ";\n");

        $manifest = new Manifest($this->path);

        $this->assertSame($entries, $manifest->clear());
        $this->assertFileDoesNotExist($this->path);
        $this->assertSame([], $manifest->all());
    }

    /**
     * @return array{path: string|null, disk: string|null, source: string, source_disk: null}
     */
    private function entry(string $source, ?string $path): array
    {
        return [
            'path' => $path,
            'disk' => $path === null ? null : 'public',
            'source' => $source,
            'source_disk' => null,
        ];
    }
}
