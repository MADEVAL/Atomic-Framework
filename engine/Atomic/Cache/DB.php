<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache;

use Engine\Atomic\App\Models\Options;

class DB extends \Prefab
{
    protected Options $options;

    public function __construct(){
        $this->options = new Options();
    }
    
    public function exists(string $key, &$val = NULL): array|false {
        return $this->options->has_option($key);
    }

    public function set(string $key, string $val, int $ttl = 0): bool {
        return $this->options->set_option($key, $val, $ttl);
    }

    public function get(string $key): mixed {
        return $this->options->get_option($key);
    }

    public function clear(string $key): bool {
        return $this->options->delete_option($key);
    }

    public function reset(?string $suffix = NULL): bool {
        $suffix = $suffix ?? '';
        return $this->options->delete_option_like($suffix . '%');
    }
}