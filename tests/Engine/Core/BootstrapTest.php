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
        $this->assertTrue(ConfigSchema::definitions()['DOMAIN']->isRequired());
        $this->assertArrayNotHasKey('DOMAIN', $defaults);
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
        $this->assertStringContainsString('Bootstrap::boot();', $source);
        $this->assertStringNotContainsString('initialize_application', $source);
    }

    public function test_skeleton_registers_application_provider(): void
    {
        $providers = require ATOMIC_CONFIG . 'providers.php';

        $this->assertSame([
            'App\\Providers\\ApplicationServiceProvider',
        ], $providers['providers']);
    }

    public function test_health_endpoint_only_intercepts_get_requests_on_the_exact_path(): void
    {
        $atomic = \Base::instance();
        $original_path = $atomic->get('PATH');
        $original_verb = $atomic->get('VERB');
        $method = new \ReflectionMethod(Bootstrap::class, 'is_health_request');

        try {
            $atomic->set('VERB', 'GET');
            $atomic->set('PATH', '/health');
            $this->assertTrue($method->invoke(null, $atomic));

            $atomic->set('PATH', '/index.php/health/');
            $this->assertTrue($method->invoke(null, $atomic));

            $atomic->set('VERB', 'POST');
            $this->assertFalse($method->invoke(null, $atomic));

            $atomic->set('VERB', 'GET');
            $atomic->set('PATH', '/api/health');
            $this->assertFalse($method->invoke(null, $atomic));
        } finally {
            $atomic->set('PATH', $original_path);
            $atomic->set('VERB', $original_verb);
        }
    }
}
