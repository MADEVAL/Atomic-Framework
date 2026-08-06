<?php
declare(strict_types=1);

namespace Tests\Engine\Hook;

use Engine\Atomic\Hook\Shortcode;
use PHPUnit\Framework\TestCase;

class ShortcodeTest extends TestCase
{
    private Shortcode $sc;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Shortcode::class);
        $prop = $ref->getProperty('instance');        $prop->setValue(null, null);

        $handlers = $ref->getProperty('handlers');
        $this->sc = Shortcode::instance();
        $handlers->setValue($this->sc, []);
    }

    public function test_singleton(): void
    {
        $this->assertSame($this->sc, Shortcode::instance());
    }

    public function test_simple_shortcode(): void
    {
        $this->sc->add_shortcode('hello', function ($atts, $content) {
            return 'Hello World';
        });

        $result = $this->sc->do_shortcode('Before [hello] After');
        $this->assertSame('Before Hello World After', $result);
    }

    public function test_shortcode_with_attributes(): void
    {
        $this->sc->add_shortcode('greet', function ($atts, $content) {
            return 'Hello ' . ($atts['name'] ?? 'Unknown');
        });

        $result = $this->sc->do_shortcode('[greet name="John"]');
        $this->assertSame('Hello John', $result);
    }

    public function test_shortcode_with_content(): void
    {
        $this->sc->add_shortcode('bold', function ($atts, $content) {
            return '<b>' . $content . '</b>';
        });

        $result = $this->sc->do_shortcode('[bold]text[/bold]');
        $this->assertSame('<b>text</b>', $result);
    }

    public function test_shortcode_with_attrs_and_content(): void
    {
        $this->sc->add_shortcode('link', function ($atts, $content) {
            $href = $atts['href'] ?? '#';
            return '<a href="' . $href . '">' . $content . '</a>';
        });

        $result = $this->sc->do_shortcode('[link href="https://example.com"]Click[/link]');
        $this->assertSame('<a href="https://example.com">Click</a>', $result);
    }

    public function test_no_handlers_returns_original(): void
    {
        $text = 'No shortcodes here';
        $this->assertSame($text, $this->sc->do_shortcode($text));
    }

    public function test_empty_text_returns_empty(): void
    {
        $this->sc->add_shortcode('test', fn() => 'x');
        $this->assertSame('', $this->sc->do_shortcode(''));
    }

    public function test_remove_shortcode(): void
    {
        $this->sc->add_shortcode('removable', fn() => 'REPLACED');
        $this->sc->remove_shortcode('removable');

        $result = $this->sc->do_shortcode('[removable]');
        $this->assertSame('[removable]', $result);
    }

    public function test_multiple_shortcodes(): void
    {
        $this->sc->add_shortcode('a', fn() => 'A');
        $this->sc->add_shortcode('b', fn() => 'B');

        $result = $this->sc->do_shortcode('[a] and [b]');
        $this->assertSame('A and B', $result);
    }

    public function test_shortcode_single_quoted_attr(): void
    {
        $this->sc->add_shortcode('tag', function ($atts) {
            return $atts['val'] ?? 'none';
        });

        $result = $this->sc->do_shortcode("[tag val='test']");
        $this->assertSame('test', $result);
    }

    public function test_shortcode_unquoted_attr(): void
    {
        $this->sc->add_shortcode('tag', function ($atts) {
            return $atts['num'] ?? '0';
        });

        $result = $this->sc->do_shortcode('[tag num=42]');
        $this->assertSame('42', $result);
    }

    public function test_unknown_shortcode_left_in_text(): void
    {
        $this->sc->add_shortcode('known', fn() => 'OK');
        
        $result = $this->sc->do_shortcode('[known] and [unknown]');
        $this->assertSame('OK and [unknown]', $result);
    }
}
