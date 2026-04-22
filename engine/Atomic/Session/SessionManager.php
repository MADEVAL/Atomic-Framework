<?php
declare(strict_types=1);
namespace Engine\Atomic\Session;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;

class SessionManager
{
    private string $driver;
    private ?ConnectionManager $connection_manager = null;
    private ?string $redis_prefix = null;
    
    use RedisSessionTrait;
    use SqlSessionTrait;
    
    public function __construct(?string $driver = null)
    {
        $atomic = App::instance();
        $this->driver = $driver ?? strtolower($atomic->get('SESSION_CONFIG.driver'));
        
        if ($this->driver === 'redis') {
            $this->connection_manager = ConnectionManager::instance();
            $this->redis_prefix = $atomic->get('REDIS.prefix');
        }
    }
    
    public function delete_session(string $session_id): bool
    {
        if ($this->driver === 'redis') {
            return $this->delete_redis_session($session_id);
        }
        return $this->delete_sql_session($session_id);
    }
    
    public function session_exists(string $session_id): bool
    {
        if ($this->driver === 'redis') {
            return $this->redis_session_exists($session_id);
        }
        return $this->sql_session_exists($session_id);
    }
    
    public function get_session_data(string $session_id): ?array
    {
        if ($this->driver === 'redis') {
            return $this->get_redis_session_data($session_id);
        }
        return $this->get_sql_session_data($session_id);
    }
    
    public function delete_sessions(array $session_ids): int
    {
        $deleted = 0;
        foreach ($session_ids as $session_id) {
            if ($this->delete_session($session_id)) {
                $deleted++;
            }
        }
        return $deleted;
    }
    
    public function get_driver(): string {
        return $this->driver;
    }
}
