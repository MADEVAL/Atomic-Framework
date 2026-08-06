<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods;

class AppContextAdapter
{
    public function get(string $key): mixed
    {
        return App::instance()->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        App::instance()->set($key, $value);
    }

    public function clear(string $key): void
    {
        App::instance()->clear($key);
    }

    public function get_device_type(): string
    {
        return Methods::instance()->get_user_device();
    }
}
