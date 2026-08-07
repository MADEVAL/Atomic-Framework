<?php

declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Application;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Core\Router;
use Engine\Atomic\Http\Response;
use Engine\Atomic\Http\Request;

final class TestApplication
{
    private Container $container;
    private Application $app;
    private bool $booted = false;

    private function __construct(Container $container)
    {
        $this->container = $container;
        $this->container->singleton(F3Bridge::class, fn() => new F3Bridge(\Base::instance()));
        $this->container->singleton(Router::class, fn() => new Router($this->container));
        $this->app = new Application($this->container);
    }

    public static function boot(): self
    {
        Container::setGlobal(null);
        $container = new Container();
        Container::setGlobal($container);

        return new self($container);
    }

    public function shutdown(): void
    {
        $this->container->reset();
        Container::setGlobal(null);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    private function request(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        if (!$this->booted) {
            $this->app->boot();
            $this->booted = true;
        }

        $request = new Request(
            method: $method,
            uri: $uri,
            headers: $headers,
            body: $body,
        );

        // Set F3 hive for routing
        $base = $this->container->get(F3Bridge::class)->base();
        $base->set('VERB', $method);
        $base->set('PATH', $uri);
        $base->set('HEADERS', $headers);
        $base->set('POST', $body);
        $base->set('AJAX', ($headers['X-Requested-With'] ?? '') === 'XMLHttpRequest');

        $response = $this->app->run();

        return new TestResponse($response);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function f3(): \Base
    {
        return $this->container->get(F3Bridge::class)->base();
    }
}
