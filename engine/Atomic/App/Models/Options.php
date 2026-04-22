<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Models;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Storage;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;

class Options extends Storage
{
    protected $table = 'options';
    protected $fieldConf = [
        'expired_at' => [
            'type' => 'DATETIME',
            'nullable' => true,
        ],
    ];

    public static function set_option(string $key, string $value, int $ttl = 0): bool {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');
        return parent::_set($uuid, $key, $value, $ttl);
    }

    public static function has_option(string $key, &$val = null): array|false {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');

        try {
            $storage = new static();
            $storage->load(['uuid = ? AND key = ?', $uuid, $key]);
            
            if ($storage->dry()) {
                return false;
            }
            
            $val = $storage->value;
            
            $timestamp = strtotime($storage->updated_at);
            $now = time();
            $ttl = 0;
            
            if (!empty($storage->expired_at)) {
                $expired_timestamp = strtotime($storage->expired_at);
                $ttl = $expired_timestamp - $now;
                
                if ($ttl <= 0) {
                    static::delete_option($key);
                    $val = null;
                    return false;
                }
            }
            
            return [$timestamp, $ttl];
        } catch (\Throwable $e) {
            Log::error('Error in has_option: ' . $e->getMessage());
            return false;
        }
    }

    public static function get_option(string $key, mixed $default = null): mixed {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');
        
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid APP UUID v4 provided to get: ' . $uuid);
            return false;
        }
        
        try {
            $storage = new static();
            $storage->load(['uuid = ? AND key = ?', $uuid, $key]);

            if ($storage->dry()) {
                return $default;
            }

            if (!empty($storage->expired_at)) {
                $expired_timestamp = strtotime($storage->expired_at);
                $now = time();
                
                if ($expired_timestamp <= $now) {
                    static::delete_option($key);
                    return $default;
                }
            }
            
            return $storage->value;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function get_option_like(string $key_pattern): array {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');
        $results = parent::_get_like($uuid, $key_pattern);
        
        $now = time();
        foreach ($results as $key => $value) {
            try {
                $storage = new static();
                $storage->load(['uuid = ? AND key = ?', $uuid, $key]);
                
                if (!$storage->dry() && !empty($storage->expired_at)) {
                    $expired_timestamp = strtotime($storage->expired_at);
                    if ($expired_timestamp <= $now) {
                        static::delete_option($key);
                        unset($results[$key]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Error checking expiration in get_option_like: ' . $e->getMessage());
            }
        }
        
        return $results;
    }

    public static function delete_option(string $key): bool {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');
        return parent::_delete($uuid, $key);
    }

    public static function delete_option_like(string $key_pattern): bool {
        $atomic = App::instance();
        $uuid = $atomic->get('APP_UUID');
        return parent::_delete_like($uuid, $key_pattern);
    }

    public function __construct(...$args)
    {
        $atomic = App::instance();
        $prefix = $atomic->get('DB_CONFIG.prefix');
        $this->table = $prefix . $this->table;
        $this->fieldConf = array_merge($this->fieldConf, parent::get_field_conf());
        parent::__construct(...$args);
    }
}
