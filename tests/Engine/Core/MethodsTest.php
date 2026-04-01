<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Methods;
use PHPUnit\Framework\TestCase;

class MethodsTest extends TestCase
{
    private Methods $m;

    protected function setUp(): void
    {
        Methods::reset();
        $this->m = Methods::instance();
    }

    public function test_singleton_instance(): void
    {
        $this->assertSame(Methods::instance(), Methods::instance());
    }

    public function test_reset_creates_new_instance(): void
    {
        $a = Methods::instance();
        Methods::reset();
        $b = Methods::instance();
        $this->assertNotSame($a, $b);
    }

    public function test_get_publicUrl(): void
    {
        $f3 = \Base::instance();
        $f3->set('DOMAIN', 'https://test.example.com/');
        Methods::reset();
        $this->assertSame('https://test.example.com/', Methods::instance()->get_publicUrl());
    }

    public function test_get_encoding_default(): void
    {
        $enc = $this->m->get_encoding();
        $this->assertIsString($enc);
        $this->assertNotEmpty($enc);
    }

    public function test_get_isCli_returns_true(): void
    {
        $this->assertTrue($this->m->get_isCli());
    }

    public function test_get_userDevice_returns_pc_in_cli(): void
    {
        $this->assertSame('pc', $this->m->get_userDevice());
    }

    public function test_deviceFromUA_detects_tv(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'deviceFromUA');
        $this->assertSame('tv', $ref->invoke($this->m, 'Mozilla/5.0 (SMART-TV; Linux) Tizen/5.0', []));
    }

    public function test_deviceFromUA_detects_tablet(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'deviceFromUA');
        $this->assertSame('tab', $ref->invoke($this->m, 'Mozilla/5.0 (iPad; CPU OS 14_0) AppleWebKit', []));
    }

    public function test_deviceFromUA_detects_phone(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'deviceFromUA');
        $this->assertSame('phone', $ref->invoke($this->m, 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0) Mobile', []));
    }

    public function test_deviceFromUA_defaults_to_pc(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'deviceFromUA');
        $this->assertSame('pc', $ref->invoke($this->m, 'Mozilla/5.0 (Windows NT 10.0) Chrome/91', []));
    }

    public function test_deviceFromUA_empty_ua(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'deviceFromUA');
        $this->assertSame('pc', $ref->invoke($this->m, '', []));
    }

    public function test_is_telegram(): void
    {
        $f3 = \Base::instance();
        $f3->set('AGENT', 'TelegramBot (like TwitterBot)');
        Methods::reset();
        $this->assertTrue(Methods::instance()->is_telegram());
    }

    public function test_is_botblocker(): void
    {
        $f3 = \Base::instance();
        $f3->set('AGENT', 'BotBlocker/Crawler v1.0');
        Methods::reset();
        $this->assertTrue(Methods::instance()->is_botblocker());
    }

    public function test_matchPath(): void
    {
        $ref = new \ReflectionMethod(Methods::class, 'matchPath');

        $this->assertTrue($ref->invoke($this->m, '/about', '/about'));
        $this->assertTrue($ref->invoke($this->m, '/docs/api', '/docs/*'));
        $this->assertTrue($ref->invoke($this->m, '/docs/api/v1', '/docs/*'));
        $this->assertFalse($ref->invoke($this->m, '/about', '/contact'));
        $this->assertTrue($ref->invoke($this->m, '/', '/*'));
        $this->assertFalse($ref->invoke($this->m, '/about', ''));
    }

    public function test_segments(): void
    {
        $f3 = \Base::instance();
        $f3->set('PATH', '/en/docs/api');
        Methods::reset();
        $m = Methods::instance();
        $segs = $m->segments(false);
        $this->assertSame(['en', 'docs', 'api'], $segs);
    }

    public function test_segment(): void
    {
        $f3 = \Base::instance();
        $f3->set('PATH', '/en/docs/api');
        Methods::reset();
        $m = Methods::instance();
        $this->assertSame('en', $m->segment(0, null, false));
        $this->assertSame('docs', $m->segment(1, null, false));
        $this->assertNull($m->segment(10, null, false));
        $this->assertSame('default', $m->segment(10, 'default', false));
    }

    public function test_is_section(): void
    {
        $f3 = \Base::instance();
        $f3->set('PATH', '/docs/api/v1');
        Methods::reset();
        $m = Methods::instance();
        $this->assertTrue($m->is_section('docs', false));
        $this->assertFalse($m->is_section('blog', false));
    }
}
