<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class UserSession
{
    private const ID_PREFIX = 12;

    public function __construct(
        public readonly string $session_id,
        public readonly ?string $user,
        public readonly string $ip,
        public readonly string $agent,
        public readonly int $created_ago,
        public readonly int $last_ago,
        public readonly ?string $impersonating,
        public readonly bool $current,
    ) {}

    /** @param array<string, mixed> $session a session of SecurityReporter::report(), redacted */
    public static function from_array(array $session): self
    {
        return new self(
            session_id: (string)($session['session_id'] ?? ''),
            user: isset($session['user']) ? (string)$session['user'] : null,
            ip: (string)($session['ip'] ?? ''),
            agent: (string)($session['agent'] ?? ''),
            created_ago: (int)($session['created_ago'] ?? 0),
            last_ago: (int)($session['last_ago'] ?? 0),
            impersonating: isset($session['impersonating']) && $session['impersonating'] !== '' ? (string)$session['impersonating'] : null,
            current: (bool)($session['current'] ?? false),
        );
    }

    public function short_id(): string
    {
        return mb_substr($this->session_id, 0, self::ID_PREFIX);
    }

    public function status(): Status
    {
        return $this->impersonating !== null
            ? new Status('alert', Tone::Warn, 'Impersonated by ' . $this->impersonating)
            : new Status('check', Tone::Neutral, $this->current ? 'This session' : 'Active');
    }
}
