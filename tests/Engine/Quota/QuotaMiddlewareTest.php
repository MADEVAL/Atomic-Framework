<?php
declare(strict_types=1);

namespace Tests\Engine\Quota;

use Engine\Atomic\Http\Response;
use Engine\Atomic\Quota\Interfaces\QuotaStoreInterface;
use Engine\Atomic\Quota\Middleware\QuotaMiddleware;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuotaSubject;
use Tests\Support\TestQuotaStore;

final class QuotaMiddlewareTest extends TestCase
{
    private TestQuotaStore $store;

    protected function setUp(): void
    {
        $this->store = new TestQuotaStore();
        QuotaLimiter::use_store($this->store);

        \Base::instance()->set('QUOTA', [
            'fail' => QuotaLimiter::FAIL_OPEN,
            'operations' => ['spam_check'],
            'quotas' => [
                'pro' => [
                    'credits' => 100,
                    'period' => 600,
                    'reservation_ttl' => 300,
                    'pacing' => [['limit' => 10, 'window' => 60]],
                    'operations' => ['spam_check' => ['cost' => 5]],
                ],
            ],
        ]);

        $limiter = QuotaLimiter::from_config();
        $limiter->resolve_plan_using(static fn(): string => 'pro');
        $limiter->resolve_scope_using(static fn(): string => 'user:1');
        $limiter->quota_set('user:1', 100, 600);
    }

    protected function tearDown(): void
    {
        QuotaLimiter::reset();
        \Base::instance()->clear('QUOTA');
    }

    public function test_a_successful_response_settles_and_keeps_the_charge(): void
    {
        $response = $this->middleware()->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
        $this->assertSame('95', $response->header(QuotaMiddleware::HEADER_BALANCE));
        $this->assertSame(95, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_thrown_exception_releases_the_reservation_and_rethrows(): void
    {
        try {
            $this->middleware()->process(null, static function (): never {
                throw new \RuntimeException('handler exploded');
            });
            $this->fail('Expected the handler exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('handler exploded', $e->getMessage());
        }

        $this->assertSame(100, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_server_error_releases_the_reservation(): void
    {
        $response = $this->middleware()->process(null, static fn(): Response => Response::html('boom', 500));

        $this->assertSame(500, $response->status());
        $this->assertSame(100, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_client_error_still_keeps_the_charge(): void
    {
        $response = $this->middleware()->process(null, static fn(): Response => Response::html('bad input', 422));

        $this->assertSame(422, $response->status());
        $this->assertSame(95, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_status_below_500_settles_the_reservation(): void
    {
        $response = $this->middleware()->process(null, static fn(): Response => Response::html('cancelled', 499));

        $this->assertSame(499, $response->status());
        $this->assertSame(95, $this->limiter()->quota_get('user:1'));
        $this->assertSame('95', $response->header(QuotaMiddleware::HEADER_BALANCE));
    }

    public function test_an_insufficient_balance_denies_with_429_and_a_retry_after_header(): void
    {
        $this->limiter()->quota_set('user:1', 1, 600);
        $reached = false;

        $response = $this->middleware()->process(null, static function () use (&$reached): Response {
            $reached = true;
            return Response::text('ok');
        });

        $this->assertFalse($reached);
        $this->assertSame(429, $response->status());
        $this->assertSame('600', $response->header(QuotaMiddleware::HEADER_RETRY_AFTER));
        $this->assertSame('1', $response->header(QuotaMiddleware::HEADER_BALANCE));
        $body = json_decode($response->body(), true);
        $this->assertIsArray($body);
        $this->assertSame('Quota exceeded', $body['error']);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $body['reason']);
        $this->assertSame(600, $body['retry_after']);
        $this->assertSame(1, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_pacing_denial_answers_429_with_a_retry_after_header(): void
    {
        $middleware = $this->middleware();
        $next = static fn(): Response => Response::text('ok');

        $this->assertSame(200, $middleware->process(null, $next)->status());
        $this->assertSame(200, $middleware->process(null, $next)->status());

        $denied = $middleware->process(null, $next);

        $this->assertSame(429, $denied->status());
        $this->assertNotNull($denied->header(QuotaMiddleware::HEADER_RETRY_AFTER));
        $this->assertStringContainsString(QuotaResult::REASON_PACING, $denied->body());
        $this->assertSame(90, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_not_entitled_operation_denies_without_a_retry_after_header(): void
    {
        $atomic = \Base::instance();
        $config = (array)$atomic->get('QUOTA');
        $config['operations'] = ['spam_check', 'seo_headline'];
        $atomic->set('QUOTA', $config);

        $response = (new QuotaMiddleware('seo_headline'))->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(429, $response->status());
        $this->assertNull($response->header(QuotaMiddleware::HEADER_RETRY_AFTER));
        $this->assertStringContainsString(QuotaResult::REASON_NOT_ENTITLED, $response->body());
        $this->assertSame(100, $this->limiter()->quota_get('user:1'));
    }

    public function test_the_legacy_handle_path_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('process() only');

        try {
            $this->middleware()->handle(\Base::instance());
        } finally {
            $this->assertSame(100, $this->limiter()->quota_get('user:1'));
        }
    }

    public function test_an_unknown_operation_answers_500_without_charging(): void
    {
        $response = (new QuotaMiddleware('seo_headlines'))->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(500, $response->status());
        $this->assertSame('Quota misconfigured', $response->body());
        $this->assertSame(100, $this->limiter()->quota_get('user:1'));
    }

    public function test_a_store_exception_fail_open_runs_the_handler_without_charging(): void
    {
        QuotaLimiter::use_store(new ThrowingQuotaStore($this->store));
        $reached = false;

        $response = $this->middleware()->process(null, static function () use (&$reached): Response {
            $reached = true;
            return Response::text('ok');
        });

        $this->assertTrue($reached);
        $this->assertSame(200, $response->status());
        $this->assertSame(100, $this->store->get('{user:1}.balance'));
    }

    public function test_a_store_exception_fail_closed_answers_500_without_running_the_handler(): void
    {
        $atomic = \Base::instance();
        $config = (array)$atomic->get('QUOTA');
        $config['fail'] = 'closed';
        $atomic->set('QUOTA', $config);
        QuotaLimiter::use_store(new ThrowingQuotaStore($this->store));
        $reached = false;

        $response = $this->middleware()->process(null, static function () use (&$reached): Response {
            $reached = true;
            return Response::text('ok');
        });

        $this->assertFalse($reached);
        $this->assertSame(500, $response->status());
        $this->assertSame('Quota error', $response->body());
        $this->assertSame(100, $this->store->get('{user:1}.balance'));
    }

    public function test_the_default_subject_charges_guest_when_the_session_is_empty(): void
    {
        $limiter = $this->limiter();
        $limiter->resolve_scope_using(static fn(mixed $subject): string => is_object($subject) ? 'user:' . $subject->id : (string)$subject);
        $limiter->quota_set('guest', 100, 600);
        \Base::instance()->clear('SESSION');

        $response = $this->middleware()->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(200, $response->status());
        $this->assertSame(95, $limiter->quota_get('guest'));
        $this->assertSame(100, $limiter->quota_get('user:1'));
    }

    public function test_a_missing_resolver_answers_500(): void
    {
        QuotaLimiter::reset();
        QuotaLimiter::use_store($this->store);

        $response = $this->middleware()->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(500, $response->status());
        $this->assertSame('Quota misconfigured', $response->body());
        $this->assertSame(100, $this->store->get('{user:1}.balance'));
    }

    public function test_a_custom_subject_resolver_is_used(): void
    {
        $limiter = $this->limiter();
        $limiter->resolve_scope_using(static fn(QuotaSubject $s): string => 'user:' . $s->id);
        $limiter->quota_set('user:9', 100, 600);

        $middleware = new QuotaMiddleware('spam_check', static fn(): QuotaSubject => new QuotaSubject(9, 'pro'));
        $middleware->process(null, static fn(): Response => Response::text('ok'));

        $this->assertSame(95, $limiter->quota_get('user:9'));
        $this->assertSame(100, $limiter->quota_get('user:1'));
    }

    private function middleware(): QuotaMiddleware
    {
        return new QuotaMiddleware('spam_check');
    }

    private function limiter(): QuotaLimiter
    {
        return QuotaLimiter::from_config();
    }
}

final class ThrowingQuotaStore implements QuotaStoreInterface
{
    public function __construct(private QuotaStoreInterface $inner) {}

    public function clear(string $key): void
    {
        $this->inner->clear($key);
    }

    public function clear_scope(string $scope): void
    {
        $this->inner->clear_scope($scope);
    }

    public function get(string $key): int
    {
        return $this->inner->get($key);
    }

    public function get_string(string $key): string
    {
        return $this->inner->get_string($key);
    }

    public function ttl(string $key): int
    {
        return $this->inner->ttl($key);
    }

    public function set_quota(string $balance_key, string $epoch_key, int $credits, int $ttl): int
    {
        return $this->inner->set_quota($balance_key, $epoch_key, $credits, $ttl);
    }

    public function add_quota(string $balance_key, int $credits, int $ttl): int
    {
        return $this->inner->add_quota($balance_key, $credits, $ttl);
    }

    public function quota_reserve(
        string $balance_key,
        string $epoch_key,
        string $reservation_key,
        string $reservation_id,
        int $cost,
        int $reservation_ttl,
        array $pacing,
        int $now
    ): QuotaResult {
        throw new \RuntimeException('store failed');
    }

    public function quota_settle(string $balance_key, string $reservation_key): int
    {
        return $this->inner->quota_settle($balance_key, $reservation_key);
    }

    public function quota_release(string $balance_key, string $epoch_key, string $reservation_key, string $reservation_id): int
    {
        return $this->inner->quota_release($balance_key, $epoch_key, $reservation_key, $reservation_id);
    }
}
