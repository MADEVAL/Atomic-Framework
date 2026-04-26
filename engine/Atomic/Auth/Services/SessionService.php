<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Services;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Interfaces\AuthSessionInterface;

class SessionService implements AuthSessionInterface
{
    public function __construct(
        private AppContextAdapter          $app,
        private PhpSessionAdapter          $php_session,
        private SessionDriverFactoryAdapter $session_factory,
        private SystemClockAdapter         $clock,
        private IdValidatorAdapter         $id_validator,
        private LogAdapter                 $logger,
    ) {}

    public function init(): void
    {
        $session_name = $this->app->get('SESSION_CONFIG.cookie');
        $this->php_session->name($session_name);

        if (!$this->php_session->has_cookie($session_name)) {
            return;
        }

        $this->start();
    }

    public function start(string $uuid = ''): void
    {
        $app = $this->app;

        $onsuspect = function ($session, $id = null) use ($app) {
            $this->logger->warning('Session security warning: IP or User-Agent mismatch', [
                'session_id'     => $id ?? (method_exists($session, 'sid') ? $session->sid() : ''),
                'stored_ip'      => method_exists($session, 'ip') ? $session->ip() : '',
                'current_ip'     => $app->get('IP'),
                'stored_agent'   => method_exists($session, 'agent') ? $session->agent() : '',
                'current_agent'  => $app->get('HEADERS.User-Agent') ?? '',
            ]);

            return !$app->get('SESSION_CONFIG.kill_on_suspect');
        };

        if ($this->php_session->status() !== PHP_SESSION_ACTIVE) {
            $driver = strtolower($this->app->get('SESSION_CONFIG.driver') ?? 'db');
            $this->session_factory->start($driver, $onsuspect);
        }

        if (!empty($uuid)) {
            $this->app->set('SESSION.user_uuid', $uuid);
            $this->app->set('SESSION.created_at', $this->clock->now());
            return;
        }

        $stored_uuid = $this->app->get('SESSION.user_uuid');
        if ($stored_uuid) {
            if (!$this->id_validator->is_valid_uuid_v4($stored_uuid) || $this->is_expired()) {
                $this->destroy();
            }
        }
    }

    public function is_expired(): bool
    {
        $created_at = $this->app->get('SESSION.created_at');
        if ($created_at === null) {
            return true;
        }
        $lifetime = (int) ($this->app->get('SESSION_CONFIG.lifetime') ?: 7200);
        return ($this->clock->now() - (int) $created_at) > $lifetime;
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

