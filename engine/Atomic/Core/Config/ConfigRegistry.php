<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

if (!defined('ATOMIC_START')) exit;

/**
 * Plugin-extensible configuration registry.
 *
 * Naming convention:
 *   UPPER_CASE     — top-level flat keys: APP_NAME, DEBUG_MODE, CACHE_PREFIX
 *   UPPER.dotted   — namespaced keys: MONOPAY.TOKEN, JAR.lifetime, SECURITY_HEADERS.XFO
 *   lower.dotted   — structured arrays: i18n.languages, ai.openai.api_key
 *
 * Usage:
 *   ConfigRegistry::register('MYPLUGIN.KEY', 'ENV_VAR', 'default', 'string');
 *   app()->get('MYPLUGIN.KEY');
 */
class ConfigRegistry
{
    private static array $schemas = [];

    public static function register(string $key, string $env, mixed $default = '', string $type = 'string', ?\Closure $map = null): void
    {
        self::$schemas[$key] = compact('env', 'default', 'type', 'map');
    }

    public static function schemas(): array
    {
        return self::$schemas;
    }
}
