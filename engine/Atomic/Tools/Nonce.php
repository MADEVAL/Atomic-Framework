<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

class Nonce
{
    protected static ?self $instance = null;
    protected ?App $atomic = null;

    public function __construct()
    {
        $this->atomic = App::instance();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function create_nonce(string $action = '', int $ttl = 3600): string
    {
        $token = bin2hex(random_bytes(16));
        $key   = $this->nonce_key($action, $token);
        $this->atomic->set($key, [
            'time' => time(),
            'ip'   => $this->atomic->get('IP'),
            'ua'   => $this->atomic->get('AGENT'),
        ], $ttl);
        return $token;
    }

    public function verify_nonce(string $token, string $action = ''): bool
    {
        if (empty($token)) return false;
        $key = $this->nonce_key($action, $token);
        if (!$this->atomic->exists($key)) {
            return false;
        }
        $data = $this->atomic->get($key);
        if (
            !is_array($data) ||
            empty($data['time']) ||
            empty($data['ip']) ||
            empty($data['ua'])
        ) {
            return false;
        }
        $valid = $data['ip'] === $this->atomic->get('IP')  && $data['ua'] === $this->atomic->get('AGENT');
        $this->atomic->clear($key);
        return $valid;
    }

    protected function nonce_key(string $action, string $token): string
    {
        return 'nonce_' . md5($action . '_' . $token);
    }
}