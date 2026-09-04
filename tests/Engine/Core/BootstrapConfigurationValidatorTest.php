<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\BootstrapConfigurationValidator;
use Engine\Atomic\Core\BootstrapConfigurationErrorRenderer;
use Engine\Atomic\Core\ID;
use PHPUnit\Framework\TestCase;

final class BootstrapConfigurationValidatorTest extends TestCase
{
    private BootstrapConfigurationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BootstrapConfigurationValidator();
    }

    public function test_web_requires_domain_and_application_keys(): void
    {
        $errors = $this->validator->validate_web([]);

        $this->assertSame([
            'DOMAIN is not configured.',
            'APP_KEY is not configured.',
            'APP_UUID is not configured.',
            'APP_ENCRYPTION_KEY is not configured.',
        ], $errors);
    }

    public function test_web_accepts_valid_configuration(): void
    {
        $this->assertSame([], $this->validator->validate_web($this->valid_configuration()));
    }

    /** @dataProvider valid_domain_provider */
    public function test_web_accepts_supported_domain_formats(string $domain): void
    {
        $configuration = $this->valid_configuration();
        $configuration['DOMAIN'] = $domain;

        $this->assertSame([], $this->validator->validate_web($configuration));
    }

    public static function valid_domain_provider(): array
    {
        return [
            'hostname' => ['example.com'],
            'hostname and port' => ['example.com:8080'],
            'localhost' => ['localhost:8000'],
            'IPv4' => ['127.0.0.1:8000'],
            'IPv6' => ['[::1]:8000'],
            'HTTPS origin' => ['https://example.com'],
            'HTTP origin with trailing slash' => ['http://localhost:8000/'],
        ];
    }

    /** @dataProvider invalid_domain_provider */
    public function test_web_rejects_invalid_domain(string $domain): void
    {
        $configuration = $this->valid_configuration();
        $configuration['DOMAIN'] = $domain;

        $this->assertSame(['DOMAIN is invalid. Use a host with an optional port, or an HTTP(S) origin.'], $this->validator->validate_web($configuration));
    }

    public static function invalid_domain_provider(): array
    {
        return [
            'path' => ['example.com/admin'],
            'URL path' => ['https://example.com/admin'],
            'query' => ['https://example.com?debug=1'],
            'credentials' => ['https://user:pass@example.com'],
            'unsupported scheme' => ['ftp://example.com'],
            'invalid port' => ['example.com:99999'],
            'spaces' => ['example .com'],
        ];
    }

    public function test_normal_cli_requires_application_keys_but_not_domain(): void
    {
        $configuration = $this->valid_configuration();
        unset($configuration['DOMAIN']);

        $this->assertSame([], $this->validator->validate_cli($configuration, '/migrations/migrate'));

        unset($configuration['APP_KEY']);
        $this->assertSame(['APP_KEY is not configured.'], $this->validator->validate_cli($configuration, '/migrations/migrate'));
    }

    /** @dataProvider repair_command_provider */
    public function test_repair_commands_bypass_application_key_validation(string $command): void
    {
        $this->assertSame([], $this->validator->validate_cli([], $command));
    }

    public static function repair_command_provider(): array
    {
        return [
            'no command' => [''],
            'init' => ['/init'],
            'init key' => ['/init/key'],
            'help' => ['/help'],
            'version' => ['/version'],
        ];
    }

    /** @dataProvider invalid_key_provider */
    public function test_invalid_application_keys_are_reported(string $key, string $value, string $message): void
    {
        $configuration = $this->valid_configuration();
        $configuration[$key] = $value;

        $this->assertSame([$message], $this->validator->validate_cli($configuration, '/schedule/run'));
    }

    public static function invalid_key_provider(): array
    {
        return [
            'APP_KEY placeholder' => ['APP_KEY', 'default-key', 'APP_KEY contains a placeholder value.'],
            'invalid UUID' => ['APP_UUID', 'not-a-uuid', 'APP_UUID must be a valid UUID.'],
            'invalid base64 encryption key' => ['APP_ENCRYPTION_KEY', 'not-base64!', 'APP_ENCRYPTION_KEY must be valid base64 encoding exactly 32 bytes.'],
            'short encryption key' => ['APP_ENCRYPTION_KEY', base64_encode('short'), 'APP_ENCRYPTION_KEY must be valid base64 encoding exactly 32 bytes.'],
        ];
    }

    public function test_command_from_argv_normalizes_colon_syntax(): void
    {
        $this->assertSame('/init/key', $this->validator->command_from_argv(['atomic', 'INIT:KEY']));
        $this->assertSame('', $this->validator->command_from_argv(['atomic']));
    }

    public function test_web_error_page_is_standalone_and_escapes_details(): void
    {
        $page = BootstrapConfigurationErrorRenderer::web_page(['DOMAIN <invalid>'], true);

        $this->assertStringContainsString('<!DOCTYPE html>', $page);
        $this->assertStringContainsString('Application configuration error', $page);
        $this->assertStringContainsString('DOMAIN &lt;invalid&gt;', $page);
        $this->assertStringNotContainsString('DOMAIN <invalid>', $page);
    }

    public function test_production_web_error_page_hides_configuration_details(): void
    {
        $page = BootstrapConfigurationErrorRenderer::web_page(['DOMAIN is not configured.'], false);

        $this->assertStringContainsString('application is not configured correctly', $page);
        $this->assertStringNotContainsString('DOMAIN', $page);
    }

    public function test_cli_error_message_lists_configuration_errors(): void
    {
        $message = BootstrapConfigurationErrorRenderer::cli_message(['APP_KEY is not configured.']);

        $this->assertStringContainsString('Application configuration error', $message);
        $this->assertStringContainsString('APP_KEY is not configured.', $message);
        $this->assertStringContainsString('php atomic init', $message);
    }

    private function valid_configuration(): array
    {
        return [
            'DOMAIN' => 'localhost:8000',
            'APP_KEY' => bin2hex(random_bytes(16)),
            'APP_UUID' => ID::uuid_v4(),
            'APP_ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
        ];
    }
}
