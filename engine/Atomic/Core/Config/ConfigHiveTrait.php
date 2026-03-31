<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

if (!defined('ATOMIC_START')) exit;

trait ConfigHiveTrait
{
    protected function build_cache_string(
        string $driver,
        string $folderPath = '',
        string $server     = '',
        string $port       = '',
        string $password   = '',
        string $login      = ''
    ): string|false {
        switch ($driver) {
            case 'folder':
                return "folder={$folderPath}";

            case 'memcache':
            case 'memcached':
                return "memcache={$server}:{$port}";

            case 'redis':
                $cs = "redis={$server}:{$port}";
                if ($login !== '' && $password !== '') {
                    $cs .= "?auth={$login}:{$password}";
                } elseif ($password !== '') {
                    $cs .= "?auth={$password}";
                }
                return $cs;

            case 'apc':      return 'apc';
            case 'xcache':   return 'xcache';
            case 'wincache': return 'wincache';

            default:
                return false;
        }
    }
    
    protected function apply_settings_to_hive(\Base $atomic, array $settings): void
    {
        foreach ($settings as $key => $value) {
            if ($key === 'TZ' && $value) {
                @date_default_timezone_set($value);
            }
            if ($value !== null && $value !== '') {
                $atomic->set($key, $value);
            }
        }
    }
}
