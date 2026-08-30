<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Response as CoreResponse;
use Engine\Atomic\Http\Response;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaResult;

/**
 * Charges one operation per request.
 *
 * Reserves before the handler runs, settles when it returns a success, and
 * releases when it throws or answers 5xx.
 *
 * process() only. The legacy handle() path returns bool and has no after-hook,
 * so it cannot settle or release, and it throws instead of charging silently.
 */
final class QuotaMiddleware implements MiddlewareInterface
{
    public const HEADER_RETRY_AFTER = 'Retry-After';
    public const HEADER_BALANCE = 'X-Quota-Balance';

    private const CONFIG_FAIL = QuotaLimiter::CONFIG_ROOT . '.fail';
    private const RESPONSE_QUOTA_EXCEEDED = 'Quota exceeded';
    private const RESPONSE_RETRY_AFTER = 'retry_after';
    private const RESPONSE_REASON = 'reason';
    private const USER_GUEST = 'guest';

    /** @var null|callable(mixed): mixed */
    private $subject;

    /** @param null|callable(mixed): mixed $subject resolves the request into the subject the limiter resolvers expect */
    public function __construct(private string $operation, ?callable $subject = null)
    {
        $this->subject = $subject;
    }

    public function handle(\Base $atomic): bool
    {
        throw new \LogicException(
            'QuotaMiddleware supports process() only. The handle() path cannot settle or release a reservation.'
        );
    }

    public function process(mixed $request, callable $next): Response
    {
        try {
            $limiter = QuotaLimiter::from_config();
            [$plan, $scope] = $limiter->resolve_subject($this->subject($request));
            $reservation_id = $limiter->reservation_id();
            $result = $limiter->quota_reserve($scope, $reservation_id, $plan, $this->operation, null);
        } catch (\InvalidArgumentException | \LogicException) {
            return Response::html('Quota misconfigured', 500);
        } catch (\Exception) {
            $fail_open = (string)App::instance()->get(self::CONFIG_FAIL) === QuotaLimiter::FAIL_OPEN;
            return $fail_open ? $next($request) : Response::html('Quota error', 500);
        }

        if (!$result->allowed) {
            return $this->deny($result);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $limiter->quota_release($scope, $reservation_id);
            throw $e;
        }

        if ($response->status() >= 500) {
            $limiter->quota_release($scope, $reservation_id);
            return $response;
        }

        $balance = $limiter->quota_settle($scope, $reservation_id);

        return $response->withHeader(self::HEADER_BALANCE, (string)$balance);
    }

    private function deny(QuotaResult $result): Response
    {
        $body = [
            'error' => self::RESPONSE_QUOTA_EXCEEDED,
            self::RESPONSE_REASON => $result->reason,
            self::RESPONSE_RETRY_AFTER => $result->retry_after,
        ];

        $response = Response::json($body, CoreResponse::STATUS_TOO_MANY_REQUESTS)
            ->withHeader(self::HEADER_BALANCE, (string)$result->balance);

        if ($result->retry_after !== null && $result->retry_after > 0) {
            $response = $response->withHeader(self::HEADER_RETRY_AFTER, (string)$result->retry_after);
        }

        return $response;
    }

    /**
     * The subject the two boot resolvers expect. Without a resolver of its own
     * the middleware falls back to whatever identifies the current session.
     */
    private function subject(mixed $request): mixed
    {
        if ($this->subject !== null) {
            return ($this->subject)($request);
        }

        $atomic = \Base::instance();

        return $atomic->get('SESSION.user')
            ?: (string)($atomic->get('SESSION.user_uuid') ?: $atomic->get('SESSION.user_id') ?: self::USER_GUEST);
    }

}
