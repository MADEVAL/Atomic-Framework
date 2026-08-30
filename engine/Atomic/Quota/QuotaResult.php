<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

final class QuotaResult
{
    public const REASON_OK                    = 'ok';
    public const REASON_INSUFFICIENT_BALANCE  = 'insufficient_balance';
    public const REASON_PACING                = 'pacing';
    public const REASON_NOT_ENTITLED          = 'not_entitled';
    public const REASON_DUPLICATE_RESERVATION = 'duplicate_reservation';

    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly int $balance = 0,
        public readonly ?int $retry_after = null,
        public readonly ?string $limited_by = null
    ) {}

    public static function ok(int $balance): self
    {
        return new self(true, self::REASON_OK, $balance);
    }

    public static function not_entitled(int $balance = 0): self
    {
        return new self(false, self::REASON_NOT_ENTITLED, $balance, null, null);
    }
}
