<?php
declare(strict_types=1);

namespace Tests\Engine\Support;

use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use PHPUnit\Framework\TestCase;

/**
 * Tests for global helper functions from Support/helpers.php
 * Only tests pure/simple functions that don't require deep framework boot.
 */
class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $f3 = \Base::instance();
        $f3->set('IP', '127.0.0.1');
        $f3->set('AGENT', 'PHPUnit/TestAgent');
    }

    public function test_get_year(): void
    {
        $this->assertSame(date('Y'), get_year());
    }

    public function test_get_copyright_years(): void
    {
        $result = get_copyright_years(2020);
        $this->assertSame('2020 - ' . date('Y'), $result);
    }

    public function test_get_date_default_format(): void
    {
        $this->assertSame(date('Y-m-d'), get_date());
    }

    public function test_get_date_custom_format(): void
    {
        $this->assertSame(date('d.m.Y'), get_date('d.m.Y'));
    }

    public function test_get_copy(): void
    {
        $this->assertSame('©', get_copy());
    }

    public function test_add_action(): void
    {
        $called = false;
        add_action('test_helpers_action', function () use (&$called) {
            $called = true;
        });
        do_action('test_helpers_action');
        $this->assertTrue($called);
    }

    public function test_has_action(): void
    {
        add_action('test_has_check', function () {});
        $this->assertTrue(has_action('test_has_check'));
    }

    public function test_remove_action(): void
    {
        $cb = function () {};
        add_action('test_rem_action', $cb);
        remove_action('test_rem_action', $cb);
        $this->assertFalse(has_action('test_rem_action'));
    }

    public function test_add_filter(): void
    {
        add_filter('test_helpers_filter', function ($val) {
            return $val . '_filtered';
        });
        $result = apply_filters('test_helpers_filter', 'input');
        $this->assertSame('input_filtered', $result);
    }

    public function test_add_action_with_enum_tag(): void
    {
        $called = false;

        add_action(MonopayHook::PAYMENT_FAILED, function () use (&$called) {
            $called = true;
        });

        do_action(MonopayHook::PAYMENT_FAILED);

        $this->assertTrue($called);
    }

    public function test_has_filter(): void
    {
        add_filter('test_has_filt', function ($v) { return $v; });
        $this->assertTrue(has_filter('test_has_filt'));
    }

    public function test_remove_filter(): void
    {
        $cb = function ($v) { return $v; };
        add_filter('test_rem_filter', $cb);
        remove_filter('test_rem_filter', $cb);
        $this->assertFalse(has_filter('test_rem_filter'));
    }

    public function test_apply_filters_passthrough(): void
    {
        $result = apply_filters('nonexistent_test_filter_xyz', 'original');
        $this->assertSame('original', $result);
    }

    public function test_create_nonce(): void
    {
        $token = create_nonce('test_action');
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function test_verify_nonce(): void
    {
        $token = create_nonce('verify_test');
        $this->assertTrue(verify_nonce($token, 'verify_test'));
    }

    public function test_verify_nonce_invalid(): void
    {
        $this->assertFalse(verify_nonce('invalid_token', 'test'));
    }

    public function test_atomic_json_encode(): void
    {
        $data = ['key' => 'value', 'url' => 'https://example.com/path'];
        $json = atomic_json_encode($data);
        $this->assertIsString($json);
        // Should have unescaped slashes
        $this->assertStringContainsString('https://example.com/path', $json);
    }

    public function test_is_authenticated_guest(): void
    {
        // Without a logged-in user → should be guest
        $this->assertTrue(is_guest());
        $this->assertFalse(is_authenticated());
    }

    public function test_format_error_trace(): void
    {
        $result = format_error_trace(500, 'Test error', '');
        $this->assertIsString($result);
    }
}
