<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Theme\Theme;

class Error extends Controller
{
    private function render_internal_server_error_html(\Base $atomic): void
    {
        $atomic->status(500);
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>500 Error</title></head><body>';
        echo '<h1>500 Internal Server Error</h1>';
        echo '<p>An internal server error occurred.</p>';
        echo '</body></html>';
    }

    public function __construct() 
    {
        parent::__construct();
        try {
            Theme::instance('ErrorPages');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to initialize ErrorPages theme: ' . $e->getMessage());
        }
    }      

    public function error404(\Base $atomic): void
    {
        try {
            $atomic->status(404);
            header('HTTP/1.1 404 Not Found');
            $atomic->set('PAGE.title', '404 - ' . I18n::instance()->t('error404.title'));
            $atomic->set('PAGE.color', '#2196f3');
            echo \View::instance()->render('layout/404.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 404 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error405(\Base $atomic): void
    {
        try {
            $atomic->status(405);
            header('HTTP/1.1 405 Method Not Allowed');
            $atomic->set('PAGE.title', '405 - ' . I18n::instance()->t('error405.title'));
            $atomic->set('PAGE.color', '#ff9800');
            echo \View::instance()->render('layout/405.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 405 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error500(\Base $atomic): void
    {
        try {
            $atomic->status(500);
            if (!headers_sent()) {
                header('HTTP/1.1 500 Internal Server Error');
            }

            $atomic->set('PAGE.title', '500 - ' . I18n::instance()->t('error500.title'));
            $atomic->set('PAGE.color', '#f44336');

            if (!$atomic->get('ERROR.formatted_trace')) {
                $atomic->set('ERROR.formatted_trace', 'No trace available');
            }

            echo \View::instance()->render('layout/500.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 500 error template: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error400(\Base $atomic): void
    {
        try {
            $atomic->status(400);
            header('HTTP/1.1 400 Bad Request');
            $atomic->set('PAGE.title', '400 - ' . I18n::instance()->t('error400.title'));
            $atomic->set('PAGE.color', '#ff9800');
            echo \View::instance()->render('layout/400.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 400 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error401(\Base $atomic): void
    {
        try {
            $atomic->status(401);
            header('HTTP/1.1 401 Unauthorized');
            $atomic->set('PAGE.title', '401 - ' . I18n::instance()->t('error401.title'));
            $atomic->set('PAGE.color', '#f44336');
            echo \View::instance()->render('layout/401.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 401 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error403(\Base $atomic): void
    {
        try {
            $atomic->status(403);
            header('HTTP/1.1 403 Forbidden');
            $atomic->set('PAGE.title', '403 - ' . I18n::instance()->t('error403.title'));
            $atomic->set('PAGE.color', '#f44336');
            echo \View::instance()->render('layout/403.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 403 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error408(\Base $atomic): void
    {
        try {
            $atomic->status(408);
            header('HTTP/1.1 408 Request Timeout');
            $atomic->set('PAGE.title', '408 - ' . I18n::instance()->t('error408.title'));
            $atomic->set('PAGE.color', '#ffc107');
            echo \View::instance()->render('layout/408.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 408 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error429(\Base $atomic): void
    {
        try {
            $atomic->status(429);
            header('HTTP/1.1 429 Too Many Requests');
            header('Retry-After: 60');
            $atomic->set('PAGE.title', '429 - ' . I18n::instance()->t('error429.title'));
            $atomic->set('PAGE.color', '#ff9800');
            echo \View::instance()->render('layout/429.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 429 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error502(\Base $atomic): void
    {
        try {
            $atomic->status(502);
            header('HTTP/1.1 502 Bad Gateway');
            $atomic->set('PAGE.title', '502 - ' . I18n::instance()->t('error502.title'));
            $atomic->set('PAGE.color', '#3f51b5');
            echo \View::instance()->render('layout/502.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 502 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }

    public function error503(\Base $atomic): void
    {
        try {
            $atomic->status(503);
            header('HTTP/1.1 503 Service Unavailable');
            header('Retry-After: 3600');
            $atomic->set('PAGE.title', '503 - ' . I18n::instance()->t('error503.title'));
            $atomic->set('PAGE.color', '#9e9e9e');
            echo \View::instance()->render('layout/503.atom.php');
        } catch (\Throwable $e) {
            Log::channel(LogChannel::ERROR)->error('Failed to render 503 error page: ' . $e->getMessage());
            $this->render_internal_server_error_html($atomic);
        }
    }
}