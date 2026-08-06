<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Session\SessionManager;

class SessionManagerAdapter
{
    private ?SessionManager $manager = null;

    private function manager(): SessionManager
    {
        if ($this->manager === null) {
            $this->manager = new SessionManager();
        }
        return $this->manager;
    }

    public function delete_session(string $session_id): bool
    {
        return $this->manager()->delete_session($session_id);
    }

    public function delete_sessions(array $session_ids): int
    {
        return $this->manager()->delete_sessions($session_ids);
    }

    public function get_driver(): string
    {
        return $this->manager()->get_driver();
    }
}
