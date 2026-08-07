<?php
declare(strict_types=1);
namespace App\Codes;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Codes\Generic;

class Code
{
    use Generic;

    // Application-specific error codes
    public const PROFILE_INCOMPLETE = 'profile_incomplete';
    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';
}
