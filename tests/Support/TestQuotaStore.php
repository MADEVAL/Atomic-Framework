<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\Quota\QuotaKeyBuilder;
use Engine\Atomic\Quota\QuotaPacingWindow;
use Engine\Atomic\Quota\QuotaResult;
use Engine\Atomic\Quota\Interfaces\QuotaStoreInterface;

/**
 * In-memory store. Mirrors the Lua scripts, including the order of the checks
 * inside quota_reserve().
 */
final class TestQuotaStore implements QuotaStoreInterface
{
    /** @var array<string, array{value: int|string, expires_at: int|null}> */
    private array $values = [];
    /** @var array<string, array<string, float>> member => score */
    private array $zsets = [];
    /** @var array<string, array{cost: int, pacing_keys: list<string>, epoch: string, member: string, expires_at: int}> */
    private array $reservations = [];
    private int $now = 1_000;
    private int $member_seq = 0;

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function clear(string $key): void
    {
        unset($this->values[$key], $this->reservations[$key], $this->zsets[$key]);
    }

    public function clear_scope(string $scope): void
    {
        unset(
            $this->values[QuotaKeyBuilder::balance_key($scope)],
            $this->values[QuotaKeyBuilder::epoch_key($scope)]
        );

        $pace = QuotaKeyBuilder::pacing_prefix($scope);
        $resv = QuotaKeyBuilder::reservation_prefix($scope);
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $pace) || str_starts_with($key, $resv)) {
                unset($this->values[$key]);
            }
        }
        foreach (array_keys($this->zsets) as $key) {
            if (str_starts_with($key, $pace)) {
                unset($this->zsets[$key]);
            }
        }
        foreach (array_keys($this->reservations) as $key) {
            if (str_starts_with($key, $resv)) {
                unset($this->reservations[$key]);
            }
        }
    }

    public function get(string $key): int
    {
        $this->purge_value($key);

        return (int)($this->values[$key]['value'] ?? 0);
    }

    public function get_string(string $key): string
    {
        $this->purge_value($key);
        if (!isset($this->values[$key])) {
            return '';
        }

        return (string)$this->values[$key]['value'];
    }

    public function ttl(string $key): int
    {
        $this->purge_value($key);
        $expires_at = $this->values[$key]['expires_at'] ?? null;

        return $expires_at === null ? 0 : max(0, $expires_at - $this->now);
    }

    public function exists(string $key): bool
    {
        $this->purge_value($key);

        return isset($this->values[$key]);
    }

    public function has_reservation(string $key): bool
    {
        $this->purge_reservation($key);

        return isset($this->reservations[$key]);
    }

    public function zcard(string $key): int
    {
        return count($this->zsets[$key] ?? []);
    }

    /** Summed cost of live reservations whose key starts with $prefix. */
    public function outstanding(string $prefix = '{'): int
    {
        $sum = 0;
        foreach (array_keys($this->reservations) as $key) {
            $this->purge_reservation($key);
            if (isset($this->reservations[$key]) && str_starts_with($key, $prefix)) {
                $sum += $this->reservations[$key]['cost'];
            }
        }

        return $sum;
    }

    /** Persist a value with no TTL, matching Redis SET without EXPIRE. */
    public function persist(string $key, int $value): void
    {
        $this->values[$key] = ['value' => $value, 'expires_at' => null];
    }

    /** Summed cost of every member of a pacing key. Test-only helper. */
    public function zset_weight(string $key): int
    {
        $used = 0;
        foreach (array_keys($this->zsets[$key] ?? []) as $member) {
            $used += (int)substr((string)$member, (int)strrpos((string)$member, ':') + 1);
        }

        return $used;
    }

    public function set_quota(string $balance_key, string $epoch_key, int $credits, int $ttl): int
    {
        $this->values[$balance_key] = ['value' => $credits, 'expires_at' => $this->now + $ttl];
        $this->values[$epoch_key] = [
            'value' => bin2hex(random_bytes(16)),
            'expires_at' => null,
        ];

        return $credits;
    }

    public function add_quota(string $balance_key, int $credits, int $ttl): int
    {
        $this->purge_value($balance_key);
        $existed = isset($this->values[$balance_key]);
        $value = ($this->values[$balance_key]['value'] ?? 0) + $credits;
        // A top-up never moves the end of the period. See add_quota.lua.
        $expires_at = $existed ? $this->values[$balance_key]['expires_at'] : null;
        $this->values[$balance_key] = [
            'value' => $value,
            'expires_at' => $expires_at ?? $this->now + $ttl,
        ];

        return $value;
    }

    public function quota_reserve(
        string $balance_key,
        string $epoch_key,
        string $reservation_key,
        string $reservation_id,
        int $cost,
        int $reservation_ttl,
        array $pacing,
        int $now
    ): QuotaResult {
        $this->purge_value($balance_key);
        $this->purge_value($epoch_key);
        $this->purge_reservation($reservation_key);

        if ($cost < 0) {
            throw new \InvalidArgumentException('cost must not be negative');
        }

        $seen = [];
        foreach ($pacing as $window) {
            if (isset($seen[$window->key])) {
                throw new \InvalidArgumentException('duplicate pacing key');
            }
            $seen[$window->key] = true;
        }

        if (isset($this->reservations[$reservation_key])) {
            $balance = (int)($this->values[$balance_key]['value'] ?? 0);

            return new QuotaResult(false, QuotaResult::REASON_DUPLICATE_RESERVATION, $balance);
        }

        $balance = (int)($this->values[$balance_key]['value'] ?? 0);

        if ($balance < $cost) {
            $retry_after = $this->ttl($balance_key) ?: null;

            return new QuotaResult(false, QuotaResult::REASON_INSUFFICIENT_BALANCE, $balance, $retry_after);
        }

        foreach ($pacing as $window) {
            $verdict = $this->check_window($window, $cost);
            if ($verdict !== null) {
                return new QuotaResult(false, QuotaResult::REASON_PACING, $balance, $verdict, $window->key);
            }
        }

        $member = $reservation_id . ':' . (++$this->member_seq) . ':' . $cost;
        $keys = [];
        foreach ($pacing as $window) {
            $this->zsets[$window->key][$member] = (float)$this->now;
            $keys[] = $window->key;
        }

        $this->values[$balance_key]['value'] = $balance - $cost;
        $this->reservations[$reservation_key] = [
            'cost' => $cost,
            'pacing_keys' => $keys,
            'epoch' => (string)($this->values[$epoch_key]['value'] ?? ''),
            'member' => $member,
            'expires_at' => $this->now + $reservation_ttl,
        ];

        return new QuotaResult(true, QuotaResult::REASON_OK, $balance - $cost);
    }

    public function quota_settle(string $balance_key, string $reservation_key): int
    {
        $this->purge_value($balance_key);
        unset($this->reservations[$reservation_key]);

        return (int)($this->values[$balance_key]['value'] ?? 0);
    }

    public function quota_release(string $balance_key, string $epoch_key, string $reservation_key, string $reservation_id): int
    {
        $this->purge_value($balance_key);
        $this->purge_value($epoch_key);
        $this->purge_reservation($reservation_key);

        if (!isset($this->reservations[$reservation_key])) {
            return (int)($this->values[$balance_key]['value'] ?? 0);
        }

        $record = $this->reservations[$reservation_key];
        unset($this->reservations[$reservation_key]);

        foreach ($record['pacing_keys'] as $key) {
            unset($this->zsets[$key][$record['member']]);
        }

        if ($record['epoch'] !== (string)($this->values[$epoch_key]['value'] ?? '')) {
            return (int)($this->values[$balance_key]['value'] ?? 0);
        }
        if (!isset($this->values[$balance_key])) {
            return 0;
        }

        $this->values[$balance_key]['value'] += $record['cost'];

        return (int)$this->values[$balance_key]['value'];
    }

    /** @return int|null the retry_after when denied, null when the window has room */
    private function check_window(QuotaPacingWindow $window, int $cost): ?int
    {
        $key = $window->key;
        $min = $this->now - $window->window;
        foreach ($this->zsets[$key] ?? [] as $member => $score) {
            if ($score <= $min) {
                unset($this->zsets[$key][$member]);
            }
        }

        $used = $this->zset_weight($key);
        if ($used + $cost <= $window->limit) {
            return null;
        }

        $scores = array_values($this->zsets[$key] ?? []);
        if ($scores === []) {
            return $window->window;
        }

        return max(1, (int)ceil(min($scores) + $window->window - $this->now));
    }

    private function purge_value(string $key): void
    {
        $expires_at = $this->values[$key]['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at <= $this->now) {
            unset($this->values[$key]);
        }
    }

    private function purge_reservation(string $key): void
    {
        $expires_at = $this->reservations[$key]['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at <= $this->now) {
            unset($this->reservations[$key]);
        }
    }
}
