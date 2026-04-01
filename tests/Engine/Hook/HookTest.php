<?php
declare(strict_types=1);

namespace Tests\Engine\Hook;

use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\TestCase;

class HookTest extends TestCase
{
    private Hook $hook;

    protected function setUp(): void
    {
        $this->hook = Hook::instance();
    }

    public function test_add_action_and_has_action(): void
    {
        $tag = 'test.action.' . uniqid();
        $this->assertFalse($this->hook->has_action($tag));
        $this->hook->add_action($tag, function() {});
        $this->assertTrue($this->hook->has_action($tag));
    }

    public function test_do_action_calls_callback(): void
    {
        $tag = 'do.action.' . uniqid();
        $called = false;
        $this->hook->add_action($tag, function() use (&$called) {
            $called = true;
        });
        $this->hook->do_action($tag);
        $this->assertTrue($called);
    }

    public function test_do_action_passes_args(): void
    {
        $tag = 'args.action.' . uniqid();
        $received = null;
        $this->hook->add_action($tag, function($val) use (&$received) {
            $received = $val;
        });
        $this->hook->do_action($tag, 'hello');
        $this->assertSame('hello', $received);
    }

    public function test_remove_action(): void
    {
        $tag = 'rm.action.' . uniqid();
        $this->hook->add_action($tag, function() {});
        $this->assertTrue($this->hook->remove_action($tag));
        $this->assertFalse($this->hook->has_action($tag));
    }

    public function test_remove_nonexistent_action(): void
    {
        $this->assertFalse($this->hook->remove_action('nonexistent.' . uniqid()));
    }

    public function test_add_filter_and_apply(): void
    {
        $tag = 'test.filter.' . uniqid();
        $this->hook->add_filter($tag, function($value) {
            return strtoupper($value);
        });
        $result = $this->hook->apply_filters($tag, 'hello');
        $this->assertSame('HELLO', $result);
    }

    public function test_filter_chain(): void
    {
        $tag = 'chain.filter.' . uniqid();
        $this->hook->add_filter($tag, function($val) { return $val . ' world'; }, 10);
        $this->hook->add_filter($tag, function($val) { return $val . '!'; }, 20);
        $result = $this->hook->apply_filters($tag, 'hello');
        $this->assertSame('hello world!', $result);
    }

    public function test_has_filter(): void
    {
        $tag = 'has.filter.' . uniqid();
        $this->assertFalse($this->hook->has_filter($tag));
        $this->hook->add_filter($tag, function($v) { return $v; });
        $this->assertTrue($this->hook->has_filter($tag));
    }

    public function test_remove_filter(): void
    {
        $tag = 'rm.filter.' . uniqid();
        $this->hook->add_filter($tag, function($v) { return $v; });
        $this->assertTrue($this->hook->remove_filter($tag));
        $this->assertFalse($this->hook->has_filter($tag));
    }

    public function test_apply_filters_no_filter_returns_original(): void
    {
        $result = $this->hook->apply_filters('nofilter.' . uniqid(), 'original');
        $this->assertSame('original', $result);
    }

    public function test_accepted_args_limit(): void
    {
        $tag = 'limited.action.' . uniqid();
        $received = [];
        $this->hook->add_action($tag, function($a) use (&$received) {
            $received[] = $a;
        }, 10, 1);
        $this->hook->do_action($tag, 'first', 'second');
        $this->assertSame(['first'], $received);
    }
}
