<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

/**
 * Markup that is safe to print. Anything else is escaped, so a template can only
 * print raw HTML built through this class.
 */
final class Html implements \Stringable
{
    private const VOID = ['br', 'hr', 'img', 'input', 'meta', 'link'];

    private function __construct(private readonly string $html) {}

    public static function raw(string $html): self
    {
        return new self($html);
    }

    /** @var (\Closure(string): string)|null */
    private static ?\Closure $text_filter = null;

    /**
     * Passes every text and attribute value through $filter before it is escaped;
     * markup is never filtered, so a mask can't break a tag.
     *
     * @template T
     * @param \Closure(string): string $filter
     * @param \Closure(): T $render
     * @return T
     */
    public static function filtered(\Closure $filter, \Closure $render): mixed
    {
        $previous = self::$text_filter;
        self::$text_filter = $filter;
        try {
            return $render();
        } finally {
            self::$text_filter = $previous;
        }
    }

    public static function escape(string|int|float $text): string
    {
        $text = (string)$text;
        if (self::$text_filter !== null) {
            $text = (self::$text_filter)($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Attributes: class is a string or a list whose falsy entries are dropped;
     * vars sets CSS custom properties (['w' => '40%'] sets --w); null and false skip
     * an attribute, true writes it without a value. Null and false children are skipped.
     *
     * @param array<string, mixed> $attrs
     */
    public static function el(string $tag, array $attrs = [], mixed ...$children): self
    {
        $html = '<' . $tag . self::attrs($attrs) . '>';
        if (in_array($tag, self::VOID, true)) {
            return new self($html);
        }

        return new self($html . self::join(...$children)->html . '</' . $tag . '>');
    }

    public static function join(mixed ...$children): self
    {
        $html = '';
        foreach ($children as $child) {
            $html .= match (true) {
                $child === null, $child === false => '',
                $child instanceof self => $child->html,
                is_array($child) => self::join(...array_values($child))->html,
                $child instanceof \BackedEnum => self::escape($child->value),
                default => self::escape($child),
            };
        }

        return new self($html);
    }

    /** @param array<string, mixed> $attrs */
    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($name === 'class' && is_array($value)) {
                $value = implode(' ', array_filter($value)) ?: null;
            } elseif ($name === 'vars' && is_array($value)) {
                $name = 'style';
                $value = self::vars($value);
            }
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            if ($value === null || $value === false) {
                continue;
            }
            $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . self::escape($value) . '"';
        }

        return $out;
    }

    /** @param array<string, string|int|float|null> $vars */
    private static function vars(array $vars): ?string
    {
        $style = [];
        foreach ($vars as $name => $value) {
            if ($value !== null) {
                $style[] = '--' . $name . ':' . $value;
            }
        }

        return $style ? implode(';', $style) : null;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
