<?php
declare(strict_types=1);

namespace Engine\Atomic\Hook;

if (!defined('ATOMIC_START')) exit;

enum ApplicationHook: string
{
    case AFTER_ROUTES_REGISTERED = 'after_routes_registered';
    case AFTER_PLUGINS_BOOTED = 'after_plugins_booted';
    case BEFORE_SERVER_START = 'before_server_start';
}
