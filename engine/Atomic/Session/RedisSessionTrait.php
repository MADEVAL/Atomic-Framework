<?php
declare(strict_types=1);
namespace Engine\Atomic\Session;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;

trait RedisSessionTrait
{
    private function delete_redis_session(string $session_id): bool
    {
        try {
            $redis = $this->connection_manager->get_redis(true);
            $result = $redis->del($this->redis_prefix . $session_id);
            return $result > 0;
        } catch (\Exception $e) {
            Log::error("Failed to delete Redis session: " . $e->getMessage());
            return false;
        }
    }

    private function redis_session_exists(string $session_id): bool
    {
        try {
            $redis = $this->connection_manager->get_redis(true);
            return (bool)$redis->exists($this->redis_prefix . $session_id);
        } catch (\Exception $e) {
            Log::error("Failed to check Redis session existence: " . $e->getMessage());
            return false;
        }
    }

    private function get_redis_session_data(string $session_id): ?array
    {
        try {
            $redis = $this->connection_manager->get_redis(true);
            $result = $redis->get($this->redis_prefix . $session_id);

            if ($result === false || $result === null) {
                return null;
            }

            $data = json_decode($result, true);
            return $data ?: null;
        } catch (\Exception $e) {
            Log::error("Failed to get Redis session data: " . $e->getMessage());
            return null;
        }
    }
}
