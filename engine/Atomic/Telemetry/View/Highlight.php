<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\Json;

final class Highlight
{
    private const JSON_TOKEN = '/("(?:\\\\.|[^\\\\"])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?|[{}\[\],]/';

    private const TRACE_FRAME = '/^(#\d+)\s+(?:(.+?)\((\d+)\):\s*)?(.*)$/';

    public static function json(mixed $value): Html
    {
        $text = Json::pretty($value);
        $out = [];
        $last = 0;
        preg_match_all(self::JSON_TOKEN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $match) {
            [$token, $offset] = $match[0];
            $out[] = substr($text, $last, $offset - $last);
            $last = $offset + strlen($token);
            $string = $match[1][0] ?? null;
            $colon = $match[2][0] ?? null;
            $literal = $match[3][0] ?? null;
            if ($string !== null) {
                $out[] = self::span($colon !== null ? 'syn-key' : 'syn-str', $string);
                if ($colon !== null) {
                    $out[] = self::span('syn-punc', $colon);
                }
            } elseif ($literal !== null) {
                $out[] = self::span('syn-bool', $token);
            } elseif (preg_match('/^-?\d/', $token)) {
                $out[] = self::span('syn-num', $token);
            } else {
                $out[] = self::span('syn-punc', $token);
            }
        }
        $out[] = substr($text, $last);

        return Html::join(...$out);
    }

    public static function trace(string $trace): Html
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', $trace) ?: [], static fn (string $line): bool => $line !== ''));
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = $i ? "\n" : '';
            if (!preg_match(self::TRACE_FRAME, $line, $frame, PREG_UNMATCHED_AS_NULL)) {
                $out[] = $line;
                continue;
            }
            [, $number, $file, $line_no, $call] = $frame;
            $out[] = self::span('syn-punc', $number);
            $out[] = ' ';
            $out[] = self::span('syn-key', (string)$call);
            if ($file !== null && $file !== '') {
                $out[] = "\n   ";
                $out[] = self::span('syn-punc', $file . ':' . $line_no);
            }
        }

        return Html::join(...$out);
    }

    private static function span(string $class, string $text): Html
    {
        return Html::el('span', ['class' => $class], $text);
    }
}
