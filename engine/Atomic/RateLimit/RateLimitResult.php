<?php
declare(strict_types=1);
namespace Engine\Atomic\RateLimit;

if (!defined('ATOMIC_START')) exit;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retry_after = 0
    ) {}
}
