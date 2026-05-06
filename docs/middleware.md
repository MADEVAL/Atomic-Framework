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

Atomic loads aliases from `config/middleware.php` through `App::register_middleware()`:

```php
return [
    'auth' => App\Http\Middleware\RequireAuth::class,
    'role' => App\Http\Middleware\RequireRole::class,
];
```

Atomic also registers built-in aliases before user config is loaded:

- `access` → `Engine\Atomic\Core\Middleware\AccessMiddleware`
- `role` → `Engine\Atomic\Core\Middleware\RoleMiddleware`

### Attach middleware to routes

```php
$app->route('GET /dashboard', 'App\\Http\\Dashboard->index', ['auth']);
$app->route('GET /team/@id', 'App\\Http\\Team->show', ['auth', 'role:admin']);
$app->route('GET /ops', 'App\\Http\\Ops->index', ['access:ops', 'role:ops.viewer']);
```

The third argument is an array of aliases. Parameterized middleware uses the `name:param` format.

### How it works

- `MiddlewareStack::for_route()` stores middleware by URL pattern
- `MiddlewareStack::for_route()` also stores verb-aware keys (for example `POST /path`)
- `MiddlewareStack::resolve()` instantiates aliases and injects the optional single parameter
- `MiddlewareStack::run_for_route()` executes the chain for the current matched route pattern

Returning `false` stops controller execution. In practice most middleware abort by calling `reroute()` or a JSON response helper and then returning `false`.

### Config-backed access middleware

`access:<guard>` enables username/key login backed by config users loaded from
`ACCESS.guards.<guard>.users`.

Use it for framework tooling, diagnostics panels, internal dashboards, or any
route that should be protected without coupling to the application's normal user
provider. Config users are separate from app users. They are stored in
`storage/framework/access_users.php`, loaded into the hive by the config loaders,
and resolved by `Engine\Atomic\Auth\ConfigUserProvider`.

Typical route setup:

```php
$app->route('GET /ops', 'App\\Http\\Ops->index', [
    'access:ops',
    'role:ops.viewer',
]);

$app->route('POST /ops/retry', 'App\\Http\\Ops->retry', [
    'access:ops',
    'role:ops.admin',
]);
```

Flow:

1. `access:ops` switches auth to `ConfigUserProvider('ops')`.
2. If a config user is already authenticated, the request continues.
3. If not authenticated, browser requests receive a username/key form.
4. A successful form POST logs in the config user and redirects back to the requested local URL.
5. `role:ops.viewer` or `role:ops.admin` then checks the authenticated config user's role slugs.

Behavior:

- If already authenticated, request continues.
- For `POST`, middleware reads `POST.username` and one of `POST.key`, `POST.password`, or `POST.secret`.
- On successful login, it redirects (303) to a safe local path from `POST.redirect`.
- For HTML requests it renders a minimal sign-in form with hidden redirect.
- For JSON/API-style requests it returns `401 Unauthorized` JSON.

CLI commands (`access/user/create`, `access/user/reset`, `access/user/list`), stored
record shape, hashing, and name normalization:
[`telemetry.md#config-user-commands`](telemetry.md#config-user-commands).

Telemetry guard and roles:
[`telemetry.md#access`](telemetry.md#access).

### Built-in role middleware

`role:<slug>` checks `Guard::has_role($slug)`.

- For JSON/API-style requests it returns `403 Forbidden` JSON.
- For HTML requests it returns plain `Forbidden` text with HTTP 403.

Use `role:<slug>` by itself when the route should use the application's normal
auth system:

```php
$app->route('GET /admin/reports', 'App\\Http\\Reports->index', [
    'role:admin',
]);
```

The current user must implement `HasRolesInterface`; otherwise role checks fail.
Telemetry auth-mode details are documented in
[`telemetry.md#access`](telemetry.md#access).
