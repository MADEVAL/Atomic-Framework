<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Session\Drivers\DB as DBSession;
use Engine\Atomic\Session\Drivers\Redis as RedisSession;

class SessionDriverFactoryAdapter
{
    public function __construct(private AppContextAdapter $app) {}

    public function start(string $driver, callable $onsuspect): void
    {
        switch (strtolower($driver)) {
            case 'redis':
                new RedisSession($onsuspect, 'SESSION.csrf_token');
                break;
            case 'db':
            default:
                new DBSession($onsuspect, 'SESSION.csrf_token');
                break;
        }
    }
}
