<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

/** One operation inside a quota tier: its credit cost and its own pacing windows. */
final class QuotaOperationSpec
{
    /** @param list<QuotaPacingSpec> $pacing */
    public function __construct(
        public readonly int $cost,
        public readonly array $pacing
    ) {}
}
