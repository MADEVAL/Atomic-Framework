<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Bootstrap;
use Engine\Atomic\Core\Config\ConfigSchema;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigSchema::reset();
    }

    protected function tearDown(): void
    {
        ConfigSchema::reset();
    }

    public function test_framework_bootstrap_registers_configuration_schema(): void
    {
        Bootstrap::register_config_schema();

        $defaults = ConfigSchema::defaults();

        $this->assertSame('Atomic', $defaults['APP_NAME']);
        $this->assertSame('mysql', $defaults['DB_DRIVER']);
        $this->assertSame(300, $defaults['AUTH_RATE_LIMIT_WINDOW_SECONDS']);
        $this->assertTrue(ConfigSchema::has('APP_KEY'));
        $this->assertArrayNotHasKey('APP_KEY', $defaults);
    }

    public function test_application_bootstrap_is_only_a_skeleton_adapter(): void
    {
        $source = (string)file_get_contents(
            ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton'
                . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php'
        );

        $this->assertStringContainsString('Bootstrap::boot', $source);
        $this->assertStringNotContainsString('ConfigSchema::', $source);
        $this->assertStringNotContainsString('registerProvider', $source);
    }
}
