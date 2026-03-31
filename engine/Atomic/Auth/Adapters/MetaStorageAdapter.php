<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Models\Meta;

class MetaStorageAdapter
{
    public function set_meta(string $id, string $key, string $value): void
    {
        Meta::set_meta($id, $key, $value);
    }

    public function delete_meta(string $id, string $key): bool
    {
        return Meta::delete_meta($id, $key);
    }

    public function delete_meta_like(string $id, string $pattern): bool
    {
        return Meta::delete_meta_like($id, $pattern);
    }

    public function get_meta_like(string $id, string $pattern): array
    {
        return Meta::get_meta_like($id, $pattern) ?: [];
    }
}
