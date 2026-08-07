<?php
declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

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

        $ordered = $this->resolveOrder();

        foreach ($ordered as $provider) {
            $provider->setContainer($this->container);
            $provider->register();
        }

        foreach ($ordered as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    /** @return ServiceProvider[] */
    private function resolveOrder(): array
    {
        $byClass = [];
        foreach ($this->providers as $p) {
            $byClass[$p::class] = $p;
        }

        $inDegree = [];
        $deps = [];
        foreach ($this->providers as $p) {
            $class = $p::class;
            if (!isset($inDegree[$class])) {
                $inDegree[$class] = 0;
            }
            $deps[$class] = [];
            foreach ($p->requires() as $req) {
                if (isset($byClass[$req])) {
                    $deps[$req][] = $class;
                    $inDegree[$class] = ($inDegree[$class] ?? 0) + 1;
                }
            }
        }

        $queue = [];
        foreach ($this->providers as $p) {
            if (($inDegree[$p::class] ?? 0) === 0) {
                $queue[] = $p;
            }
        }

        $ordered = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $ordered[] = $current;
            foreach ($deps[$current::class] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $byClass[$dependent];
                }
            }
        }

        if (count($ordered) !== count($this->providers)) {
            error_log('[Atomic] Cycle detected in ServiceProvider dependencies. Falling back to registration order.', E_USER_WARNING);
            $ordered = $this->providers;
        }

        return $ordered;
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
