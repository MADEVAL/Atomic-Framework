<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareStack;

abstract class Controller
{
    protected \Base $atomic;

    public function __construct()
    {
        $this->atomic = App::atomic();
    }

    public function beforeroute(\Base $atomic): void
    {
        $this->atomic = $atomic ?: App::atomic();
        $this->atomic->set('__current_controller', $this);
        $this->atomic->set('__afterroute_done', false);

        if (!MiddlewareStack::run_for_route($this->atomic)) {
            exit;
        }
    }

    public function afterroute(\Base $atomic): void
    {
        $this->atomic = $atomic ?: App::atomic();

        if ($this->atomic->get('__afterroute_done')) return;
        $this->atomic->set('__afterroute_done', true);

        define('ATOMIC_STOP', microtime(true));
        define('ATOMIC_TIME', ATOMIC_STOP - ATOMIC_START);
    }

    // Render and Display methods TEST
    // TODO: move to THEME class and i18n support add
    // TODO!!!!!!!!!!   Check cache ON BEFORE 
    protected function render(string $file, string $mime = 'text/html', ?array $hive = null): string
    {
        $ttl = ATOMIC_CACHE_ALL_PAGES ? (int)ATOMIC_CACHE_EXPIRE_TIME : 0;
        return \View::instance()->render($file, $mime, $hive, $ttl);
    }

    protected function display(string $file, string $mime = 'text/html', ?array $hive = null): void
    {
        echo $this->render($file, $mime, $hive);
    }
}
