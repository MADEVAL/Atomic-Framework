<?php
declare(strict_types=1);
namespace Engine\Atomic\Codes;

if (!defined( 'ATOMIC_START' ) ) exit;

class Code
{
    // Generic
    public const SUCCESS = '200';
    public const BAD_REQUEST = '400';
    public const UNAUTHORIZED = '401';
    public const FORBIDDEN = '403';
    public const TOO_MANY_REQUESTS = '429';
    public const NONCE_INVALID = '440';
    public const SERVER_ERROR = '500';
    public const SERVICE_UNAVAILABLE = '503';

    // OAuth
    public const OAUTH_TOKEN_ERROR = '450';
    public const OAUTH_USER_DATA_ERROR = '451';
    public const OAUTH_ACCOUNT_ALREADY_LINKED = '452';
    public const OAUTH_NOT_CONFIGURED = '453';
    public const OAUTH_INVALID_STATE = '454';
}
