<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class QuotaDenial
{
    public function __construct(
        public readonly string $time,
        public readonly string $operation,
        public readonly string $reason,
    ) {}

    /** @param array<string, mixed> $denial */
    public static function from_array(array $denial): self
    {
        return new self((string)($denial['ts'] ?? ''), (string)($denial['op'] ?? ''), (string)($denial['reason'] ?? ''));
    }
}
