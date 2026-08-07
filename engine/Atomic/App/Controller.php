<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\Theme\Theme;

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

        Theme::instance();

        if (!MiddlewareStack::run_for_route($this->atomic)) {
            throw new \Engine\Atomic\Exceptions\HttpException('Middleware blocked', 403);
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

    /** Render a view template and return as string */
    protected function render(string $file, string $mime = 'text/html', ?array $hive = null): string
    {
        $ttl = ATOMIC_CACHE_ALL_PAGES ? (int)ATOMIC_CACHE_EXPIRE_TIME : 0;
        return \View::instance()->render($file, $mime, $hive, $ttl);
    }

    /** Render and echo a view template */
    protected function display(string $file, string $mime = 'text/html', ?array $hive = null): void
    {
        echo $this->render($file, $mime, $hive);
    }

    /** New v0.4: render view using ViewRenderer */
    protected function view(string $template, array $data = []): \Engine\Atomic\Http\Response
    {
        $renderer = new \Engine\Atomic\View\ViewRenderer(ATOMIC_DIR . '/resources/views');
        return $renderer->renderToResponse($template, $data);
    }
}
