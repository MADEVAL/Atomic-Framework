<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function test_text_children_and_attributes_are_escaped(): void
    {
        $html = Html::el('span', ['title' => '"x" & <y>'], '<script>alert(1)</script>');

        self::assertSame('<span title="&quot;x&quot; &amp; &lt;y&gt;">&lt;script&gt;alert(1)&lt;/script&gt;</span>', (string)$html);
    }

    public function test_html_children_are_printed_as_is(): void
    {
        self::assertSame('<div><b>bold</b></div>', (string)Html::el('div', [], Html::el('b', [], 'bold')));
    }

    public function test_class_lists_drop_empty_entries(): void
    {
        self::assertSame('<i class="a c"></i>', (string)Html::el('i', ['class' => ['a', null, false, 'c']]));
        self::assertSame('<i></i>', (string)Html::el('i', ['class' => [null, false]]));
    }

    public function test_null_and_false_skip_attributes_and_true_writes_a_bare_one(): void
    {
        self::assertSame('<input data-search>', (string)Html::el('input', ['data-search' => true, 'title' => null, 'hidden' => false]));
    }

    public function test_vars_become_css_custom_properties(): void
    {
        self::assertSame('<span style="--w:40%;--h:3"></span>', (string)Html::el('span', ['vars' => ['w' => '40%', 'h' => 3, 'skip' => null]]));
    }

    public function test_children_flatten_and_skip_null_and_false_but_keep_zero(): void
    {
        self::assertSame('<p>a0bc</p>', (string)Html::el('p', [], 'a', 0, null, false, ['b', ['c']]));
    }

    public function test_backed_enums_print_their_value(): void
    {
        self::assertSame('<i data-tone="bad">bad</i>', (string)Html::el('i', ['data-tone' => Tone::Bad], Tone::Bad));
    }

    public function test_a_text_filter_masks_texts_and_attributes_but_never_markup(): void
    {
        // Masking the finished HTML turned "Cookie:</b><span>" into "Cookie:[MASKED]", breaking the markup.
        $html = Html::filtered(
            static fn (string $text): string => (string)\Engine\Atomic\Core\Redactor::redact_secrets($text),
            static fn (): Html => Html::el('div', ['data-copy' => 'token=abc123'], Html::el('b', [], 'Cookie:'), Html::el('span', ['class' => 's'], '"[MASKED]"')),
        );

        self::assertSame('<div data-copy="token=[MASKED]"><b>Cookie:</b><span class="s">&quot;[MASKED]&quot;</span></div>', (string)$html);
        self::assertSame('token=abc123', Html::escape('token=abc123'));
    }
}
