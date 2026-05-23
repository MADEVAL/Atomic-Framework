<?php
declare(strict_types=1);
namespace Engine\Atomic\Session\Drivers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\App;
use Engine\Atomic\Session\Models\Session as SessionModel;
use SessionHandlerInterface;

class DB implements SessionHandlerInterface
{
    private const REVOKED_KEY_PREFIX = ':revoked:';

    private ?string $sid = null;
    private string $_csrf;
    private string $_agent;
    private string $_ip;
    private int $ttl;
    /** @var callable|null */
    private mixed $onsuspect;
    private SessionModel $mapper;

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->mapper->reset();
        $this->sid = null;
        return true;
    }

    public function read(string $id): string|false
    {
        $this->sid = $id;
        if ($this->is_revoked($id)) {
            return '';
        }

        $this->mapper->load(['session_id = ?', $id]);

        if ($this->mapper->dry()) {
            return '';
        }

        if ($this->mapper->get('ip') !== $this->_ip || $this->mapper->get('agent') !== $this->_agent) {
            $fw = \Base::instance();
            if (!isset($this->onsuspect) || $fw->call($this->onsuspect, [$this, $id]) === false) {
                // session_destroy() cannot be called before session_start() completes.
                $this->destroy($id);
                $this->close();
                unset($fw->{'COOKIE.' . \session_name()});
                $fw->error(403);
            }
        }

        return (string) $this->mapper->get('data');
    }

    public function write(string $id, string $data): bool
    {
        try {
            if ($this->is_revoked($id)) {
                return true;
            }

            if ($this->mapper->dry() || $this->mapper->get('session_id') !== $id) {
                $this->mapper->reset();
                $this->mapper->load(['session_id = ?', $id]);
            }

            $this->mapper->set('session_id', $id);
            $this->mapper->set('data', $data);
            $this->mapper->set('ip', $this->_ip);
            $this->mapper->set('agent', $this->_agent);
            $this->mapper->set('stamp', \time());
            $this->mapper->save();
            return true;
        } catch (\Throwable $e) {
            Log::error('DB session write error: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $mapper = new SessionModel();
            $mapper->erase(['session_id = ?', $id]);
            $this->mark_revoked($id);
            return true;
        } catch (\Throwable $e) {
            Log::error('DB session destroy error: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $mapper = new SessionModel();
            return (int) $mapper->erase(['stamp + ? < ?', $max_lifetime, \time()]);
        } catch (\Throwable $e) {
            Log::error('DB session garbage collection error: ' . $e->getMessage());
            return false;
        }
    }

    public function sid(): ?string
    {
        return $this->sid;
    }

    public function csrf(): string
    {
        return $this->_csrf;
    }

    public function ip(): string
    {
        return $this->_ip;
    }

    public function stamp(): int|false
    {
        if (!$this->sid) {
            \session_start();
        }

        return $this->mapper->dry() ? false : (int) $this->mapper->get('stamp');
    }

    public function agent(): string
    {
        return $this->_agent;
    }

    public function dry(): bool
    {
        return $this->mapper->dry();
    }

    public function get(string $key): mixed
    {
        return $this->mapper->get($key);
    }

    public function set(string $key, string $val): void
    {
        $this->mapper->set($key, $val);
    }

    public function reset(): void
    {
        $this->mapper->reset();
    }

    public function __construct(?callable $onsuspect = null, ?string $key = null)
    {
        $this->mapper = new SessionModel();
        $this->onsuspect = $onsuspect;
        $ttl = (int)App::instance()->get('SESSION_CONFIG.lifetime');
        $this->ttl = $ttl ?: (int)\ini_get('session.gc_maxlifetime') ?: 1440;

        \session_set_save_handler($this, true);

        $fw = \Base::instance();
        $headers = $fw->HEADERS;
        $this->_csrf = $fw->hash(
            $fw->SEED .
            \bin2hex(\random_bytes(16))
        );
        if ($key) {
            $fw->$key = $this->_csrf;
        }
        $this->_agent = isset($headers['User-Agent']) ? (string) $headers['User-Agent'] : '';
        if (\strlen($this->_agent) > 512) {
            $this->_agent = \substr($this->_agent, 0, 512);
        }
        $this->_ip = (string) $fw->IP;
    }

    private function is_revoked(string $id): bool
    {
        $mapper = new SessionModel();
        $mapper->load(['session_id = ?', $this->revoked_id($id)]);

        if ($mapper->dry()) {
            return false;
        }

        $stamp = (int)$mapper->get('stamp');
        if ($stamp > 0 && $stamp + $this->ttl <= \time()) {
            $mapper->erase();
            return false;
        }

        return true;
    }

    private function mark_revoked(string $id): void
    {
        $mapper = new SessionModel();
        $mapper->load(['session_id = ?', $this->revoked_id($id)]);
        $mapper->set('session_id', $this->revoked_id($id));
        $mapper->set('data', '');
        $mapper->set('ip', '');
        $mapper->set('agent', '');
        $mapper->set('stamp', \time());
        $mapper->save();
    }

    private function revoked_id(string $id): string
    {
        return self::REVOKED_KEY_PREFIX . $id;
    }
}
