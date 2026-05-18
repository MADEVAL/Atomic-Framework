<?php
declare(strict_types=1);
namespace Engine\Atomic\Session\Services;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Hook\Hook;

class SessionService
{
    public function __construct(
        private AppContextAdapter           $app,
        private PhpSessionAdapter           $php_session,
        private SessionDriverFactoryAdapter $session_factory,
        private LogAdapter                  $logger,
    ) {}

    public function init(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $session_name = $this->app->get('SESSION_CONFIG.cookie');
        $this->php_session->name($session_name);

        if (!$this->php_session->has_cookie($session_name)) {
            return;
        }

        $this->start();
    }

    public function start(): void
    {
        $app = $this->app;

        $onsuspect = function ($session, $id = null) use ($app) {
            $this->logger->warning('Session security warning: IP or User-Agent mismatch', [
                'session_id'    => $id ?? (method_exists($session, 'sid') ? $session->sid() : ''),
                'stored_ip'     => method_exists($session, 'ip') ? $session->ip() : '',
                'current_ip'    => $app->get('IP'),
                'stored_agent'  => method_exists($session, 'agent') ? $session->agent() : '',
                'current_agent' => $app->get('HEADERS.User-Agent') ?? '',
            ]);

            return !$app->get('SESSION_CONFIG.kill_on_suspect');
        };

        if ($this->php_session->status() !== PHP_SESSION_ACTIVE) {
            Hook::instance()->do_action('SESSION_BEFORE_START', $this);
            $driver = strtolower($this->app->get('SESSION_CONFIG.driver') ?? 'db');
            $this->session_factory->start($driver, $onsuspect);
            if ($this->php_session->status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                session_start();
            }
        }

        Hook::instance()->do_action('SESSION_STARTED', $this);
    }

    public function is_started(): bool
    {
        return $this->php_session->status() === PHP_SESSION_ACTIVE;
    }

    public function destroy(): void
    {
        $this->app->clear('SESSION');
    }
}
