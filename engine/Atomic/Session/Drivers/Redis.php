<?php
declare(strict_types=1);
namespace Engine\Atomic\Session\Drivers;

if (!defined('ATOMIC_START')) exit;

use SessionAdapter;
use SessionHandlerInterface;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

class Redis implements SessionHandlerInterface
{
    private const REVOKED_KEY_PREFIX = ':revoked:';

    private ?string $sid = null;
    private string $_csrf;
    private string $_agent;
    private string $_ip;
    /** @var callable|null */
    private mixed $onsuspect;
    private string $prefix;
    private int $ttl;
    private ConnectionManager $connection_manager;
    private array $data = [];

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool
    {
        $this->data = [];
        $this->sid = null;
        return true;
    }

    public function read(string $id): string|false
    {
        $this->sid = $id;
        $redis = $this->connection_manager->get_redis(true);
        
        try {
            $result = $redis->get($this->prefix . $id);
            
            if ($result === false || $result === null) {
                $this->data = [];
                return '';
            }
            
            $decoded = \json_decode($result, true);
            if (!\is_array($decoded)) {
                $this->data = [];
                return '';
            }

            $this->data = $decoded;
            
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

    public function write(string $id, string $data): bool
    {
        $redis = $this->connection_manager->get_redis(true);
        
        try {
            if ($redis->exists($this->revoked_key($id))) {
                return true;
            }

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

    public function destroy(string $id): bool
    {
        $redis = $this->connection_manager->get_redis(true);
        try {
            $redis->setex($this->revoked_key($id), $this->ttl, '1');
            $redis->del($this->prefix . $id);
            return true;
        } catch (\Exception $e) {
            Log::error("Redis session destroy error: " . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false {
        return 0;
    }

    public function sid(): ?string {
        return $this->sid;
    }

    protected function csrf(): string {
        return $this->_csrf;
    }

    public function ip(): string {
        return $this->_ip;
    }

    protected function stamp(): int|false {
        if (!$this->sid) {
            \session_start();
        }
        return empty($this->data) || !isset($this->data['stamp']) ? false : (int)$this->data['stamp'];
    }

    public function agent(): string {
        return $this->_agent;
    }

    protected function dry(): bool {
        return empty($this->data);
    }

    protected function get(string $key): mixed {
        return $this->data[$key] ?? null;
    }

    protected function set(string $key, string $val): void {
        $this->data[$key] = $val;
    }

    protected function reset(): void {
        $this->data = [];
    }

    private function revoked_key(string $id): string
    {
        return $this->prefix . self::REVOKED_KEY_PREFIX . $id;
    }

    /**
     * Instantiate class
     * @param callable|null $onsuspect Callback for suspicious sessions
     * @param string|null $key Hive key to store CSRF token
     */
    public function __construct(
        ?callable $onsuspect = null,
        ?string $key = null
    ) {
        $atomic = App::instance();
        $ttl = (int)$atomic->get('SESSION_CONFIG.lifetime');
        $this->prefix = (string)$atomic->get('REDIS.prefix');
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
            \bin2hex(\random_bytes(16))
        );
        if ($key)
            $fw->$key = $this->_csrf;
        $this->_agent = isset($headers['User-Agent']) ? (string)$headers['User-Agent'] : '';
        if (\strlen($this->_agent) > 512) {
            $this->_agent = \substr($this->_agent, 0, 512);
        }
        $this->_ip = (string)$fw->IP;
    }
}
