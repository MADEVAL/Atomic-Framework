# Rate Limiting

Atomic provides Redis-backed rate limiting under `Engine\Atomic\RateLimit`.

The system can be used in two ways:

- route middleware for HTTP endpoints
- direct `RateLimiter` calls for application workflows such as jobs, provider calls, and token quotas

The route middleware intentionally supports only simple bucket sources: IP, logged-in user, or route. Request-body keys such as email/login credentials are application-specific and should be implemented in the app layer.

## Requirements

`RateLimiter::from_config()` creates a Redis store by default. The store uses `ConnectionManager::instance()->get_redis(true)` and prefixes keys with:

```text
{REDIS.prefix or atomic:}rate_limit:
```

Make sure Redis is configured before enabling route rate limiting in production.

## Configuration

Register the middleware alias in `config/middleware.php`:

```php
return [
    'rate_limit' => Engine\Atomic\RateLimit\Middleware\RateLimitMiddleware::class,
];
```

Define named policies in `config/rate_limiter.php`:

```php
<?php
declare(strict_types=1);

use Engine\Atomic\RateLimit\Middleware\RateLimitMiddleware;
use Engine\Atomic\RateLimit\RateLimiter;

return [
    'fail' => RateLimiter::FAIL_OPEN,
    'policies' => [
        'default' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 60,
            'window'   => 60,
        ],
        'api' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 100,
            'window'   => 60,
        ],
        'user' => [
            'strategy' => RateLimiter::STRATEGY_SLIDING,
            'key'      => RateLimitMiddleware::KEY_USER,
            'limit'    => 1000,
            'window'   => 3600,
        ],
    ],
];
```

You can also set the same runtime config directly:

```php
$atomic->set(RateLimiter::CONFIG_ROOT, [
    'fail' => RateLimiter::FAIL_OPEN,
    'policies' => [
        'api' => [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key'      => RateLimitMiddleware::KEY_IP,
            'limit'    => 100,
            'window'   => 60,
        ],
    ],
]);
```

Environment config is also supported. Policy names are parsed from keys in this shape:

```dotenv
RATE_LIMITER_FAIL=open
RATE_LIMITER_API_STRATEGY=fixed
RATE_LIMITER_API_KEY=ip
RATE_LIMITER_API_LIMIT=100
RATE_LIMITER_API_WINDOW=60
```

## Route Middleware

Attach a named policy to a route:

```php
$atomic->route(
    'GET /api/items',
    App\Http\Controllers\ItemsController::class . '->index',
    ['rate_limit:api']
);
```

If no policy parameter is supplied, the middleware uses the `default` policy:

```php
$atomic->route('GET /status', App\Http\Controllers\StatusController::class . '->show', ['rate_limit']);
```

## Policies

Each policy supports these fields:

| Field | Description |
| --- | --- |
| `strategy` | One of `fixed`, `sliding`, `cooldown`, or `concurrency`. |
| `key` | One of `ip`, `user`, or `route`. |
| `limit` | Maximum hits or active slots. Use `1` for cooldown policies. |
| `window` | Time window or slot TTL, in seconds. |

### Strategies

`fixed` counts hits during a TTL window. A policy with `limit => 100` and `window => 60` allows 100 hits per key every 60 seconds. It is simple and cheap, but bursts can happen around the window boundary.

`sliding` uses a rolling time window instead of a fixed TTL bucket. This avoids a large burst at the boundary between two fixed windows. Use it when smoother throttling matters more than minimal Redis work.

`cooldown` allows one successful hit per key per window. Use it for expensive actions such as sending an email, regenerating a report, or starting a costly external job. Usually `limit` should be `1`.

`concurrency` limits active work. The middleware acquires a slot before the controller runs and registers a shutdown handler to release the slot. The `window` field is the fallback TTL if the process exits before release. Use it for long-running work, not normal request-rate throttling.

## Keys

The middleware builds keys in this format:

```text
{policy}:{route-pattern}:{identifier}
```

The route pattern comes from `PATTERN`; `/` becomes `root`.

Key sources:

| Key source | Identifier |
| --- | --- |
| `RateLimitMiddleware::KEY_IP` | `IP`, then `$_SERVER['REMOTE_ADDR']`, then `unknown`. |
| `RateLimitMiddleware::KEY_USER` | `SESSION.user.id`, then `SESSION.user_id`, then `guest`. |
| `RateLimitMiddleware::KEY_ROUTE` | The normalized route pattern. |

Because the route pattern is always part of the key, two routes using the same policy and identifier still get separate middleware buckets.

### Choosing a key source

Use `KEY_IP` for public endpoints:

```php
'login_page_or_public_api' => [
    'strategy' => RateLimiter::STRATEGY_FIXED,
    'key'      => RateLimitMiddleware::KEY_IP,
    'limit'    => 60,
    'window'   => 60,
],
```

This gives each IP address its own bucket. Be aware that NAT, offices, mobile carriers, and proxies can cause many real users to share one IP.

Use `KEY_USER` only for authenticated endpoints:

```php
'account_exports' => [
    'strategy' => RateLimiter::STRATEGY_COOLDOWN,
    'key'      => RateLimitMiddleware::KEY_USER,
    'limit'    => 1,
    'window'   => 300,
],
```

`KEY_USER` reads `SESSION.user.id`, then `SESSION.user_id`. If neither exists, the identifier is `guest`. That means all unauthenticated visitors share the same `guest` bucket, so one anonymous user can block other anonymous users. Do not use `KEY_USER` on public routes unless that behavior is intentional.

Use `KEY_ROUTE` for a global endpoint cap:

```php
'global_search' => [
    'strategy' => RateLimiter::STRATEGY_FIXED,
    'key'      => RateLimitMiddleware::KEY_ROUTE,
    'limit'    => 1000,
    'window'   => 60,
],
```

This makes all callers share one bucket for the route. Use it to protect expensive shared resources, not to enforce per-client fairness.

Unsupported key sources are configuration errors. The middleware does not fall back to IP for unknown values.

## Responses

Allowed requests continue to the controller and receive these headers when headers have not already been sent:

```text
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
```

Blocked requests return HTTP `429 Too Many Requests` as a JSON error and may include:

```text
Retry-After: 42
```

The JSON payload includes a `retry_after` value from the rate limit result.

## Failure Mode

Middleware catches Redis/store/runtime exceptions. If `RATE_LIMITER.fail` is `RateLimiter::FAIL_OPEN`, the request is allowed when rate limiting fails:

```php
'fail' => RateLimiter::FAIL_OPEN,
```

Any other value causes middleware to fail closed and stop the request when rate limiting throws.

Invalid policy configuration, such as an unsupported `key`, is treated as a configuration error and stops the request. It does not use the fail-open runtime fallback.

## Direct Usage

Use `RateLimiter` directly when the limit is not tied to a route middleware check:

```php
use Engine\Atomic\RateLimit\RateLimiter;

$limiter = RateLimiter::from_config();

$result = $limiter->fixed('imports:user:123', 10, 3600);
if (!$result->allowed) {
    throw new RuntimeException("Try again in {$result->retry_after} seconds.");
}
```

Available methods:

| Method | Use |
| --- | --- |
| `fixed($key, $limit, $ttl)` | Fixed-window hit counter. |
| `sliding($key, $limit, $window)` | Sliding-window hit counter. |
| `cooldown($key, $seconds)` | One hit per cooldown period. |
| `acquire($key, $limit, $ttl)` | Acquire a concurrency slot. |
| `release($key)` | Release a concurrency slot acquired directly. |
| `store()->clear($key)` | Clear stored state for a key. |

When using `acquire()` directly, release the slot yourself:

```php
$result = $limiter->acquire('exports:user:123', 2, 300);
if (!$result->allowed) {
    throw new RuntimeException('Too many active exports.');
}

try {
    // Run export.
} finally {
    $limiter->release('exports:user:123');
}
```

## Limitations

v1.0 route middleware does not support:

- request-body keys such as `email`, `username`, or `phone`
- query/header-derived keys
- composite policies in a single middleware entry
- automatic normalizers
- per-route custom key callbacks

Use direct `RateLimiter` calls or app-specific middleware for those cases.

## Auth Rate Limits

`AuthService` does not include built-in credential throttling in v1.0.

For simple public auth routes, use IP-based route middleware:

```php
$atomic->route('POST /login', App\Http\Controllers\AuthController::class . '->login', ['rate_limit:auth_login_ip']);
```

For stronger auth protection, implement app-specific middleware/service logic that checks both:

- IP bucket, e.g. `auth:login:ip:{hash(ip)}`
- credential bucket, e.g. `auth:login:credential:{hash(normalized_email)}`

Do not put passwords, secrets, or tokens into rate-limit keys. Normalize identity fields such as email with `trim()` and lowercase before hashing.

## AI Token Quotas

Use the reservation pattern for AI providers or other metered token systems. Reserve an estimate before the provider call, then settle the reservation with the actual usage.

```php
use Engine\Atomic\RateLimit\RateLimiter;

$limiter = RateLimiter::from_config();
$quota_key = 'quota:user:123';
$reservation_key = 'reservation:request-uuid';

$limiter->add_quota($quota_key, 100000);

if (!$limiter->reserve_tokens($quota_key, $reservation_key, 1200, 300)) {
    throw new RuntimeException('Token quota exceeded');
}

try {
    // Call provider.
    $remaining = $limiter->settle_tokens($quota_key, $reservation_key, $actual_tokens);
} catch (Throwable $e) {
    $limiter->release_tokens($quota_key, $reservation_key);
    throw $e;
}
```

Quota behavior:

- `add_quota($key, $tokens, $ttl)` adds tokens and returns the new balance.
- `reserve_tokens()` fails if the active reservation id already exists or there is not enough quota.
- `settle_tokens()` refunds unused reserved tokens or charges overage, clamped at zero.
- `release_tokens()` refunds the reservation when the provider call is not completed.
- reservation ids should be unique per provider request.
