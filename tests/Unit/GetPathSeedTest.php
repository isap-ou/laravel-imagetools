<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Unit;

use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;
use ReflectionClass;

class GetPathSeedTest extends TestCase
{
    private function seedPath(ImageTools $it, string $path): string
    {
        $ref = new ReflectionClass($it);
        $m = $ref->getMethod('getPathSeed');
        $m->setAccessible(true);
        return $m->invoke($it, $path);
    }

    public function test_orders_query_parameters_canonically(): void
    {
        $it = app(ImageTools::class);
        $a = $this->seedPath($it, 'public/images/a.jpg?w=1200&h=630');
        $b = $this->seedPath($it, 'public/images/a.jpg?h=630&w=1200');
        $this->assertSame($a, $b);
    }

    public function test_different_values_produce_different_seed(): void
    {
        $it = app(ImageTools::class);
        $a = $this->seedPath($it, 'public/images/a.jpg?w=1200&h=630&q=75');
        $b = $this->seedPath($it, 'public/images/a.jpg?w=1200&h=630&q=80');
        $this->assertNotSame($a, $b);
    }

    public function test_no_query_keeps_plain_path_seed(): void
    {
        $it = app(ImageTools::class);
        $seed = $this->seedPath($it, 'public/images/a.jpg');

        $this->assertSame('public/images/a.jpg', $seed);
    }
}