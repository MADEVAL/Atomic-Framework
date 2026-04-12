<?php
declare(strict_types=1);
namespace Engine\Atomic\Session\Redis;

if (!defined('ATOMIC_START')) exit;

use ReturnTypeWillChange;
use SessionAdapter;
use SessionHandlerInterface;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

class Session implements SessionHandlerInterface
{
    protected
        //! Session ID
        $sid,
        //! Anti-CSRF token
        $_csrf,
        //! User agent
        $_agent,
        //! IP
        $_ip,
        //! Suspect callback
        $onsuspect,
        // ! Redis key prefix
        $prefix,
        //! Session TTL (max lifetime)
        $ttl,
        //! Connection Manager
        $connection_manager,
        //! Cached session data
        $data = [];

    function open(string $path, string $name): bool {
        return true;
    }

    function close(): bool
    {
        $this->data = [];
        $this->sid = null;
        return true;
    }

    #[ReturnTypeWillChange]
    function read(string $id): string|false
    {
        $this->sid = $id;
        $redis = $this->connection_manager->get_redis(true);
        
        try {
            $result = $redis->get($this->prefix . $id);
            
            if ($result === false || $result === null) {
                $this->data = [];
                return '';
            }
            
            $this->data = \json_decode($result, true) ?: [];
            
            $stored_ip = $this->data['ip'] ?? '';
            $stored_agent = $this->data['agent'] ?? '';
            
            if ($stored_ip !== $this->_ip || $stored_agent !== $this->_agent) {
                $fw = \Base::instance();
                if (!isset($this->onsuspect) ||
                    $fw->call($this->onsuspect, [$this, $id]) === false) {
                    //NB: `session_destroy` can't be called at that stage (`session_start` not completed)
                    $this->destroy($id);
                    $this->close();
                    unset($fw->{'COOKIE.' . \session_name()});
                    $fw->error(403);
                }
            }
            
            return $this->data['data'] ?? '';
        } catch (\Exception $e) {
            Log::error("Redis session read error: " . $e->getMessage());
            return '';
        }
    }

    function write(string $id, string $data): bool
    {
        $redis = $this->connection_manager->get_redis(true);
        
        try {
            $session_data = \json_encode([
                'session_id' => $id,
                'data' => $data,
                'ip' => $this->_ip,
                'agent' => $this->_agent,
                'stamp' => \time()
            ], JSON_THROW_ON_ERROR);

            return (bool)$redis->setex(
                $this->prefix . $id,
                $this->ttl,
                $session_data
            );
            
        } catch (\Exception $e) {
            Log::error("Redis session write error: " . $e->getMessage());
            return false;
        }
    }

    function destroy($id): bool
    {
        $redis = $this->connection_manager->get_redis(true);
        try {
            $redis->del($this->prefix . $id);
            return true;
        } catch (\Exception $e) {
            Log::error("Redis session destroy error: " . $e->getMessage());
            return false;
        }
    }

    #[ReturnTypeWillChange]
    function gc(int $max_lifetime): int {
        return 0;
    }

    function sid(): ?string {
        return $this->sid;
    }

    function csrf(): string {
        return $this->_csrf;
    }

    function ip(): string {
        return $this->_ip;
    }

    function stamp() {
        if (!$this->sid) {
            \session_start();
        }
        return empty($this->data) ? false : ($this->data['stamp'] ?? false);
    }

    function agent(): string {
        return $this->_agent;
    }

    function dry(): bool {
        return empty($this->data);
    }

    function get(string $key): mixed {
        return $this->data[$key] ?? null;
    }

    function set(string $key, $val): void {
        $this->data[$key] = $val;
    }

    function reset(): void {
        $this->data = [];
    }

    /**
     * Instantiate class
     * @param string $prefix Redis key prefix for sessions
     * @param int $ttl Session TTL in seconds (0 = use session.gc_maxlifetime)
     * @param callable|null $onsuspect Callback for suspicious sessions
     * @param string|null $key Hive key to store CSRF token
     */
    function __construct(
        string $prefix,
        int $ttl,
        ?callable $onsuspect = null,
        ?string $key = null
    ) {
        $this->prefix = $prefix;
        $this->ttl = $ttl ?: (int)\ini_get('session.gc_maxlifetime') ?: 1440;
        $this->onsuspect = $onsuspect;
        $this->connection_manager = ConnectionManager::instance();
        
        if (\version_compare(PHP_VERSION, '8.4.0') >= 0) {
            \session_set_save_handler(new SessionAdapter($this));
        } else {
            \session_set_save_handler(
                [$this, 'open'],
                [$this, 'close'],
                [$this, 'read'],
                [$this, 'write'],
                [$this, 'destroy'],
                [$this, 'gc']
            );
        }
        \register_shutdown_function('session_commit');
        $fw = \Base::instance();
        $headers = $fw->HEADERS;
        $this->_csrf = $fw->hash(
            $fw->SEED .
            (\extension_loaded('openssl') ?
                \implode(\unpack('L', \openssl_random_pseudo_bytes(4))) :
                \mt_rand())
        );
        if ($key)
            $fw->$key = $this->_csrf;
        $this->_agent = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        $this->_ip = $fw->IP;
    }
}