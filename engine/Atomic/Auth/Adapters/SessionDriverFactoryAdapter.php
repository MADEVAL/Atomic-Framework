<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use DB\SQL\Session as SQLSession;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Session\Redis\Session as RedisSession;

class SessionDriverFactoryAdapter
{
    public function __construct(private AppContextAdapter $app) {}

    public function start(string $driver, callable $onsuspect): void
    {
        switch (strtolower($driver)) {
            case 'redis':
                new RedisSession(
                    $this->app->get('REDIS.prefix'),
                    (int) $this->app->get('SESSION_CONFIG.lifetime'),
                    $onsuspect
                );
                break;
            case 'db':
            default:
                new SQLSession(
                    ConnectionManager::instance()->get_db(),
                    $this->app->get('DB_CONFIG.prefix') . 'sessions',
                    false,
                    $onsuspect
                );
                break;
        }
    }
}
