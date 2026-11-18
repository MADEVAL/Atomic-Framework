<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

final class Fmt
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const KB = 1024;
    private const MB = 1024 * 1024;
    private const GB = 1024 * 1024 * 1024;

    public static function age(int $seconds): string
    {
        return match (true) {
            $seconds >= self::DAY => intdiv($seconds, self::DAY) . 'd ' . intdiv($seconds % self::DAY, self::HOUR) . 'h',
            $seconds >= self::HOUR => intdiv($seconds, self::HOUR) . 'h ' . intdiv($seconds % self::HOUR, self::MINUTE) . 'm',
            $seconds >= self::MINUTE => intdiv($seconds, self::MINUTE) . 'm ' . ($seconds % self::MINUTE) . 's',
            default => $seconds . 's',
        };
    }

    public static function n(int|float $number): string
    {
        return is_int($number) ? number_format($number) : rtrim(rtrim(number_format($number, 3), '0'), '.');
    }

    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= self::GB => sprintf('%.1F GB', $bytes / self::GB),
            $bytes >= self::MB => sprintf('%.1F MB', $bytes / self::MB),
            $bytes >= self::KB => round($bytes / self::KB) . ' KB',
            default => $bytes . ' B',
        };
    }

    public static function run_ms(float $ms): string
    {
        return $ms < 1000 ? (int)round($ms) . ' ms' : self::dur(round($ms / 100) / 10);
    }

    public static function dur(int|float $seconds): string
    {
        if ($seconds < self::MINUTE) {
            return self::plain($seconds) . 's';
        }
        $rest = round(fmod((float)$seconds, self::MINUTE), 1);

        return intdiv((int)$seconds, self::MINUTE) . 'm ' . ($rest < 10 ? '0' : '') . self::plain($rest) . 's';
    }

    public static function utc(int|\DateTimeInterface|null $time): string
    {
        $date = self::date($time);

        return $date === null ? '-' : $date->format('Y-m-d H:i:s') . ' UTC';
    }

    public static function short(int|\DateTimeInterface|null $time, ?int $now = null): string
    {
        $date = self::date($time);
        if ($date === null) {
            return '-';
        }
        $today = gmdate('Y-m-d', $now ?? time());

        return $date->format($date->format('Y-m-d') === $today ? 'H:i' : 'M j, H:i');
    }

    private static function date(int|\DateTimeInterface|null $time): ?\DateTimeImmutable
    {
        if ($time === null) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');

        return $time instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($time)->setTimezone($utc)
            : (new \DateTimeImmutable('@' . $time))->setTimezone($utc);
    }

    private static function plain(int|float $number): string
    {
        return is_int($number) || floor($number) !== $number ? (string)$number : (string)(int)$number;
    }
}
