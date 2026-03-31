<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Theme\Theme;

class Error extends Controller
{

    public function __construct() 
    {
        parent::__construct();
        try {
            Theme::instance('ErrorPages');
        } catch (\Throwable $e) {
            Log::error('Failed to initialize ErrorPages theme: ' . $e->getMessage());
        }
    }      

    public function error404(\Base $atomic): void
    {
        $atomic->status(404);
        header('HTTP/1.1 404 Not Found');
        $atomic->set('PAGE.title', '404 - ' . I18n::instance()->t('error404.title'));
        $atomic->set('PAGE.color', '#2196f3');
        echo \View::instance()->render('layout/404.atom.php');
    }

    public function error405(\Base $atomic): void
    {
        $atomic->status(405);
        header('HTTP/1.1 405 Method Not Allowed');
        $atomic->set('PAGE.title', '405 - ' . I18n::instance()->t('error405.title'));
        $atomic->set('PAGE.color', '#ff9800');
        echo \View::instance()->render('layout/405.atom.php');
    }

    public function error500(\Base $atomic): void
    {
        $atomic->status(500);
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
        }

        $atomic->set('PAGE.title', '500 - ' . I18n::instance()->t('error500.title'));
        $atomic->set('PAGE.color', '#f44336');

        if (!$atomic->get('ERROR.formatted_trace')) {
            $atomic->set('ERROR.formatted_trace', 'No trace available');
        }
        
        try {
            echo \View::instance()->render('layout/500.atom.php');
        } catch (\Throwable $e) {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>500 Error</title></head><body>';
            echo '<h1>500 Internal Server Error</h1>';
            echo '<p>An error occurred while processing your request.</p>';
            if ($atomic->get('ERROR.formatted_trace')) {
                echo '<pre>' . htmlspecialchars($atomic->get('ERROR.formatted_trace')) . '</pre>';
            }
            echo '</body></html>';
        }
    }

    public function error400(\Base $atomic): void
    {
        $atomic->status(400);
        header('HTTP/1.1 400 Bad Request');
        $atomic->set('PAGE.title', '400 - ' . I18n::instance()->t('error400.title'));
        $atomic->set('PAGE.color', '#ff9800');
        echo \View::instance()->render('layout/400.atom.php');
    }

    public function error401(\Base $atomic): void
    {
        $atomic->status(401);
        header('HTTP/1.1 401 Unauthorized');
        $atomic->set('PAGE.title', '401 - ' . I18n::instance()->t('error401.title'));
        $atomic->set('PAGE.color', '#f44336');
        echo \View::instance()->render('layout/401.atom.php');
    }

    public function error403(\Base $atomic): void
    {
        $atomic->status(403);
        header('HTTP/1.1 403 Forbidden');
        $atomic->set('PAGE.title', '403 - ' . I18n::instance()->t('error403.title'));
        $atomic->set('PAGE.color', '#f44336');
        echo \View::instance()->render('layout/403.atom.php');
    }

    public function error408(\Base $atomic): void
    {
        $atomic->status(408);
        header('HTTP/1.1 408 Request Timeout');
        $atomic->set('PAGE.title', '408 - ' . I18n::instance()->t('error408.title'));
        $atomic->set('PAGE.color', '#ffc107');
        echo \View::instance()->render('layout/408.atom.php');
    }

    public function error429(\Base $atomic): void
    {
        $atomic->status(429);
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: 60');
        $atomic->set('PAGE.title', '429 - ' . I18n::instance()->t('error429.title'));
        $atomic->set('PAGE.color', '#ff9800');
        echo \View::instance()->render('layout/429.atom.php');
    }

    public function error502(\Base $atomic): void
    {
        $atomic->status(502);
        header('HTTP/1.1 502 Bad Gateway');
        $atomic->set('PAGE.title', '502 - ' . I18n::instance()->t('error502.title'));
        $atomic->set('PAGE.color', '#3f51b5');
        echo \View::instance()->render('layout/502.atom.php');
    }

    public function error503(\Base $atomic): void
    {
        $atomic->status(503);
        header('HTTP/1.1 503 Service Unavailable');
        header('Retry-After: 3600');
        $atomic->set('PAGE.title', '503 - ' . I18n::instance()->t('error503.title'));
        $atomic->set('PAGE.color', '#9e9e9e');
        echo \View::instance()->render('layout/503.atom.php');
    }
}