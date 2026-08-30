<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota\Interfaces;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Quota\QuotaPacingWindow;
use Engine\Atomic\Quota\QuotaResult;

interface QuotaStoreInterface
{
    public function clear(string $key): void;

    /**
     * Drop every key that belongs to one scope: balance, epoch, pacing, and
     * live reservations. Other scopes must stay untouched.
     */
    public function clear_scope(string $scope): void;

    public function get(string $key): int;

    /**
     * Raw string stored at $key. Empty when the key is missing.
     *
     * Use this for the epoch uid. get() is for balances and would collapse a
     * hex id to 0.
     */
    public function get_string(string $key): string;

    public function ttl(string $key): int;

    /**
     * Overwrite the balance, refresh its period, and write a new epoch uid.
     *
     * The epoch never expires. Only clear() / clear_scope() removes it.
     *
     * @return int the new balance
     */
    public function set_quota(string $balance_key, string $epoch_key, int $credits, int $ttl): int;

    /**
     * Add to the balance without moving the end of the period. The TTL is
     * applied only when the balance is absent or carries no expiry.
     *
     * @return int the new balance
     */
    public function add_quota(string $balance_key, int $credits, int $ttl): int;

    /**
     * Check the reservation id, the balance, and every pacing window, then
     * commit. Nothing is written unless every check passes.
     *
     * @param list<QuotaPacingWindow> $pacing
     */
    public function quota_reserve(
        string $balance_key,
        string $epoch_key,
        string $reservation_key,
        string $reservation_id,
        int $cost,
        int $reservation_ttl,
        array $pacing,
        int $now
    ): QuotaResult;

    /**
     * Drop the reservation record and leave the charge standing.
     *
     * @return int the balance after the call
     */
    public function quota_settle(string $balance_key, string $reservation_key): int;

    /**
     * Remove every pacing member the reservation added and refund the cost,
     * but only when the epoch still matches.
     *
     * @return int the balance after the call
     */
    public function quota_release(string $balance_key, string $epoch_key, string $reservation_key, string $reservation_id): int;
}
