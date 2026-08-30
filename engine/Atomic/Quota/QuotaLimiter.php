<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Quota\Drivers\Redis;
use Engine\Atomic\Quota\Exceptions\QuotaExceededException;
use Engine\Atomic\Quota\Interfaces\QuotaStoreInterface;

final class QuotaLimiter
{
    public const DRIVER_REDIS = 'redis';
    public const CONFIG_ROOT  = QuotaSubjectResolver::CONFIG_ROOT;
    public const FAIL_OPEN    = 'open';

    public const CONFIG_QUOTAS     = QuotaSubjectResolver::CONFIG_QUOTAS;
    public const CONFIG_OPERATIONS = QuotaSubjectResolver::CONFIG_OPERATIONS;

    private static ?QuotaStoreInterface $configured_store = null;

    public function __construct(private QuotaStoreInterface $store) {}

    public static function from_config(): self
    {
        if (self::$configured_store === null) {
            self::$configured_store = new Redis();
        }
        return new self(self::$configured_store);
    }

    /**
     * Override the store every later from_config() call hands out. Lets an
     * application swap the driver at boot, and lets tests run without Redis.
     */
    public static function use_store(QuotaStoreInterface $store): void
    {
        self::$configured_store = $store;
    }

    /** Drops the configured store and both resolvers. Intended for tests. */
    public static function reset(): void
    {
        self::$configured_store = null;
        QuotaSubjectResolver::reset();
    }

    public function store(): QuotaStoreInterface
    {
        return $this->store;
    }

    // ── Balance — the application owns it ────────────────────────────────────

    /** Renewal. Overwrites the balance, so nothing rolls over, and writes a new epoch uid. */
    public function quota_set(string $scope, int $credits, int $ttl): int
    {
        return $this->store->set_quota(QuotaKeyBuilder::balance_key($scope), QuotaKeyBuilder::epoch_key($scope), $credits, $ttl);
    }

    /** Top-up. Adds credit and leaves the end of the period where it is. */
    public function quota_add(string $scope, int $credits, int $ttl): int
    {
        return $this->store->add_quota(QuotaKeyBuilder::balance_key($scope), $credits, $ttl);
    }

    public function quota_get(string $scope): int
    {
        return $this->store->get(QuotaKeyBuilder::balance_key($scope));
    }

    public function quota_ttl(string $scope): int
    {
        return $this->store->ttl(QuotaKeyBuilder::balance_key($scope));
    }

    public function quota_clear(string $scope): void
    {
        $this->store->clear_scope($scope);
    }

    // ── Config resolver ──────────────────────────────────────────────────────

    /**
     * Turn a tier name into a plan. Reads config, touches no store, enforces
     * nothing.
     */
    public function plan(string $name): QuotaPlan
    {
        return QuotaSubjectResolver::plan($name);
    }

    // ── Bootstrap resolvers ──────────────────────────────────────────────────

    /** @param callable(mixed): (string|QuotaPlan) $resolver */
    public function resolve_plan_using(callable $resolver): self
    {
        QuotaSubjectResolver::resolve_plan_using($resolver);
        return $this;
    }

    /** @param callable(mixed): string $resolver */
    public function resolve_scope_using(callable $resolver): self
    {
        QuotaSubjectResolver::resolve_scope_using($resolver);
        return $this;
    }

    /**
     * Run both boot resolvers against one subject.
     *
     * @return array{0: QuotaPlan, 1: string} the plan and the scope
     * @throws \LogicException when either resolver is missing
     */
    public function resolve_subject(mixed $subject): array
    {
        return QuotaSubjectResolver::resolve($subject);
    }

    // ── Recommended entry point ──────────────────────────────────────────────

    /**
     * Reserve, run the work, settle on return, release on exception.
     *
     * @throws QuotaExceededException when the reservation is denied
     */
    public function meter(mixed $subject, string $operation, callable $work): mixed
    {
        [$plan, $scope] = $this->resolve_subject($subject);
        $reservation_id = $this->reservation_id();

        $result = $this->quota_reserve($scope, $reservation_id, $plan, $operation);
        if (!$result->allowed) {
            throw new QuotaExceededException($result);
        }

        try {
            $value = $work();
        } catch (\Throwable $e) {
            $this->quota_release($scope, $reservation_id);
            throw $e;
        }

        $this->quota_settle($scope, $reservation_id);

        return $value;
    }

    // ── Raw three-phase API ──────────────────────────────────────────────────

    public function quota_reserve(
        string $scope,
        string $reservation_id,
        QuotaPlan $plan,
        string $operation,
        ?int $cost = null
    ): QuotaResult {
        if (!$plan->declares_operation($operation)) {
            throw new \InvalidArgumentException(
                "Unknown quota operation: '{$operation}'. Declare it in " . self::CONFIG_OPERATIONS . '.'
            );
        }

        if (!$plan->has_operation($operation)) {
            return QuotaResult::not_entitled($this->quota_get($scope));
        }

        $cost = $cost ?? $plan->cost($operation);

        return $this->store->quota_reserve(
            QuotaKeyBuilder::balance_key($scope),
            QuotaKeyBuilder::epoch_key($scope),
            QuotaKeyBuilder::reservation_key($scope, $reservation_id),
            $reservation_id,
            $cost,
            $plan->reservation_ttl,
            QuotaKeyBuilder::pacing_keys($scope, $plan, $operation),
            time()
        );
    }

    /** Drops the reservation record. In this model the charge stands. */
    public function quota_settle(string $scope, string $reservation_id): int
    {
        return $this->store->quota_settle(QuotaKeyBuilder::balance_key($scope), QuotaKeyBuilder::reservation_key($scope, $reservation_id));
    }

    public function quota_release(string $scope, string $reservation_id): void
    {
        $this->store->quota_release(
            QuotaKeyBuilder::balance_key($scope),
            QuotaKeyBuilder::epoch_key($scope),
            QuotaKeyBuilder::reservation_key($scope, $reservation_id),
            $reservation_id
        );
    }

    public function reservation_id(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ── Key construction ─────────────────────────────────────────────────────
    //
    // Kept as thin forwarders so existing callers (and tests) that reach key
    // construction through a QuotaLimiter instance keep working. The real
    // logic lives in QuotaKeyBuilder.

    public function balance_key(string $scope): string
    {
        return QuotaKeyBuilder::balance_key($scope);
    }

    public function epoch_key(string $scope): string
    {
        return QuotaKeyBuilder::epoch_key($scope);
    }

    public function reservation_key(string $scope, string $reservation_id): string
    {
        return QuotaKeyBuilder::reservation_key($scope, $reservation_id);
    }
}
