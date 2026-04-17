<?php
declare(strict_types=1);

namespace Tests\Engine\Theme;

use Engine\Atomic\Theme\Assets;
use PHPUnit\Framework\TestCase;

class AssetsTest extends TestCase
{
    private Assets $assets;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Assets::class);
        $prop = $ref->getProperty('instance');        $prop->setValue(null, null);

        $this->assets = Assets::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame($this->assets, Assets::instance());
    }

    public function test_enqueue_style(): void
    {
        $this->assets->enqueue_style('my-style', '/css/style.css');
        
        $ref = new \ReflectionClass($this->assets);
        $styles = $ref->getProperty('styles');        $val = $styles->getValue($this->assets);

        $this->assertArrayHasKey('my-style', $val);
        $this->assertSame('/css/style.css', $val['my-style']['src']);
        $this->assertSame('all', $val['my-style']['media']);
    }

    public function test_enqueue_script(): void
    {
        $this->assets->enqueue_script('my-script', '/js/app.js');

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertArrayHasKey('my-script', $val);
        $this->assertSame('/js/app.js', $val['my-script']['src']);
        $this->assertTrue($val['my-script']['inFooter']);
    }

    public function test_enqueue_script_in_header(): void
    {
        $this->assets->enqueue_script('header-script', '/js/header.js', [], null, false);

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertFalse($val['header-script']['inFooter']);
    }

    public function test_dequeue_style(): void
    {
        $this->assets->enqueue_style('removable', '/css/remove.css');
        $this->assets->dequeue_style('removable');

        $ref = new \ReflectionClass($this->assets);
        $styles = $ref->getProperty('styles');        $val = $styles->getValue($this->assets);

        $this->assertArrayNotHasKey('removable', $val);
    }

    public function test_dequeue_script(): void
    {
        $this->assets->enqueue_script('removable', '/js/remove.js');
        $this->assets->dequeue_script('removable');

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertArrayNotHasKey('removable', $val);
    }

    public function test_localize_script(): void
    {
        $this->assets->enqueue_script('loc', '/js/loc.js');
        $this->assets->localize_script('loc', ['foo' => 'bar'], 'myVar');

        $ref = new \ReflectionClass($this->assets);
        $localize = $ref->getProperty('localize');        $val = $localize->getValue($this->assets);

        $this->assertArrayHasKey('loc', $val);
        $this->assertSame('myVar', $val['loc']['var']);
        $this->assertSame(['foo' => 'bar'], $val['loc']['data']);
    }

    public function test_add_inline_style(): void
    {
        $this->assets->add_inline_style('custom', 'body { color: red; }');

        $ref = new \ReflectionClass($this->assets);
        $inline = $ref->getProperty('inlineStyles');        $val = $inline->getValue($this->assets);

        $this->assertArrayHasKey('custom', $val);
        $this->assertSame('body { color: red; }', $val['custom'][0]);
    }

    public function test_add_inline_script(): void
    {
        $this->assets->add_inline_script('jsinline', 'console.log("test");', 'footer');

        $ref = new \ReflectionClass($this->assets);
        $inline = $ref->getProperty('inlineScripts');        $val = $inline->getValue($this->assets);

        $this->assertArrayHasKey('jsinline', $val['footer']);
        $this->assertSame('console.log("test");', $val['footer']['jsinline'][0]);
    }

    public function test_set_script_attrs(): void
    {
        $this->assets->enqueue_script('attrs', '/js/attrs.js', [], null, true, ['defer' => true]);
        $this->assets->set_script_attrs('attrs', ['type' => 'module']);

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertArrayHasKey('type', $val['attrs']['attrs']);
        $this->assertSame('module', $val['attrs']['attrs']['type']);
    }

    public function test_enqueue_preset_jquery(): void
    {
        $this->assets->enqueue_preset('jquery');

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertArrayHasKey('jquery', $val);
        $this->assertStringContainsString('jquery', $val['jquery']['src']);
    }

    public function test_enqueue_preset_unknown(): void
    {
        // Should not throw
        $this->assets->enqueue_preset('nonexistent');

        $ref = new \ReflectionClass($this->assets);
        $scripts = $ref->getProperty('scripts');        $val = $scripts->getValue($this->assets);

        $this->assertEmpty($val);
    }

    public function test_duplicate_enqueue_ignored(): void
    {
        $this->assets->enqueue_style('dup', '/css/dup.css');
        
        // Mark as loaded
        $ref = new \ReflectionClass($this->assets);
        $loaded = $ref->getProperty('loaded');        $loadedVal = $loaded->getValue($this->assets);
        $loadedVal['styles'][] = 'dup2';
        $loaded->setValue($this->assets, $loadedVal);

        // Enqueue same handle again should not add
        $this->assets->enqueue_style('dup2', '/css/different.css');

        $styles = $ref->getProperty('styles');        $val = $styles->getValue($this->assets);

        $this->assertArrayNotHasKey('dup2', $val);
    }

    public function test_enqueue_style_with_deps(): void
    {
        $this->assets->enqueue_style('dependent', '/css/dep.css', ['base']);

        $ref = new \ReflectionClass($this->assets);
        $styles = $ref->getProperty('styles');        $val = $styles->getValue($this->assets);

        $this->assertSame(['base'], $val['dependent']['deps']);
    }

    public function test_print_styles_outputs_html(): void
    {
        $this->assets->enqueue_style('print-test', 'https://example.com/style.css');

        ob_start();
        $this->assets->print_styles();
        $output = ob_get_clean();

        $this->assertStringContainsString('<link rel="stylesheet"', $output);
        $this->assertStringContainsString('https://example.com/style.css', $output);
    }
}
