<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Unit;

use Isapp\ImageTools\Support\PathResolver;
use Isapp\ImageTools\Tests\TestCase;

class PathResolverTest extends TestCase
{
    private PathResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PathResolver;
    }

    public function test_orders_query_parameters_canonically(): void
    {
        $a = $this->resolver->seed('public/images/a.jpg?w=1200&h=630');
        $b = $this->resolver->seed('public/images/a.jpg?h=630&w=1200');
        $this->assertSame($a, $b);
    }

    public function test_different_values_produce_different_seed(): void
    {
        $a = $this->resolver->seed('public/images/a.jpg?w=1200&h=630&q=75');
        $b = $this->resolver->seed('public/images/a.jpg?w=1200&h=630&q=80');
        $this->assertNotSame($a, $b);
    }

    public function test_no_query_keeps_plain_path_seed(): void
    {
        $this->assertSame('public/images/a.jpg', $this->resolver->seed('public/images/a.jpg'));
    }

    public function test_unknown_query_keys_are_excluded_from_the_seed(): void
    {
        $this->assertSame(
            $this->resolver->seed('public/images/a.jpg?w=1200'),
            $this->resolver->seed('public/images/a.jpg?w=1200&foo=bar')
        );
    }

    public function test_lossless_is_part_of_the_identity(): void
    {
        $lossy = $this->resolver->seed('public/images/a.png?format=webp');
        $lossless = $this->resolver->seed('public/images/a.png?format=webp&lossless=1');

        $this->assertNotSame($lossy, $lossless);
        $this->assertNotSame(
            $this->resolver->storedFile('public/images/a.png?format=webp')['path'],
            $this->resolver->storedFile('public/images/a.png?format=webp&lossless=1')['path']
        );

        // Order still does not matter.
        $this->assertSame(
            $lossless,
            $this->resolver->seed('public/images/a.png?lossless=1&format=webp')
        );
    }

    public function test_source_disk_is_folded_into_the_seed(): void
    {
        $local = $this->resolver->seed('a.jpg?w=200');
        $s3 = $this->resolver->seed('a.jpg?w=200', 's3');

        $this->assertNotSame($local, $s3);
        $this->assertSame('s3:a.jpg?w=200', $s3);
    }
}
