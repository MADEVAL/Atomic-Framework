<?php
declare(strict_types=1);

namespace Tests\Engine\Tools;

use Engine\Atomic\Tools\Nonce;
use PHPUnit\Framework\TestCase;

class NonceTest extends TestCase
{
    private Nonce $nonce;

    protected function setUp(): void
    {
        $f3 = \Base::instance();
        $f3->set('IP', '127.0.0.1');
        $f3->set('AGENT', 'PHPUnit/TestAgent');
        $this->nonce = Nonce::instance();
    }

    public function test_create_returns_hex_string(): void
    {
        $token = $this->nonce->create_nonce('test_action');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function test_verify_valid_nonce(): void
    {
        $token = $this->nonce->create_nonce('login');
        $this->assertTrue($this->nonce->verify_nonce($token, 'login'));
    }

    public function test_verify_invalid_nonce(): void
    {
        $this->assertFalse($this->nonce->verify_nonce('invalid_token', 'login'));
    }

    public function test_verify_wrong_action(): void
    {
        $token = $this->nonce->create_nonce('action_a');
        $this->assertFalse($this->nonce->verify_nonce($token, 'action_b'));
    }

    public function test_verify_empty_token(): void
    {
        $this->assertFalse($this->nonce->verify_nonce('', 'test'));
    }

    public function test_nonce_consumed_after_verify(): void
    {
        $token = $this->nonce->create_nonce('once');
        $this->assertTrue($this->nonce->verify_nonce($token, 'once'));
        $this->assertFalse($this->nonce->verify_nonce($token, 'once'));
    }

    public function test_nonce_uniqueness(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = $this->nonce->create_nonce('test');
        }
        $this->assertCount(50, array_unique($tokens));
    }

    public function test_nonce_invalid_with_different_ip(): void
    {
        $token = $this->nonce->create_nonce('ip_check');
        $f3 = \Base::instance();
        $f3->set('IP', '10.0.0.1');
        $this->assertFalse($this->nonce->verify_nonce($token, 'ip_check'));
        $f3->set('IP', '127.0.0.1');
    }

    public function test_nonce_invalid_with_different_ua(): void
    {
        $token = $this->nonce->create_nonce('ua_check');
        $f3 = \Base::instance();
        $f3->set('AGENT', 'DifferentAgent');
        $this->assertFalse($this->nonce->verify_nonce($token, 'ua_check'));
        $f3->set('AGENT', 'PHPUnit/TestAgent');
    }
}
