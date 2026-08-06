<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Models;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Storage;
use Engine\Atomic\Core\App;

class Meta extends Storage
{
    protected $table = 'meta';

    public static function set_meta(string $uuid, string $key, string $value): bool {
        return parent::_set($uuid, $key, $value);
    }

    public static function has_meta(string $uuid, string $key): bool {
        return parent::_has($uuid, $key);
    }

    public static function get_meta(string $uuid, string $key, mixed $default = null): mixed {
        return parent::_get($uuid, $key, $default);
    }

    public static function get_meta_like(string $uuid, string $key_pattern): array|false {
        return parent::_get_like($uuid, $key_pattern);
    }

    public static function delete_meta(string $uuid, string $key): bool {
        return parent::_delete($uuid, $key);
    }

    public static function delete_meta_like(string $uuid, string $key_pattern): bool {
        return parent::_delete_like($uuid, $key_pattern);
    }

}