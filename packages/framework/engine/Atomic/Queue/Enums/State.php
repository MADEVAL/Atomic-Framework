<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Enums;

if (!defined( 'ATOMIC_START' ) ) exit;

enum State: string
{
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case PENDING = 'pending';
    case RUNNING = 'running';
    case CANCEL_REQUESTED = 'cancel_requested';
    case CANCELLED = 'cancelled';

    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function filterable_states(): array
    {
        return [
            self::FAILED->value,
            self::COMPLETED->value,
            self::PENDING->value,
            self::RUNNING->value,
            self::CANCEL_REQUESTED->value,
            self::CANCELLED->value,
        ];
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::FAILED->value => 'Failed',
            self::COMPLETED->value => 'Completed',
            self::PENDING->value => 'Pending',
            self::RUNNING->value => 'Running',
            self::CANCEL_REQUESTED->value => 'Cancel requested',
            self::CANCELLED->value => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    public static function icon(string $state): string
    {
        return match ($state) {
            self::FAILED->value => 'x-circle',
            self::COMPLETED->value => 'check-circle',
            self::PENDING->value => 'clock',
            self::RUNNING->value => 'loader',
            self::CANCEL_REQUESTED->value => 'octagon-alert',
            self::CANCELLED->value => 'ban',
            default => 'circle-help',
        };
    }

    public static function badge_class(string $state): string
    {
        return match ($state) {
            self::FAILED->value => 'state-failed',
            self::COMPLETED->value => 'state-completed',
            self::PENDING->value => 'state-pending',
            self::RUNNING->value => 'state-running',
            self::CANCEL_REQUESTED->value => 'state-cancel-requested',
            self::CANCELLED->value => 'state-cancelled',
            default => 'state-unknown',
        };
    }

    public static function display(string $state): array
    {
        return [
            'value' => $state,
            'label' => self::label($state),
            'icon' => self::icon($state),
            'class' => self::badge_class($state),
        ];
    }

    public static function display_map(): array
    {
        $map = [];
        foreach (self::all() as $state) {
            $map[$state] = self::display($state);
        }
        return $map;
    }

    public static function state_totals_template(int $total = 0): array
    {
        return [
            self::FAILED->value => 0,
            self::PENDING->value => 0,
            self::RUNNING->value => 0,
            self::COMPLETED->value => 0,
            self::CANCEL_REQUESTED->value => 0,
            self::CANCELLED->value => 0,
            'total' => $total,
        ];
    }

    public static function aggregate(string $state): string
    {
        return $state;
    }
}
