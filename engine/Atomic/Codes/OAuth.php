<?php
declare(strict_types=1);
namespace Engine\Atomic\Codes;

if (!defined('ATOMIC_START')) exit;

trait OAuth
{
    public const OAUTH_TOKEN_ERROR = '450';
    public const OAUTH_USER_DATA_ERROR = '451';
    public const OAUTH_ACCOUNT_ALREADY_LINKED = '452';
    public const OAUTH_NOT_CONFIGURED = '453';
    public const OAUTH_INVALID_STATE = '454';
}
