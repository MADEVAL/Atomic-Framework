<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

/**
 * Builds every Redis key the quota mechanism uses.
 *
 * Pure functions only — no state, no store access. Every key carries the
 * scope inside a hash tag, so one Lua script never spans two cluster slots.
 * The driver adds its own prefix on top — never add it here.
 */
final class QuotaKeyBuilder
{
    private const KEY_BALANCE = '{%s}.balance';
    private const KEY_EPOCH = '{%s}.epoch';
    private const KEY_RESERVATION = '{%s}.reservation.%s';
    private const KEY_PACING = '{%s}.pacing.%s.%d';

    private const PREFIX_RESERVATION = '{%s}.reservation.';
    private const PREFIX_PACING = '{%s}.pacing.';

    public static function balance_key(string $scope): string
    {
        return sprintf(self::KEY_BALANCE, $scope);
    }

    public static function epoch_key(string $scope): string
    {
        return sprintf(self::KEY_EPOCH, $scope);
    }

    public static function reservation_key(string $scope, string $reservation_id): string
    {
        return sprintf(self::KEY_RESERVATION, $scope, $reservation_id);
    }

    /** Every reservation key of one scope starts with this. */
    public static function reservation_prefix(string $scope): string
    {
        return sprintf(self::PREFIX_RESERVATION, $scope);
    }

    /** Every pacing key of one scope starts with this. */
    public static function pacing_prefix(string $scope): string
    {
        return sprintf(self::PREFIX_PACING, $scope);
    }

    /**
     * Tier windows and operation windows merged. All of them must pass.
     *
     * @return list<QuotaPacingWindow>
     */
    public static function pacing_keys(string $scope, QuotaPlan $plan, string $operation): array
    {
        $pacing = [];
        foreach ($plan->pacing as $window) {
            $pacing[] = new QuotaPacingWindow(
                sprintf(self::KEY_PACING, $scope, QuotaPlan::TIER, $window->window),
                $window->limit,
                $window->window
            );
        }

        foreach ($plan->operation_pacing($operation) as $window) {
            $pacing[] = new QuotaPacingWindow(
                sprintf(self::KEY_PACING, $scope, $operation, $window->window),
                $window->limit,
                $window->window
            );
        }

        return $pacing;
    }
}
