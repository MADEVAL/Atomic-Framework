<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\View\Fmt;

final class SecurityPolicy
{
    public function __construct(
        public readonly int $max_attempts,
        public readonly int $window,
        public readonly int $lockout,
        public readonly int $password_min_length,
        public readonly bool $password_mixed_case,
        public readonly bool $password_numbers,
        public readonly bool $password_symbols,
        public readonly string $session_driver,
        public readonly int $session_lifetime,
        public readonly string $csrf_key,
    ) {}

    /** @param array<string, mixed> $policy SecurityReporter::report()['policy'] */
    public static function from_array(array $policy): self
    {
        $lockout = (array)($policy['lockout'] ?? []);
        $password = (array)($policy['password'] ?? []);
        $session = (array)($policy['session'] ?? []);

        return new self(
            max_attempts: (int)($lockout['max_attempts'] ?? 0),
            window: (int)($lockout['window'] ?? 0),
            lockout: (int)($lockout['lockout'] ?? 0),
            password_min_length: (int)($password['min_length'] ?? 0),
            password_mixed_case: (bool)($password['mixed_case'] ?? false),
            password_numbers: (bool)($password['numbers'] ?? false),
            password_symbols: (bool)($password['symbols'] ?? false),
            session_driver: (string)($session['driver'] ?? ''),
            session_lifetime: (int)($session['lifetime'] ?? 0),
            csrf_key: (string)(((array)($policy['csrf'] ?? []))['key'] ?? ''),
        );
    }

    public function password_rules(): string
    {
        return implode(', ', array_filter([
            'min ' . $this->password_min_length . ' chars',
            $this->password_mixed_case ? 'mixed case' : null,
            $this->password_numbers ? 'numbers' : null,
            $this->password_symbols ? 'symbols' : null,
        ]));
    }

    /** @return array<string, string> label => value, in display order */
    public function rules(): array
    {
        return [
            'Lockout' => $this->max_attempts . ' attempts in ' . Fmt::age($this->window),
            'Locked for' => Fmt::age($this->lockout),
            'Password' => $this->password_rules(),
            'Session driver' => $this->session_driver,
            'Session lifetime' => Fmt::age($this->session_lifetime),
            'CSRF key' => $this->csrf_key,
        ];
    }
}
