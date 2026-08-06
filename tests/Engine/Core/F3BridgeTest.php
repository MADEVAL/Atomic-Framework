<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\F3Bridge;
use PHPUnit\Framework\TestCase;

final class F3BridgeTest extends TestCase
{
    private \Base $base;
    private F3Bridge $bridge;

    protected function setUp(): void
    {
        $this->base = \Base::instance();
        $this->bridge = new F3Bridge($this->base);
    }

    public function test_base_returns_f3_instance(): void
    {
        $bridge = new F3Bridge($this->base);
        $this->assertSame($this->base, $bridge->base());
    }

    public function test_construct_without_base_creates_default(): void
    {
        $bridge = new F3Bridge();
        $this->assertInstanceOf(\Base::class, $bridge->base());
    }

    public function test_get_proxies_to_base(): void
    {
        $this->base->set('BRIDGE_TEST_KEY', 'hello');
        $this->assertSame('hello', $this->bridge->get('BRIDGE_TEST_KEY'));
        $this->base->clear('BRIDGE_TEST_KEY');
    }

    public function test_set_proxies_to_base(): void
    {
        $this->bridge->set('BRIDGE_TEST_KEY', 'world');
        $this->assertSame('world', $this->base->get('BRIDGE_TEST_KEY'));
        $this->base->clear('BRIDGE_TEST_KEY');
    }

    public function test_clear_proxies_to_base(): void
    {
        $this->base->set('BRIDGE_TEST_KEY', 'temp');
        $this->bridge->clear('BRIDGE_TEST_KEY');
        $this->assertFalse($this->base->exists('BRIDGE_TEST_KEY'));
    }

    public function test_exists_proxies_to_base(): void
    {
        $this->base->set('BRIDGE_EXISTS_KEY', 1);
        $this->assertTrue($this->bridge->exists('BRIDGE_EXISTS_KEY'));
        $this->base->clear('BRIDGE_EXISTS_KEY');
    }

    public function test_hive_returns_array(): void
    {
        $hive = $this->bridge->hive();
        $this->assertIsArray($hive);
    }

    public function test_mset_proxies_to_base(): void
    {
        $this->bridge->mset(['BRIDGE_A' => 1, 'BRIDGE_B' => 2]);
        $this->assertSame(1, $this->base->get('BRIDGE_A'));
        $this->assertSame(2, $this->base->get('BRIDGE_B'));
        $this->base->clear('BRIDGE_A');
        $this->base->clear('BRIDGE_B');
    }

    public function test_ref_proxies_to_base(): void
    {
        $this->base->set('BRIDGE_REF', 42);
        $this->assertSame(42, $this->bridge->ref('BRIDGE_REF'));
        $this->base->clear('BRIDGE_REF');
    }

    public function test_sync_proxies_to_base(): void
    {
        // Just verify it doesn't error
        $this->bridge->sync('BRIDGE_SYNC');
        $this->assertTrue(true);
    }

    public function test_no_magic_call(): void
    {
        $this->assertFalse(method_exists($this->bridge, '__call'));
    }
}
