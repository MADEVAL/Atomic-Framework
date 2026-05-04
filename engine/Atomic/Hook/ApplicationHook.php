<?php
declare(strict_types=1);

namespace Engine\Atomic\Hook;

if (!defined('ATOMIC_START')) exit;

enum ApplicationHook: string
{
    case AFTER_ROUTES_REGISTERED = 'after_routes_registered';
    case AFTER_PLUGINS_REGISTERED = 'after_plugins_registered';
    case BEFORE_SERVER_START = 'before_server_start';
}