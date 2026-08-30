<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Quota\QuotaLimiter;

return [
    'fail' => QuotaLimiter::FAIL_OPEN,

    // Optional credit quotas — see docs/quota.md for operations/quotas shape.
    'operations' => [],
    'quotas'     => [],
];
