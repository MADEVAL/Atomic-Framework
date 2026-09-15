<?php
declare(strict_types=1);

namespace Tests\Engine\Tools;

use Engine\Atomic\Tools\Nonce;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TempPath;

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

    public function test_nonce_survives_a_new_request_hive(): void
    {
        $f3 = \Base::instance();
        $original_cache = \Cache::instance();
        $cache_path = TempPath::make_dir('atomic_nonce_');
        $cache = new \Cache('folder=' . $cache_path);
        \Registry::set(\Cache::class, $cache);

        try {
            $action = 'cross_request';
            $token = $this->nonce->create_nonce($action, 60);
            $key = 'nonce_' . md5($action . '_' . $token);

            // A new HTTP request starts with a fresh Fat-Free hive while the
            // cache-backed nonce must remain available for verification.
            $hive = ReflectionHelper::get(\Base::class, 'hive', $f3);
            unset($hive[$key]);
            ReflectionHelper::set(\Base::class, 'hive', $hive, $f3);

            $this->assertTrue($this->nonce->verify_nonce($token, $action));
        } finally {
            $cache->reset();
            \Registry::set(\Cache::class, $original_cache);
            TempPath::remove($cache_path);
        }
    }

    public function test_verify_invalid_nonce(): void
    {
        $this->assertFalse($this->nonce->verify_nonce('invalid_token', 'login'));
    }

    public function test_expired_nonce_is_rejected(): void
    {
        $token = $this->nonce->create_nonce('expired', -1);

        $this->assertFalse(
            $this->nonce->verify_nonce($token, 'expired'),
            'A nonce whose TTL has passed must be rejected.'
        );
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

    public function test_cannot_instantiate_externally(): void
    {
        $this->expectException(\Error::class);
        new Nonce();
    }
}
