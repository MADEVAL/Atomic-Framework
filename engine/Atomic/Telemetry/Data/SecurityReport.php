<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class SecurityReport
{
    /**
     * @param list<UserSession> $sessions
     * @param list<Lockout> $lockouts
     * @param list<int> $failed failed logins per point of the range
     * @param list<int> $lock_events lockouts per point of the range
     * @param array<string, int> $top_failed identifier => failed logins
     */
    public function __construct(
        public readonly array $sessions,
        public readonly array $lockouts,
        public readonly array $failed,
        public readonly array $lock_events,
        public readonly array $top_failed,
        public readonly Lifetime $lifetime,
        public readonly SecurityPolicy $policy,
    ) {}

    /** @param array<string, mixed> $report SecurityReporter::report(), redacted */
    public static function from_array(array $report): self
    {
        return new self(
            sessions: array_map(UserSession::from_array(...), array_values((array)($report['sessions'] ?? []))),
            lockouts: array_map(Lockout::from_array(...), array_values((array)($report['lockouts'] ?? []))),
            failed: array_map('intval', array_values((array)($report['failed'] ?? []))),
            lock_events: array_map('intval', array_values((array)($report['lock_events'] ?? []))),
            top_failed: array_map('intval', (array)($report['top_failed'] ?? [])),
            lifetime: Lifetime::from_array((array)($report['lifetime'] ?? [])),
            policy: SecurityPolicy::from_array((array)($report['policy'] ?? [])),
        );
    }

    public function locked(): int
    {
        return count(array_filter($this->lockouts, static fn (Lockout $lockout): bool => $lockout->locked));
    }

    public function impersonation(): bool
    {
        return (bool)array_filter($this->sessions, static fn (UserSession $session): bool => $session->impersonating !== null);
    }
}
