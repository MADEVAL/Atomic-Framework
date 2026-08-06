<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

use Engine\Atomic\Http\Response;

final class Application
{
    /** @var ServiceProvider[] */
    private array $providers = [];

    private bool $booted = false;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function container(): Container
    {
        return $this->container;
    }

    public function base(): \Base
    {
        return $this->container->get(F3Bridge::class)->base();
    }

    public function registerProvider(ServiceProvider $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->setContainer($this->container);
            $provider->register();
        }

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    public function run(): Response
    {
        if (!$this->booted) {
            $this->boot();
        }

        try {
            ob_start();
            $this->base()->run();
            $body = ob_get_clean();

            if (!empty($body)) {
                return Response::html($body, http_response_code() ?: 200);
            }

            return Response::empty();
        } catch (\Throwable $e) {
            return Response::html($e->getMessage(), 500);
        }
    }

    public function terminate(): void
    {
        $this->container->reset();
    }
}
