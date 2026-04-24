<?php
declare(strict_types=1);

namespace Engine\Atomic\Plugins\Monopay\Enums;

if (!defined('ATOMIC_START')) exit;

enum PaymentStatus: string
{
    case CREATED    = 'created';
    case PROCESSING = 'processing';
    case HOLD       = 'hold';
    case SUCCESS    = 'success';
    case FAILURE    = 'failure';
    case REVERSED   = 'reversed';
    case EXPIRED    = 'expired';

    public static function pending_values(): array
    {
        return [
            self::CREATED->value,
            self::PROCESSING->value,
        ];
    }

    public function label_key(): string
    {
        return match ($this) {
            self::CREATED    => 'payment.status.created',
            self::PROCESSING => 'payment.status.processing',
            self::HOLD       => 'payment.status.hold',
            self::SUCCESS    => 'payment.status.success',
            self::FAILURE    => 'payment.status.failure',
            self::REVERSED   => 'payment.status.reversed',
            self::EXPIRED    => 'payment.status.expired',
        };
    }

    public function badge_class(): string
    {
        return match ($this) {
            self::SUCCESS    => 'success',
            self::FAILURE    => 'danger',
            self::HOLD       => 'warning',
            self::PROCESSING => 'info',
            self::CREATED    => 'secondary',
            self::REVERSED   => 'warning',
            self::EXPIRED    => 'secondary',
        };
    }

    public static function is_successful_status(string|self $status): bool
    {
        return self::normalize($status) === self::SUCCESS;
    }

    public static function is_pending_status(string|self $status): bool
    {
        return in_array((self::normalize($status)?->value), self::pending_values(), true);
    }

    public static function is_failed_status(string|self $status): bool
    {
        return self::normalize($status) === self::FAILURE;
    }

    public static function is_hold_status(string|self $status): bool
    {
        return self::normalize($status) === self::HOLD;
    }

    public static function status_weight(string|self $status): int
    {
        return match (self::normalize($status)) {
            self::CREATED    => 0,
            self::PROCESSING => 1,
            self::HOLD       => 2,
            self::SUCCESS, self::FAILURE, self::EXPIRED => 3,
            self::REVERSED   => 4,
            default          => -1,
        };
    }

    public static function is_valid_status_transition(string|self $from, string|self $to): bool
    {
        $from_status = self::normalize($from);
        $to_status = self::normalize($to);

        if (!$from_status || !$to_status) return false;
        if ($from_status === $to_status) return true;
        if (self::status_weight($to_status) < self::status_weight($from_status)) return false;
        if ($from_status === self::SUCCESS && $to_status !== self::REVERSED) return false;
        if (in_array($from_status, [self::FAILURE, self::EXPIRED], true)) return false;
        
        return true;
    }

    private static function normalize(string|self $status): ?self
    {
        return $status instanceof self ? $status : self::tryFrom($status);
    }
}