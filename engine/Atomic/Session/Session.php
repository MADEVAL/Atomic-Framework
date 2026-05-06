<?php
declare(strict_types=1);
namespace Engine\Atomic\Session;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Session\Services\SessionService as SessionServiceImpl;

class Session
{
    private static ?SessionServiceImpl $service = null;

    public static function service(): SessionServiceImpl
    {
        if (self::$service === null) {
            $app = new AppContextAdapter();
            self::$service = new SessionServiceImpl(
                $app,
                new PhpSessionAdapter(),
                new SessionDriverFactoryAdapter($app),
                new LogAdapter(),
            );
        }

        return self::$service;
    }

    public static function init(): void
    {
        self::service()->init();
    }

    public static function start(): void
    {
        self::service()->start();
    }

    public static function is_started(): bool
    {
        return self::service()->is_started();
    }

    public static function destroy(): void
    {
        self::service()->destroy();
    }
}
