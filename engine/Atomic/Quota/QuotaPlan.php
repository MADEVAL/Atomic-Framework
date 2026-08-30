<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

final class QuotaPlan
{
    public const TIER                = '_tier';
    public const KEY_CREDITS         = 'credits';
    public const KEY_PERIOD          = 'period';
    public const KEY_RESERVATION_TTL = 'reservation_ttl';
    public const KEY_PACING          = 'pacing';
    public const KEY_OPERATIONS      = 'operations';
    public const KEY_LIMIT           = 'limit';
    public const KEY_WINDOW          = 'window';
    public const KEY_COST            = 'cost';

    /**
     * @param list<QuotaPacingSpec> $pacing
     * @param array<string, QuotaOperationSpec> $operations
     * @param list<string> $declared_operations
     */
    public function __construct(
        public readonly string $name,
        public readonly int $credits,
        public readonly int $period,
        public readonly int $reservation_ttl,
        public readonly array $pacing,
        public readonly array $operations,
        public readonly array $declared_operations
    ) {
        $this->guard();
    }

    /**
     * Build a plan from a raw tier array and the declared operation list.
     *
     * @param array<string, mixed> $tier
     * @param list<string> $declared_operations
     */
    public static function from_array(string $name, array $tier, array $declared_operations): self
    {
        $required = [self::KEY_CREDITS, self::KEY_PERIOD, self::KEY_RESERVATION_TTL, self::KEY_PACING, self::KEY_OPERATIONS];
        foreach ($required as $key) {
            if (!array_key_exists($key, $tier)) {
                throw new \InvalidArgumentException("Quota tier '{$name}' is missing the required key '{$key}'. Quota config has no defaults.");
            }
        }

        if (!is_array($tier[self::KEY_OPERATIONS])) {
            throw new \InvalidArgumentException("Quota tier '{$name}': operations must be a map of operation names.");
        }

        $operations = [];
        foreach ($tier[self::KEY_OPERATIONS] as $operation => $spec) {
            $operation = (string)$operation;
            if (!is_array($spec) || !array_key_exists(self::KEY_COST, $spec)) {
                throw new \InvalidArgumentException("Quota tier '{$name}': operation '{$operation}' is missing the required key 'cost'.");
            }

            $unknown = array_diff(array_keys($spec), [self::KEY_COST, self::KEY_PACING]);
            if ($unknown !== []) {
                $key = (string)reset($unknown);
                throw new \InvalidArgumentException(
                    $key === 'global'
                        ? "Quota tier '{$name}': shared (global) pacing is not in this version. Remove 'global' from operation '{$operation}'."
                        : "Quota tier '{$name}': operation '{$operation}' has an unknown key '{$key}'."
                );
            }

            $operations[$operation] = new QuotaOperationSpec(
                (int)$spec[self::KEY_COST],
                self::windows($name, $operation . '.pacing', $spec[self::KEY_PACING] ?? [])
            );
        }

        return new self(
            $name,
            (int)$tier[self::KEY_CREDITS],
            (int)$tier[self::KEY_PERIOD],
            (int)$tier[self::KEY_RESERVATION_TTL],
            self::windows($name, 'pacing', $tier[self::KEY_PACING]),
            $operations,
            array_values(array_map('strval', $declared_operations))
        );
    }

    public function has_operation(string $operation): bool
    {
        return isset($this->operations[$operation]);
    }

    public function declares_operation(string $operation): bool
    {
        return in_array($operation, $this->declared_operations, true);
    }

    public function cost(string $operation): int
    {
        return $this->operation($operation)->cost;
    }

    /** @return list<QuotaPacingSpec> */
    public function operation_pacing(string $operation): array
    {
        return $this->operation($operation)->pacing;
    }

    private function operation(string $operation): QuotaOperationSpec
    {
        if (!isset($this->operations[$operation])) {
            throw new \InvalidArgumentException("Quota tier '{$this->name}' does not include the operation '{$operation}'.");
        }

        return $this->operations[$operation];
    }

    /**
     * @return list<QuotaPacingSpec>
     */
    private static function windows(string $name, string $label, mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("Quota tier '{$name}': '{$label}' must be a list of windows.");
        }

        $windows = [];
        foreach ($raw as $window) {
            $has_pair = is_array($window)
                && array_key_exists(self::KEY_LIMIT, $window)
                && array_key_exists(self::KEY_WINDOW, $window);
            if (!$has_pair) {
                throw new \InvalidArgumentException("Quota tier '{$name}': every entry of '{$label}' needs both 'limit' and 'window'.");
            }

            $limit = (int)$window[self::KEY_LIMIT];
            $seconds = (int)$window[self::KEY_WINDOW];
            if ($limit < 1 || $seconds < 1) {
                throw new \InvalidArgumentException("Quota tier '{$name}': '{$label}' needs a positive 'limit' and 'window'.");
            }

            $windows[] = new QuotaPacingSpec($limit, $seconds);
        }

        return $windows;
    }

    private function guard(): void
    {
        if ($this->name === '') {
            throw new \InvalidArgumentException('Quota tier name must not be empty.');
        }
        if ($this->credits < 0) {
            throw new \InvalidArgumentException("Quota tier '{$this->name}': 'credits' must not be negative.");
        }
        if ($this->period < 1) {
            throw new \InvalidArgumentException("Quota tier '{$this->name}': 'period' must be positive.");
        }
        if ($this->reservation_ttl < 1) {
            throw new \InvalidArgumentException("Quota tier '{$this->name}': 'reservation_ttl' must be positive.");
        }
        if ($this->declared_operations === []) {
            throw new \InvalidArgumentException("Quota tier '{$this->name}': the declared operation list must not be empty.");
        }

        foreach ($this->declared_operations as $operation) {
            if ($operation === self::TIER) {
                throw new \InvalidArgumentException("'" . self::TIER . "' is a reserved pacing name and cannot be an operation name.");
            }
        }

        foreach (array_keys($this->operations) as $operation) {
            if (!in_array((string)$operation, $this->declared_operations, true)) {
                throw new \InvalidArgumentException("Quota tier '{$this->name}': operation '{$operation}' is not in the declared operation list.");
            }
        }
    }
}
