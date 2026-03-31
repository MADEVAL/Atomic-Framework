<?php
declare(strict_types=1);
namespace Engine\Atomic\Session;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Session\Models\Session;

trait SqlSessionTrait
{
    private function delete_sql_session(string $session_id): bool
    {
        try {
            $session_mapper = new Session();
            $session_mapper->load(['session_id = ?', $session_id]);
            if ($session_mapper->dry()) return false;
            $session_mapper->erase();
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to delete SQL session: " . $e->getMessage());
            return false;
        }
    }

    private function sql_session_exists(string $session_id): bool
    {
        try {
            $session_mapper = new Session();
            $session_mapper->load(['session_id = ?', $session_id]);
            return !$session_mapper->dry();
        } catch (\Exception $e) {
            Log::error("Failed to check SQL session existence: " . $e->getMessage());
            return false;
        }
    }

    private function get_sql_session_data(string $session_id): ?array
    {
        try {
            $session_mapper = new Session();
            $session_mapper->load(['session_id = ?', $session_id]);

            if ($session_mapper->dry()) {
                return null;
            }

            return [
                'session_id' => $session_mapper->session_id,
                'data' => $session_mapper->data,
                'ip' => $session_mapper->ip,
                'agent' => $session_mapper->agent,
                'stamp' => $session_mapper->stamp,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get SQL session data: " . $e->getMessage());
            return null;
        }
    }
}
