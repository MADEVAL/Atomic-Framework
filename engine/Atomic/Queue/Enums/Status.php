<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Enums;

if (!defined( 'ATOMIC_START' ) ) exit;

enum Status: string
{
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case IN_PROGRESS = 'in_progress';

    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function filterable_statuses(): array
    {
        return [
            self::FAILED->value,
            self::COMPLETED->value,
            self::PENDING->value,
            self::RUNNING->value,
        ];
    }

    public static function pending_like(): array
    {
        return [
            self::PENDING->value,
            self::QUEUED->value,
        ];
    }

    public static function totals_template(int $total = 0): array
    {
        return [
            self::FAILED->value => 0,
            self::QUEUED->value => 0,
            self::PENDING->value => 0,
            self::RUNNING->value => 0,
            self::COMPLETED->value => 0,
            'total' => $total,
        ];
    }

    public static function aggregate(string $status): string
    {
        return match ($status) {
            self::PENDING->value, self::QUEUED->value => self::QUEUED->value,
            self::IN_PROGRESS->value => self::RUNNING->value,
            default => $status,
        };
    }
}
