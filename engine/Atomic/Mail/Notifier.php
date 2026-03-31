<?php
declare(strict_types=1);
namespace Engine\Atomic\Mail;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

final class Notifier
{
    protected App $atomic;
    private static ?self $instance = null;

    private string $sessionKey;
    private array $messages = [];
    private array $flash = [];

    private const TYPES = ['success', 'info', 'warning', 'danger', 'error'];

    private function __construct(string $key = 'notifier')
    {
        $this->atomic = App::instance();
        $this->sessionKey = $key;
        $this->loadFromSession();
    }

    public static function instance(string $key = 'notifier'): self
    {
        return self::$instance ??= new self($key);
    }

    private function loadFromSession(): void
    {
        $data = $this->atomic->get('SESSION.' . $this->sessionKey);
        if (is_array($data)) {
            $this->messages = $data['messages'] ?? [];
            $this->flash = $data['flash'] ?? [];
        }
    }

    private function saveToSession(): void
    {
        $this->atomic->set('SESSION.' . $this->sessionKey, [
            'messages' => $this->messages,
            'flash' => $this->flash,
        ]);
    }

    public function add(string $text, string $type = 'info', array $data = []): self
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'info';
        
        $this->messages[] = array_merge([
            'text' => $text,
            'type' => $type,
            'time' => time(),
        ], $data);

        $this->saveToSession();
        return $this;
    }

    public function success(string $text, array $data = []): self
    {
        return $this->add($text, 'success', $data);
    }

    public function info(string $text, array $data = []): self
    {
        return $this->add($text, 'info', $data);
    }

    public function warning(string $text, array $data = []): self
    {
        return $this->add($text, 'warning', $data);
    }

    public function error(string $text, array $data = []): self
    {
        return $this->add($text, 'danger', $data);
    }

    public function danger(string $text, array $data = []): self
    {
        return $this->add($text, 'danger', $data);
    }

    public function get(?string $type = null, bool $clear = true): array
    {
        if ($type === null) {
            $result = $this->messages;
            if ($clear) $this->clear();
            return $result;
        }

        $filtered = [];
        $remaining = [];

        foreach ($this->messages as $msg) {
            if ($msg['type'] === $type) {
                $filtered[] = $msg;
            } else {
                $remaining[] = $msg;
            }
        }

        if ($clear) {
            $this->messages = $remaining;
            $this->saveToSession();
        }

        return $filtered;
    }

    public function has(?string $type = null): bool
    {
        if ($type === null) {
            return !empty($this->messages);
        }

        foreach ($this->messages as $msg) {
            if ($msg['type'] === $type) return true;
        }

        return false;
    }

    public function clear(?string $type = null): self
    {
        if ($type === null) {
            $this->messages = [];
        } else {
            $this->messages = array_filter(
                $this->messages,
                fn($msg) => $msg['type'] !== $type
            );
        }

        $this->saveToSession();
        return $this;
    }

    public function setFlash(string $key, $value, int $lifetime = 1): self
    {
        $this->flash[$key] = [
            'value' => $value,
            'lifetime' => $lifetime,
            'created' => time(),
        ];

        $this->saveToSession();
        return $this;
    }

    public function getFlash(string $key, $default = null)
    {
        if (!isset($this->flash[$key])) {
            return $default;
        }

        $item = $this->flash[$key];
        $value = $item['value'];

        $item['lifetime']--;
        
        if ($item['lifetime'] <= 0) {
            unset($this->flash[$key]);
        } else {
            $this->flash[$key] = $item;
        }

        $this->saveToSession();
        return $value;
    }

    public function peekFlash(string $key, $default = null)
    {
        return $this->flash[$key]['value'] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return isset($this->flash[$key]);
    }

    public function removeFlash(string $key): self
    {
        unset($this->flash[$key]);
        $this->saveToSession();
        return $this;
    }

    public function clearFlash(): self
    {
        $this->flash = [];
        $this->saveToSession();
        return $this;
    }

    public function reset(): self
    {
        $this->messages = [];
        $this->flash = [];
        $this->saveToSession();
        return $this;
    }

    public function count(?string $type = null): int
    {
        if ($type === null) {
            return count($this->messages);
        }

        return count(array_filter(
            $this->messages,
            fn($msg) => $msg['type'] === $type
        ));
    }

    private function __clone() {}
}
