<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;
if (!defined('ATOMIC_START')) exit;

abstract class ServiceProvider
{
    protected Container $container;

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /** Р¤Р°Р·Р° 1: СЂРµРіРёСЃС‚СЂР°С†РёСЏ СЃРµСЂРІРёСЃРѕРІ */
    public function register(): void {}

    /** Р¤Р°Р·Р° 2: РїРѕСЃС‚-СЂРµРіРёСЃС‚СЂР°С†РёСЏ (РІСЃРµ СЃРµСЂРІРёСЃС‹ РґРѕСЃС‚СѓРїРЅС‹) */
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