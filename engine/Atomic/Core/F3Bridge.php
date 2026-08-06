<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

final class F3Bridge
{
    private \Base $base;

    public function __construct(?\Base $base = null)
    {
        $this->base = $base ?? \Base::instance();
    }

    public function base(): \Base
    {
        return $this->base;
    }

    public function get(string $key): mixed
    {
        return $this->base->get($key);
    }

    public function set(string $key, mixed $val): void
    {
        $this->base->set($key, $val);
    }

    public function clear(string $key): void
    {
        $this->base->clear($key);
    }

    public function exists(string $key): bool
    {
        return $this->base->exists($key);
    }

    /** @return array<string, mixed> */
    public function hive(): array
    {
        return $this->base->hive();
    }

    /** @param array<string, mixed> $vars */
    public function mset(array $vars): void
    {
        $this->base->mset($vars);
    }

    public function ref(string $key): mixed
    {
        return $this->base->ref($key);
    }

    public function sync(string $key): void
    {
        $this->base->sync($key);
    }
}
