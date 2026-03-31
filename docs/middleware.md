## Middleware ##

Atomic route middleware is handled by `Engine\Atomic\Core\Middleware\MiddlewareStack`.

### Middleware class

Each middleware must implement:

```php
use Engine\Atomic\Core\Middleware\MiddlewareInterface;

final class RequireAuth implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        if (!is_authenticated()) {
            $atomic->reroute('/login');
            return false;
        }

        return true;
    }
}
```

### Register aliases

Atomic loads aliases from `config/middleware.php` through `App::registerMiddleware()`:

```php
return [
    'auth' => App\Http\Middleware\RequireAuth::class,
    'role' => App\Http\Middleware\RequireRole::class,
];
```

### Attach middleware to routes

```php
$app->route('GET /dashboard', 'App\\Http\\Dashboard->index', ['auth']);
$app->route('GET /team/@id', 'App\\Http\\Team->show', ['auth', 'role:admin']);
```

The third argument is an array of aliases. Parameterized middleware uses the `name:param` format.

### How it works

- `MiddlewareStack::forRoute()` stores middleware by URL pattern
- `MiddlewareStack::resolve()` instantiates aliases and injects the optional single parameter
- `MiddlewareStack::runForRoute()` executes the chain for the current matched route pattern

Returning `false` stops controller execution. In practice most middleware abort by calling `reroute()` or a JSON response helper and then returning `false`.
