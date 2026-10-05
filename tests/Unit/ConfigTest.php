<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Unit;

use Isapp\ImageTools\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_upscaling_is_off_by_default(): void
    {
        // The test environment sets its own config, so the shipped file is read directly.
        $config = require __DIR__ . '/../../config/image-tools.php';

        $this->assertFalse($config['allow_upscale']);
    }

    public function test_generation_is_synchronous_by_default(): void
    {
        $config = require __DIR__ . '/../../config/image-tools.php';

        $this->assertFalse($config['queue']);
    }

    public function test_a_queued_miss_falls_back_to_the_original_by_default(): void
    {
        $config = require __DIR__ . '/../../config/image-tools.php';

        $this->assertSame('original', $config['queue_fallback']);
    }
}
