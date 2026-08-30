<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

/**
 * Turns an application subject (example: the current user) into a plan and
 * a scope. Also turns a tier name into a validated QuotaPlan from config.
 *
 * The resolvers are registered once at boot and read by every later
 * resolve() call, so they are held statically.
 */
final class QuotaSubjectResolver
{
    public const CONFIG_ROOT = 'QUOTA';
    public const CONFIG_QUOTAS = self::CONFIG_ROOT . '.quotas';
    public const CONFIG_OPERATIONS = self::CONFIG_ROOT . '.operations';

    /** @var null|callable(mixed): (string|QuotaPlan) */
    private static $plan_resolver = null;

    /** @var null|callable(mixed): string */
    private static $scope_resolver = null;

    /** @param callable(mixed): (string|QuotaPlan) $resolver */
    public static function resolve_plan_using(callable $resolver): void
    {
        self::$plan_resolver = $resolver;
    }

    /** @param callable(mixed): string $resolver */
    public static function resolve_scope_using(callable $resolver): void
    {
        self::$scope_resolver = $resolver;
    }

    /**
     * Turn a tier name into a plan. Reads config, touches no store, enforces
     * nothing.
     */
    public static function plan(string $name): QuotaPlan
    {
        $tier = App::instance()->get(self::CONFIG_QUOTAS . '.' . $name);
        if (!is_array($tier) || $tier === []) {
            throw new \InvalidArgumentException("Undefined quota tier: '{$name}'.");
        }

        $declared = App::instance()->get(self::CONFIG_OPERATIONS);
        if (!is_array($declared) || $declared === []) {
            throw new \InvalidArgumentException('Quota config must declare an operation list under ' . self::CONFIG_OPERATIONS . '.');
        }

        return QuotaPlan::from_array($name, $tier, array_values($declared));
    }

    /**
     * Run both boot resolvers against one subject.
     *
     * @return array{0: QuotaPlan, 1: string} the plan and the scope
     * @throws \LogicException when either resolver is missing
     */
    public static function resolve(mixed $subject): array
    {
        if (self::$plan_resolver === null) {
            throw new \LogicException('No quota plan resolver registered. Call resolve_plan_using() at boot.');
        }
        if (self::$scope_resolver === null) {
            throw new \LogicException('No quota scope resolver registered. Call resolve_scope_using() at boot.');
        }

        $resolved = (self::$plan_resolver)($subject);
        $plan = $resolved instanceof QuotaPlan ? $resolved : self::plan((string)$resolved);

        return [$plan, (string)(self::$scope_resolver)($subject)];
    }

    /** Drops both resolvers. Intended for tests. */
    public static function reset(): void
    {
        self::$plan_resolver = null;
        self::$scope_resolver = null;
    }
}
