<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class PlatformGuardTest extends TestCase
{
    public function test_require_pcntl_on_windows_triggers_skip(): void
    {
        if (function_exists('pcntl_fork')) {
            $this->markTestSkipped('This test only validates skip behavior on Windows');
        }

        try {
            PlatformGuard::requirePcntl();
            $this->fail('Should have skipped');
        } catch (\PHPUnit\Framework\SkippedTestError) {
            $this->assertTrue(true);
        }
    }

    public function test_require_extension_checks_loaded(): void
    {
        // json is always loaded
        PlatformGuard::requireExtension('json');
        $this->assertTrue(true);
    }
}
