<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;

class GenerateImagesCommandTest extends TestCase
{
    private string $bladeDir = 'resources/views/it-test';

    private string $phpDir = 'app/it-test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        File::ensureDirectoryExists(base_path('public/images'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        File::put(base_path('public/images/scan.png'), $png);

        $manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($manifestFile)) {
            File::delete($manifestFile);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->bladeDir));
        File::deleteDirectory(base_path($this->phpDir));
        parent::tearDown();
    }

    private function manifest(): array
    {
        return require base_path('bootstrap/cache/image-tools.php');
    }

    public function test_it_scans_blade_facade_calls_and_generates_images(): void
    {
        Config::set('image-tools.blade_paths', [$this->bladeDir]);
        File::ensureDirectoryExists(base_path($this->bladeDir));
        File::put(
            base_path($this->bladeDir . '/page.blade.php'),
            "{{ ImageTools::asset('public/images/scan.png?w=8') }}"
        );

        $this->artisan('imagetools:generate')->assertSuccessful();

        $manifest = $this->manifest();
        $this->assertArrayHasKey('public/images/scan.png?w=8', $manifest);
        Storage::disk('public')->assertExists($manifest['public/images/scan.png?w=8']['path']);
    }

    public function test_it_scans_php_container_resolved_calls(): void
    {
        Config::set('image-tools.blade_paths', []);
        Config::set('image-tools.php_paths', [$this->phpDir]);
        File::ensureDirectoryExists(base_path($this->phpDir));
        File::put(
            base_path($this->phpDir . '/Foo.php'),
            "<?php app('image-tools')->asset('public/images/scan.png?w=12');"
        );

        $this->artisan('imagetools:generate')->assertSuccessful();

        $this->assertArrayHasKey('public/images/scan.png?w=12', $this->manifest());
    }

    public function test_it_scans_disk_chained_calls_and_generates_from_that_disk(): void
    {
        // Source lives ONLY on the 's3' disk; the derivative is written to 'public'.
        Storage::fake('s3');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
        Storage::disk('s3')->put('assets/scan.png', $png);

        Config::set('image-tools.blade_paths', [$this->bladeDir]);
        File::ensureDirectoryExists(base_path($this->bladeDir));
        File::put(
            base_path($this->bladeDir . '/page.blade.php'),
            "{{ ImageTools::disk('s3')->asset('assets/scan.png?w=8') }}"
        );

        $this->artisan('imagetools:generate')->assertSuccessful();

        $manifest = $this->manifest();
        $this->assertArrayHasKey('s3:assets/scan.png?w=8', $manifest);
        Storage::disk('public')->assertExists($manifest['s3:assets/scan.png?w=8']['path']);
    }

    public function test_it_clears_existing_output_before_regenerating(): void
    {
        // Pre-seed a stale manifest entry + file; the command calls clear first.
        $stale = app(\Isapp\ImageTools\ImageTools::class)->generate('public/images/scan.png?w=99');
        Storage::disk('public')->assertExists($stale['path']);

        Config::set('image-tools.blade_paths', [$this->bladeDir]);
        File::ensureDirectoryExists(base_path($this->bladeDir));
        File::put(
            base_path($this->bladeDir . '/page.blade.php'),
            "{{ ImageTools::asset('public/images/scan.png?w=8') }}"
        );

        $this->artisan('imagetools:generate')->assertSuccessful();

        $manifest = $this->manifest();
        $this->assertArrayHasKey('public/images/scan.png?w=8', $manifest);
        $this->assertArrayNotHasKey('public/images/scan.png?w=99', $manifest);
        Storage::disk('public')->assertMissing($stale['path']);
    }
}
