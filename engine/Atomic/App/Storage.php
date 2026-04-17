<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;

abstract class Storage extends Model
{
    protected $db = 'DB';
    protected $fieldConf = [
        'uuid' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'required' => true,
        ],
        'key' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'required' => true,
        ],
        'value' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'updated_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
    ];

    public function get_field_conf(): array
    {
        return $this->fieldConf;
    }

    protected static function _set(string $uuid, string $key, string $value, int $ttl = 0): bool
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to set: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            $existing = $storage->load(['uuid = ? AND key = ?', $uuid, $key]);
            $now = date('Y-m-d H:i:s');
            if (!$existing) {
                $storage = new static();
                $storage->uuid = $uuid;
                $storage->key = $key;
                $storage->created_at = $now;
            }
            $storage->updated_at = $now;
            if ($ttl > 0) $storage->expired_at = date('Y-m-d H:i:s', time() + $ttl);
            $storage->value = $value;
            $result = $storage->save();
            return $result !== false;
        } catch (\Throwable $e) {
            Log::error('Error in set: ' . $e->getMessage());
            return false;
        }
    }

    // TODO mb rm
    protected static function _has(string $uuid, string $key): bool
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to has: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            return $storage->load(['uuid = ? AND key = ?', $uuid, $key]) !== false;
        } catch (\Throwable $e) {
            Log::error('Error in has: ' . $e->getMessage());
            return false;
        }
    }

    protected static function _get(string $uuid, string $key): string|false
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to get: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            $storage->load(['uuid = ? AND key = ?', $uuid, $key]);
            if ($storage->dry()) return false;
            return $storage->value;
        } catch (\Throwable $e) {
            Log::error('Error in get: ' . $e->getMessage());
            return false;
        }
    }

    protected static function _get_like(string $uuid, string $key_pattern): array|false
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to _get_like: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            $results = $storage->find(['uuid = ? AND key LIKE ?', $uuid, $key_pattern]);
            if ($results === false) return false;
            $output = [];
            if ($results) {
                foreach ($results as $record) {
                    $output[$record->key] = $record->value;
                }
            }
            
            return $output;
        } catch (\Throwable $e) {
            Log::error('Error in _get_like: ' . $e->getMessage());
            return false;
        }
    }

    protected static function _delete(string $uuid, string $key): bool
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to delete: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            $storage->load(['uuid = ? AND key = ?', $uuid, $key]);
            return $storage->erase();
        } catch (\Throwable $e) {
            Log::error('Error in delete: ' . $e->getMessage());
            return false;
        }
    }

    protected static function _delete_like(string $uuid, string $key_pattern): bool
    {
        if (!ID::is_valid_uuid_v4($uuid)) {
            Log::error('Invalid UUID v4 provided to _delete_like: ' . $uuid);
            return false;
        }
        try {
            $storage = new static();
            return $storage->erase(['uuid = ? AND key LIKE ?', $uuid, $key_pattern]);
        } catch (\Throwable $e) {
            Log::error('Error in delete_like: ' . $e->getMessage());
            return false;
        }
    }
}
