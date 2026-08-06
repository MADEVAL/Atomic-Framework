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
use Engine\Atomic\Session\Services\SessionService;

class AuthSessionService implements AuthSessionInterface
{
    private SessionService $session;

    public function __construct(
        private AppContextAdapter   $app,
        PhpSessionAdapter           $php_session,
        SessionDriverFactoryAdapter $session_factory,
        private SystemClockAdapter  $clock,
        private IdValidatorAdapter  $id_validator,
        LogAdapter                  $logger,
    ) {
        $this->session = new SessionService($app, $php_session, $session_factory, $logger);
    }

    public function start_for_user(string $uuid): void
    {
        $this->session->start();
        $this->app->set('SESSION.user_uuid', $uuid);
        $this->app->set('SESSION.created_at', $this->clock->now());
    }

    public function validate_auth_session(): void
    {
        $stored_uuid = $this->app->get('SESSION.user_uuid');
        if (!$stored_uuid) {
            return;
        }

        if (!$this->id_validator->is_valid_uuid_v4($stored_uuid) || $this->is_expired()) {
            $this->destroy();
        }
    }

    public function is_expired(): bool
    {
        $created_at = $this->app->get('SESSION.created_at');
        if ($created_at === null) {
            return true;
        }

        $lifetime = (int) $this->app->get('SESSION_CONFIG.lifetime');
        return ($this->clock->now() - (int) $created_at) > $lifetime;
    }

    public function is_started(): bool
    {
        return $this->session->is_started();
    }

    public function destroy(): void
    {
        $this->app->clear('SESSION.user_uuid');
        $this->app->clear('SESSION.created_at');
        $this->app->clear('SESSION.admin_uuid');
    }
}
