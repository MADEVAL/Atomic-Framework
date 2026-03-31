<?php
declare(strict_types=1);
namespace Engine\Atomic\Codes;

if (!defined('ATOMIC_START')) exit;

trait Generic
{
    public const SUCCESS = '200';
    public const BAD_REQUEST = '400';
    public const UNAUTHORIZED = '401';
    public const FORBIDDEN = '403';
    public const RATE_LIMIT = '429';
    public const NONCE_INVALID = '440';
    public const SERVER_ERROR = '500';
    public const SERVICE_UNAVAILABLE = '503';
}
