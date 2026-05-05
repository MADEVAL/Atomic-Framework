<?php
declare(strict_types=1);

namespace Engine\Atomic\Hook;

if (!defined('ATOMIC_START')) exit;

enum ApplicationHook: string
{
    case CONFIG_LOADED = 'atomic_config_loaded';
    case PREFLY_FAILED = 'atomic_prefly_failed';
    case CORE_READY = 'atomic_core_ready';
    case ROUTES_REGISTERED = 'atomic_routes_registered';
    case PLUGINS_LOADED = 'atomic_plugins_loaded';
    case APP_BOOTSTRAPPED = 'atomic_app_bootstrapped';
    case BEFORE_SERVER_START = 'atomic_before_server_start';
}
