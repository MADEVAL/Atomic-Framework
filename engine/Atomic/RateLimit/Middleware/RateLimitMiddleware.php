<?php
declare(strict_types=1);
namespace Engine\Atomic\RateLimit\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Response;
use Engine\Atomic\RateLimit\RateLimiter;
use Engine\Atomic\RateLimit\RateLimitResult;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public const POLICY_DEFAULT = 'default';

    public const KEY_IP = 'ip';
    public const KEY_USER = 'user';
    public const KEY_ROUTE = 'route';

    public const HEADER_LIMIT = 'X-RateLimit-Limit';
    public const HEADER_REMAINING = 'X-RateLimit-Remaining';
    public const HEADER_RETRY_AFTER = 'Retry-After';

    private const CONFIG_FAIL = RateLimiter::CONFIG_ROOT . '.fail';
    private const CONFIG_POLICIES = RateLimiter::CONFIG_ROOT . '.policies.';
    private const RESPONSE_TOO_MANY_REQUESTS = 'Too many requests';
    private const RESPONSE_RETRY_AFTER = 'retry_after';
    private const PATH_ROOT = 'root';
    private const USER_GUEST = 'guest';
    private const IP_UNKNOWN = 'unknown';

    public function __construct(private string $policy = self::POLICY_DEFAULT) {}

    public function handle(\Base $atomic): bool
    {
        try {
            $limiter = RateLimiter::from_config();
            $result = $this->check($limiter, $atomic);
        } catch (\InvalidArgumentException) {
            return false;
        } catch (\Throwable) {
            return (string)App::instance()->get(self::CONFIG_FAIL) === RateLimiter::FAIL_OPEN;
        }

        $this->headers($result);
        if ($result->allowed) {
            return true;
        }

        Response::instance()->send_json_error(self::RESPONSE_TOO_MANY_REQUESTS, Response::STATUS_TOO_MANY_REQUESTS, [
            self::RESPONSE_RETRY_AFTER => $result->retry_after,
        ]);
        return false;
    }

    private function check(RateLimiter $limiter, \Base $atomic): RateLimitResult
    {
        $config = (array)App::instance()->get(self::CONFIG_POLICIES . $this->policy);
        $strategy = (string)$config['strategy'];
        $key = $this->key($atomic, (string)$config['key']);
        $limit = (int)$config['limit'];
        $window = (int)$config['window'];

        $result = match ($strategy) {
            RateLimiter::STRATEGY_SLIDING => $limiter->sliding($key, $limit, $window),
            RateLimiter::STRATEGY_COOLDOWN => $limiter->cooldown($key, $window),
            RateLimiter::STRATEGY_CONCURRENCY => $limiter->acquire($key, $limit, $window),
            default => $limiter->fixed($key, $limit, $window),
        };

        if ($strategy === RateLimiter::STRATEGY_CONCURRENCY && $result->allowed) {
            register_shutdown_function(static fn() => $limiter->release($key));
        }

        return $result;
    }

    private function key(\Base $atomic, string $source): string
    {
        $path = trim((string)$atomic->get('PATTERN'), '/') ?: self::PATH_ROOT;
        $id = match ($source) {
            self::KEY_IP => (string)($atomic->get('IP') ?: $_SERVER['REMOTE_ADDR'] ?? self::IP_UNKNOWN),
            self::KEY_USER => (string)($atomic->get('SESSION.user.id') ?: $atomic->get('SESSION.user_id') ?: self::USER_GUEST),
            self::KEY_ROUTE => $path,
            default => throw new \InvalidArgumentException("Unsupported rate limit key source: {$source}"),
        };

        return $this->policy . ':' . $path . ':' . $id;
    }

    private function headers(RateLimitResult $result): void
    {
        if (headers_sent()) {
            return;
        }

        header(self::HEADER_LIMIT . ': ' . $result->limit);
        header(self::HEADER_REMAINING . ': ' . $result->remaining);
        if (!$result->allowed && $result->retry_after > 0) {
            header(self::HEADER_RETRY_AFTER . ': ' . $result->retry_after);
        }
    }
}
