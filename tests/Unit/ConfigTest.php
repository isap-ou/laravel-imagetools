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
}
