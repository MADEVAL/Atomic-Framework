<?php
declare(strict_types=1);

namespace Tests\Engine\Hook;

use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\TestCase;

class HookGranularTest extends TestCase
{
    private Hook $hook;

    protected function setUp(): void
    {
        $this->hook = Hook::instance();
    }

    public function test_remove_action_removes_only_specified_callback(): void
    {
        $tag = 'granular.rm.' . uniqid();
        $called_a = false;
        $called_b = false;

        $cb_a = function () use (&$called_a) { $called_a = true; };
        $cb_b = function () use (&$called_b) { $called_b = true; };

        $this->hook->add_action($tag, $cb_a, 10);
        $this->hook->add_action($tag, $cb_b, 10);

        $this->assertTrue($this->hook->remove_action($tag, $cb_a, 10));

        $this->hook->do_action($tag);
        $this->assertFalse($called_a, 'Callback A should have been removed');
        $this->assertTrue($called_b, 'Callback B should still fire');
    }

    public function test_remove_action_considers_priority(): void
    {
        $tag = 'granular.prio.' . uniqid();
        $called = false;
        $cb = function () use (&$called) { $called = true; };

        $this->hook->add_action($tag, $cb, 20);
        $this->assertFalse($this->hook->remove_action($tag, $cb, 10), 'Should not remove when priority does not match');

        $this->hook->do_action($tag);
        $this->assertTrue($called, 'Callback at priority 20 should still fire after failed removal at priority 10');
    }

    public function test_has_action_checks_specific_callback(): void
    {
        $tag = 'granular.has.' . uniqid();
        $cb_a = function () {};
        $cb_b = function () {};

        $this->hook->add_action($tag, $cb_a, 10);

        $this->assertTrue($this->hook->has_action($tag, $cb_a), 'Should detect registered callback');
        $this->assertFalse($this->hook->has_action($tag, $cb_b), 'Should not detect unregistered callback');
    }

    public function test_remove_filter_removes_only_specified_filter(): void
    {
        $tag = 'granular.filter.' . uniqid();

        $upper = function ($v) { return strtoupper($v); };
        $append = function ($v) { return $v . '!'; };

        $this->hook->add_filter($tag, $upper, 10);
        $this->hook->add_filter($tag, $append, 10);

        $this->assertTrue($this->hook->remove_filter($tag, $upper, 10));

        $result = $this->hook->apply_filters($tag, 'hello');
        $this->assertSame('hello!', $result, 'Only append filter should remain');
    }

    public function test_has_filter_checks_specific_callback(): void
    {
        $tag = 'granular.hasf.' . uniqid();
        $cb_a = function ($v) { return $v; };
        $cb_b = function ($v) { return $v; };

        $this->hook->add_filter($tag, $cb_a, 10);

        $this->assertTrue($this->hook->has_filter($tag, $cb_a), 'Should detect registered filter');
        $this->assertFalse($this->hook->has_filter($tag, $cb_b), 'Should not detect unregistered filter');
    }

    public function test_remove_action_without_callback_removes_all(): void
    {
        $tag = 'granular.all.' . uniqid();
        $called_a = false;
        $called_b = false;

        $this->hook->add_action($tag, function () use (&$called_a) { $called_a = true; });
        $this->hook->add_action($tag, function () use (&$called_b) { $called_b = true; });

        $this->assertTrue($this->hook->remove_action($tag));

        $this->hook->do_action($tag);
        $this->assertFalse($called_a);
        $this->assertFalse($called_b);
        $this->assertFalse($this->hook->has_action($tag));
    }

    public function test_remove_nonexistent_callback_returns_false(): void
    {
        $tag = 'granular.nx.' . uniqid();
        $cb = function () {};

        $this->hook->add_action($tag, $cb, 10);
        $this->assertFalse($this->hook->remove_action($tag, function () {}, 10));
    }
}
