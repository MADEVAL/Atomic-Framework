# Quota

Credit balances and per-subject pacing live in `Engine\Atomic\Quota`, separate from hit throttling in `Engine\Atomic\RateLimit`.

A quota is a balance the application spends. The balance counts whatever unit you choose — requests, exports, uploads, minutes, credits. The framework never decides what a unit means and never refills a balance on its own.

`QuotaLimiter::from_config()` uses the same Redis connection as rate limiting, under its own `quota.` key prefix.

A step-through of Redis keys (grant → reserve → deny → settle → release) is in [`docs/visuals/quota-redis-reserve-chain.html`](visuals/quota-redis-reserve-chain.html). Open that file in a browser (GitHub’s file view is source only). The same kit lives in [`docs/visuals/`](visuals/README.md). A Cursor Canvas copy remains in [`docs/canvases/`](canvases/quota-redis-reserve-chain.canvas.tsx).

## Requirements

Redis must be configured before using quota in production. Keys are prefixed with:

```text
{REDIS.prefix or atomic.}quota.
```

Every quota key starts with that prefix and nothing else does. After it the keys are:

| Key | Purpose |
|-----|---------|
| `{scope}.balance` | The balance. |
| `{scope}.epoch` | The uid of the current period. |
| `{scope}.reservation.{reservation_id}` | One live reservation. |
| `{scope}.pacing._tier.{window}` | A pacing window of the tier. |
| `{scope}.pacing.{operation}.{window}` | A pacing window of one operation. |
| `{scope}.pacing.….used` | The running total of a pacing window. |

The separator is `.` everywhere. The braces are the Redis Cluster hash tag around the scope, so one Lua call never spans two slots. A scope you supply with `:` inside it, such as `user:1`, keeps that character — pick `user.1` if you want `.` throughout.

## Configuration

Register quota in `config/quota.php` (PHP loader) or via `.env` keys (ENV loader). Declare every operation name once, then give each tier its own numbers. Every key is required. Leave `operations` and `quotas` empty until you need credit billing.

**PHP** (`config/quota.php`):

```php
<?php
declare(strict_types=1);

use Engine\Atomic\Quota\QuotaLimiter;

return [
    'fail' => QuotaLimiter::FAIL_OPEN,

    'operations' => ['spam_check', 'seo_headline'],

    'quotas' => [
        'free' => [
            'credits'         => 500,
            'period'          => 2592000,
            'reservation_ttl' => 300,
            'pacing'          => [
                ['limit' => 20, 'window' => 60],
            ],
            'operations' => [
                'spam_check' => ['cost' => 1],
            ],
        ],
        'pro' => [
            'credits'         => 20000,
            'period'          => 2592000,
            'reservation_ttl' => 300,
            'pacing'          => [
                ['limit' => 200,  'window' => 60],
                ['limit' => 2000, 'window' => 86400],
            ],
            'operations' => [
                'spam_check'   => ['cost' => 1],
                'seo_headline' => [
                    'cost'   => 5,
                    'pacing' => [['limit' => 30, 'window' => 3600]],
                ],
            ],
        ],
    ],
];
```

**ENV** (same shape, flat keys — tier and operation names are uppercased):

```env
QUOTA_FAIL=open
QUOTA_OPERATIONS=spam_check,seo_headline

QUOTA_FREE_CREDITS=500
QUOTA_FREE_PERIOD=2592000
QUOTA_FREE_RESERVATION_TTL=300
QUOTA_FREE_PACING_0_LIMIT=20
QUOTA_FREE_PACING_0_WINDOW=60
QUOTA_FREE_OP_SPAM_CHECK_COST=1

QUOTA_PRO_CREDITS=20000
QUOTA_PRO_PERIOD=2592000
QUOTA_PRO_RESERVATION_TTL=300
QUOTA_PRO_PACING_0_LIMIT=200
QUOTA_PRO_PACING_0_WINDOW=60
QUOTA_PRO_PACING_1_LIMIT=2000
QUOTA_PRO_PACING_1_WINDOW=86400
QUOTA_PRO_OP_SPAM_CHECK_COST=1
QUOTA_PRO_OP_SEO_HEADLINE_COST=5
QUOTA_PRO_OP_SEO_HEADLINE_PACING_0_LIMIT=30
QUOTA_PRO_OP_SEO_HEADLINE_PACING_0_WINDOW=3600
```

Key patterns:

| Piece | Pattern |
|-------|---------|
| Failure mode | `QUOTA_FAIL` (default `open`) |
| Operations list | `QUOTA_OPERATIONS` (CSV) |
| Tier scalars | `QUOTA_{TIER}_{CREDITS\|PERIOD\|RESERVATION_TTL}` |
| Tier pacing | `QUOTA_{TIER}_PACING_{N}_{LIMIT\|WINDOW}` |
| Operation cost | `QUOTA_{TIER}_OP_{OP}_COST` |
| Operation pacing | `QUOTA_{TIER}_OP_{OP}_PACING_{N}_{LIMIT\|WINDOW}` |

Without `QUOTA_OPERATIONS`, both `operations` and `quotas` stay empty.

The ENV loader finds a tier from its `CREDITS`, `PERIOD`, or `RESERVATION_TTL` key. It lowercases the tier and operation names again, so `QUOTA_PRO_OP_SEO_HEADLINE_COST` becomes the operation `seo_headline` of the tier `pro`.

An operation absent from a tier is denied for that tier.

The name `_tier` is reserved for the pacing key of the tier. An operation cannot use it.

Tier pacing is shared. All operations of one subject count into the same tier windows. Operation pacing is separate per operation. A reserve must pass every window of both sets.

## Boot

Register two resolvers once, during bootstrap.

```php
$limiter = QuotaLimiter::from_config();
$limiter->resolve_plan_using(fn($subject) => $subject->plan);
$limiter->resolve_scope_using(fn($subject) => "user:{$subject->id}");
```

## Grant the balance

The application decides when a period starts.

```php
$plan = $limiter->plan($user->plan);
$limiter->quota_set("user:{$user->id}", $plan->credits, $plan->period); // renewal
$limiter->quota_add("user:{$user->id}", 500, $plan->period);            // top-up
```

`quota_set` overwrites the balance, starts a new period, and writes a new epoch
uid. Release refunds only when that uid still matches, so a stale reservation
cannot invent credit into a later grant. `quota_add` adds credit and leaves the
end of the current period where it is. Its `$ttl` applies only when no balance
exists yet. `quota_clear` drops the balance, the epoch, pacing windows, and live
reservations for that scope.

Two read methods do not change anything. `quota_get($scope)` returns the balance. `quota_ttl($scope)` returns the seconds left in the period.

`QuotaLimiter::use_store($store)` replaces the store every later `from_config()` call hands out. `QuotaLimiter::reset()` drops the store and both boot resolvers. Use them to run tests without Redis, or to install another driver at boot.

## Spend it

`meter()` reserves before the work, settles after it returns, and releases when it throws.
It mints a fresh reservation id each time. The raw `quota_reserve($scope, $id, …)`
call is a new paced spend even when `$id` is reused after settle: the balance
decreases and the window counts it. While the reservation hash still exists, the
same id is `duplicate_reservation` and is not charged.

```php
use Engine\Atomic\Quota\Exceptions\QuotaExceededException;
use Engine\Atomic\Quota\QuotaLimiter;

$limiter = QuotaLimiter::from_config();

$result = $limiter->meter($user, 'spam_check', fn() => $classifier->call($comment));
```

`quota_reserve()` takes an optional last argument `$cost`. It overrides the cost of the operation in config for that one call. Pass `null` to use the configured cost.

Each `QuotaResult` carries a `reason`:

| Reason | Meaning |
|--------|---------|
| `ok` | Allowed. The balance is charged. |
| `insufficient_balance` | The balance is smaller than the cost. |
| `pacing` | A pacing window is full. `limited_by` names the key of that window. |
| `not_entitled` | The tier does not declare the operation. |
| `duplicate_reservation` | The reservation hash for that id still exists. Nothing is charged. |

`retry_after` is the TTL of the balance for `insufficient_balance`, and the seconds until the oldest member of the window expires for `pacing`. It is `null` for `not_entitled` and `duplicate_reservation`.

Catch the denial and answer with `retry_after`:

```php
try {
    $result = $limiter->meter($user, 'spam_check', fn() => $classifier->call($comment));
} catch (QuotaExceededException $e) {
    return Response::json([
        'error'       => 'Quota exceeded',
        'reason'      => $e->result->reason,
        'retry_after' => $e->result->retry_after,
    ], 429);
}
```

To charge one operation per request, add the middleware instead:

```php
use Engine\Atomic\Quota\Middleware\QuotaMiddleware;

$kernel->appendMiddleware(new QuotaMiddleware('spam_check'));
```

The middleware reserves before the handler, and then:

- settles when the handler returns a status below 500
- releases when the handler throws, and rethrows
- releases when the handler answers 500 or higher, and returns that response

Every answer carries an `X-Quota-Balance` header. A denial answers 429 with the reason and `retry_after` in the JSON body, plus a `Retry-After` header when `retry_after` is above 0.

A bad config throws `InvalidArgumentException` or `LogicException`. The middleware turns those into `500 Quota misconfigured`. It never fails open on them. Any other exception, such as a Redis failure, follows `QUOTA.fail`: `QuotaLimiter::FAIL_OPEN` lets the request through, any other value answers `500 Quota error`.

The second constructor argument turns the request into the subject that both boot resolvers expect:

```php
$kernel->appendMiddleware(new QuotaMiddleware('spam_check', fn($request) => $request->user()));
```

Without it the middleware reads `SESSION.user`, then `SESSION.user_uuid`, then `SESSION.user_id`, then the string `guest`.

`QuotaMiddleware` runs in the `HttpKernel` pipeline only. It needs `process()`,
which wraps the handler, so it can settle after the handler returns and release
when the handler throws. The route middleware list resolves alias strings and
calls `handle()`, which has no after-hook, so `QuotaMiddleware::handle()` throws.
To charge one route and nothing else, call `meter()` inside that handler.

## Why the Application Supplies These

**The two boot resolvers.** The framework cannot know which tier a subject is on or which key charges them. That fact lives in your own records. You declare the mapping once instead of repeating it at every call site.

**`quota_set` and `quota_add`.** The framework never refills a balance. A period is anchored to a business event. No TTL can know when one of those happens, so any automatic refill would be a guess. You call these when the event occurs.

**Every config key.** There are no defaults and no inheritance. A tier missing any key throws when `plan()` reads it.

## Known Limitations

- The cost of an operation is a fixed number declared in config. It is decided before the work runs and does not change afterwards.
- There is no way to report the real cost back once the work finishes.
- The balance counts a unit the application defines. It does not reconcile against any external system's accounting.
- Single Redis node only. Keys are hash-tagged so a Cluster driver stays possible, but Cluster is not supported.
- `QuotaMiddleware` charges once per request. A handler that does the work more than once must call `meter()` per unit of work.
- If a reservation expires before it settles, the charge stands.
- Reusing a reservation id after settle is a new paced spend, not a silent retry.
- A Lua pacing member collision (`id:nonce:cost` already in the window) aborts the reserve: no charge, no reservation hash.
- Pacing zset TTL is `max(window, now + window − Redis TIME)` so the key cannot expire before trim would drop the last live member. Trim still uses the supplied `now`.
- `:used` is a cache. It is rebuilt only when missing or negative. It is not reconciled against the zset on every reserve.
- `QuotaMiddleware` works in the `HttpKernel` pipeline only, not in a route middleware list.
- Quota config works in both loaders. ENV uses the flat key patterns documented above; omit `QUOTA_OPERATIONS` to leave quotas disabled.
