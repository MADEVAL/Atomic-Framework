<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

abstract class ServiceProvider
{
    protected Container $container;

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /** Фаза 1: регистрация сервисов */
    public function register(): void {}

    /** Фаза 2: пост-регистрация (все сервисы доступны) */
    public function boot(): void {}

    /** @return class-string<ServiceProvider>[] */
    public function requires(): array
    {
        return [];
    }

    /** @return string[] */
    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }
}
