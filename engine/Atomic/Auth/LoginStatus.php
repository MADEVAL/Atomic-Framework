<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

enum LoginStatus: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case LockedOut = 'locked_out';
}
