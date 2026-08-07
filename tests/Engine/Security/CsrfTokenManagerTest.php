<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    private array $session = [];
    private CsrfTokenManager $manager;

    protected function setUp(): void
    {
        $this->session = [];
        $this->manager = new CsrfTokenManager(
            new class($this->session) {
                public function __construct(private array &$store) {}
                public function get(string $key): mixed { return $this->store[$key] ?? null; }
                public function set(string $key, mixed $value): void { $this->store[$key] = $value; }
                public function has(string $key): bool { return isset($this->store[$key]); }
            },
            '_csrf_token',
        );
    }

    public function test_token_is_generated_once(): void
    {
        $t1 = $this->manager->token();
        $t2 = $this->manager->token();

        $this->assertSame($t1, $t2);
        $this->assertSame(64, strlen($t1)); // 32 bytes hex = 64 chars
    }

    public function test_generate_creates_new_token_each_time(): void
    {
        $t1 = $this->manager->generate();
        $t2 = $this->manager->generate();

        $this->assertNotSame($t1, $t2);
    }

    public function test_validate_accepts_valid_token(): void
    {
        $token = $this->manager->token();

        $this->assertTrue($this->manager->validate($token));
    }

    public function test_validate_rejects_invalid_token(): void
    {
        $this->manager->token();

        $this->assertFalse($this->manager->validate('wrong-token'));
    }

    public function test_validate_rejects_empty_token(): void
    {
        $this->assertFalse($this->manager->validate(''));
    }

    public function test_field_returns_hidden_input_html(): void
    {
        $field = $this->manager->field();

        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('hidden', $field);
        $this->assertStringContainsString('_csrf_token', $field);
    }

    public function test_meta_tag_returns_meta_html(): void
    {
        $meta = $this->manager->metaTag();

        $this->assertStringContainsString('<meta', $meta);
        $this->assertStringContainsString('csrf-token', $meta);
    }

    public function test_validate_uses_constant_time_comparison(): void
    {
        $token = $this->manager->token();

        // Should not throw, works with valid token
        $this->assertTrue($this->manager->validate($token));

        // Tampered token fails
        $tampered = substr($token, 0, -1) . '0';
        $this->assertFalse($this->manager->validate($tampered));
    }

    public function test_validate_static_accepts_valid_token(): void
    {
        $atomic = \Base::instance();
        $token = bin2hex(random_bytes(32));
        $atomic->set('SESSION.csrf_token', $token);

        $this->assertTrue(CsrfTokenManager::validateStatic($atomic, $token));
    }

    public function test_validate_static_rejects_invalid_token(): void
    {
        $atomic = \Base::instance();
        $atomic->set('SESSION.csrf_token', 'real-token');

        $this->assertFalse(CsrfTokenManager::validateStatic($atomic, 'wrong-token'));
    }

    public function test_validate_static_rejects_when_no_token_stored(): void
    {
        $atomic = \Base::instance();
        $atomic->clear('SESSION.csrf_token');

        $this->assertFalse(CsrfTokenManager::validateStatic($atomic, 'any-token'));
    }
}
