<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Api;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Order;
use Engine\Atomic\Plugins\Monopay\WebhookHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Tests\Support\StreamCapture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\FakeMonobankApi;
use Tests\Support\PlatformGuard;

/**
 * Stress tests for Monopay idempotency behaviour.
 *
 * Context: the Monobank Acquiring API does NOT document any server-side
 * idempotency mechanism (no "X-Idempotency-Key" header exists in
 * https://api.monobank.ua/docs/acquiring.html, verified 2026-09). Every
 * POST /api/merchant/invoice/create creates a NEW invoice, and webhooks
 * are delivered "up to 3 times until HTTP 200 OK" (webhook.md). Therefore
 * all idempotency must be implemented client-side by this plugin.
 *
 * These tests attack two flows:
 *  A) Order::create() — duplicate submits and retry-after-lost-response
 *  B) Webhook delivery — duplicate retries, out-of-order retries,
 *     status-API outages during verification, signature-flood amplification
 *
 * Tests marked BUG-DOCUMENTING assert the CORRECT behaviour but are
 * marked markTestSkipped("RED (bug documented ...)") so the suite stays
 * green while the bugs are open (per AGENTS.md the FAIL count must not
 * increase). Each skip message records the observed-vs-expected evidence;
 * unskip a test to get a red test for its fix, then flip it to a
 * regression guard once green.
 *
 * WebhookHandler::handle() terminates via Response::send_json_* (exit),
 * so the delivery sequence of the real handler is mirrored in-process
 * below in deliver_webhook(); test_webhook_handler_source_matches_
 * mirrored_sequence() guards that mirror against source drift.
 */
final class MonopayIdempotencyStressTest extends TestCase
{
    private Monopay $plugin;
    private FakeMonobankApi $mono;

    /** @var array<string, list<list<mixed>>> hook tag => collected invocations */
    private array $hook_events = [];

    /** @var array<string, callable> hook tag => listener closure (for removal) */
    private array $hook_listeners = [];

    public static function setUpBeforeClass(): void
    {
        PlatformGuard::requireExtension('openssl');
    }

    protected function setUp(): void
    {
        if (\Base::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }

        $this->create_plugin_tables();
        $this->clear_plugin_tables();

        $this->mono = new FakeMonobankApi('test-token');
        $this->plugin = $this->make_configured_plugin($this->mono);
        $this->hook_events = [];
        $this->hook_listeners = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->hook_listeners as $tag => $closure) {
            Hook::instance()->remove_action($tag, $closure);
        }
        $this->hook_listeners = [];

        $this->clear_plugin_tables();
    }

    // ==================================================================
    // A) Invoice creation idempotency (Order::create)
    // ==================================================================

    /**
     * Characterization: two independent create_payment() calls (double
     * click, two tabs, two racing workers) produce two separate invoices
     * on monobank and two local payment rows. There is no intent-level
     * dedup and no way for the caller to pass an idempotency token, so
     * every duplicate submit is independently billable.
     */
    public function test_create_payment_twice_creates_two_separate_invoices(): void
    {
        $first = $this->plugin->create_payment(4200.00, 'Order #1');
        $second = $this->plugin->create_payment(4200.00, 'Order #1');

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame(2, $this->mono->invoice_count(), 'Each create() call hits invoice/create — no client-side dedup exists.');
        $this->assertNotSame($first['data']['invoiceId'], $second['data']['invoiceId']);
        $this->assertSame(2, $this->count_payment_rows());
    }

    /**
     * Finding 2 regression guard: retry after a lost create response.
     *
     * The Monobank Acquiring API has no idempotency mechanism and no way to
     * look up an invoice by reference, so the invoice monobank created
     * during the lost first attempt stays hidden forever — a guarantee of
     * "exactly one invoice on the monobank side" is not implementable.
     * What the plugin CAN guarantee (and what this test pins) when the
     * caller supplies the same idempotency key on both attempts:
     *
     *  1. a transport-level failure (status 0 / 5xx) KEEPS the preliminary
     *     payment row — its reference is the only client-side dedup anchor;
     *  2. the retry resumes the SAME reference instead of minting a new
     *     one, so every invoice monobank ends up holding maps to ONE local
     *     payment and duplicate webhooks reconcile onto it;
     *  3. local state never duplicates: exactly one payment row, whose
     *     invoice_id/page_url are refreshed by the successful retry and
     *     whose pending marker is cleared.
     *
     * NOTE: the resume is keyed by the caller-supplied idempotency key —
     * without it a retry is a fresh purchase (see the contract tests below).
     */
    public function test_retry_with_the_same_idempotency_key_reuses_the_same_reference_for_one_local_payment(): void
    {
        $this->mono->create_script[] = FakeMonobankApi::TIMEOUT_AFTER_CREATE;

        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'retry-order-1']);
        $this->assertFalse($first['ok'], 'First attempt must report the transport failure.');
        $this->assertNotEmpty(
            (string)($first['payment_uuid'] ?? ''),
            'A transport-level failure must return the preliminary payment uuid so callers can resume it.'
        );
        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'The preliminary payment must be KEPT on a transport failure — erasing it destroys the dedup anchor.'
        );
        $kept_uuid = (string)$first['payment_uuid'];

        $second = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'retry-order-1']);
        $this->assertTrue($second['ok'], 'Retry must succeed once the transport recovers.');

        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'The retry must resume the kept payment, not create a second local row.'
        );
        $this->assertSame(
            $kept_uuid,
            $this->mono->last_create_payload()['merchantPaymInfo']['reference'],
            'The retry must re-send the SAME reference to monobank — it is the only client-side dedup anchor.'
        );
        $this->assertSame(
            $kept_uuid,
            $this->mono->reference_of($this->mono->invoice_ids()[0]),
            'The invoice monobank created during the lost attempt must carry the same reference as the retry.'
        );

        $payment = $this->reload_payment_by_uuid($kept_uuid);
        $this->assertSame(
            $second['data']['invoiceId'],
            $payment->get('invoice_id'),
            'The retry must bind the recovered invoice to the kept payment row.'
        );
        $this->assertNotNull($payment->get('page_url'));
        $this->assertNull(
            $payment->get('invoice_pending_at'),
            'The pending marker must be cleared after a successful create.'
        );
    }

    /**
     * Api::request()'s Throwable catch returns
     * ['ok' => false, 'error' => ..., 'data' => null] with NO 'status' key.
     * That shape means "we never learned the outcome", exactly like a
     * timeout: monobank may already hold the invoice for the reference we
     * sent. Only a definite 4xx proves the request was refused without side
     * effects, so anything else must keep the keyed row for a resume.
     *
     * Losing the row destroys the caller's dedup anchor: the retry would
     * mint a NEW reference even though monobank may already hold an invoice
     * for the first one, so one logical payment could end up with two
     * independent payable invoices.
     */
    public function test_create_exception_without_http_status_keeps_the_keyed_payment_for_resume(): void
    {
        $api = new ExceptionShapeCreateApi();
        $key = 'exception-order-' . bin2hex(random_bytes(4));

        $plugin = $this->make_configured_plugin($api);

        $first = $plugin->create_payment(4200.00, 'Exception order', ['idempotency_key' => $key]);

        $this->assertFalse((bool)$first['ok'], 'the first attempt must report the exception failure');
        $first_reference = (string)($first['payment_uuid'] ?? '');
        $this->assertNotSame('', $first_reference, 'the failed attempt must expose the reference it sent');

        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'a create failure whose HTTP outcome is unknown must KEEP the keyed payment row '
            . '(it is the only dedup anchor for the invoice monobank may have created)'
        );

        $second = $plugin->create_payment(4200.00, 'Exception order', ['idempotency_key' => $key]);
        $this->assertTrue((bool)$second['ok'], 'the retry must succeed');

        $this->assertSame(
            $first_reference,
            (string)($api->references[1] ?? ''),
            'the retry must resume the first attempt\'s reference, not mint a new one'
        );
        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'the retry must reuse the kept row'
        );
    }

    // ------------------------------------------------------------------
    // Create-retry contract: resume only on a caller-supplied key
    // ------------------------------------------------------------------

    /**
     * Contract (2): without an idempotency key every create() is a fresh
     * payment. The preliminary row kept after an ambiguous failure belongs
     * to the attempt that created it and must never be silently shared with
     * a later, unrelated create for the same amount and destination.
     */
    public function test_create_without_key_never_resumes_a_pending_payment(): void
    {
        $this->mono->create_script[] = FakeMonobankApi::TIMEOUT_AFTER_CREATE;

        $first = $this->plugin->create_payment(4200.00, 'Order #1');
        $this->assertFalse($first['ok']);
        $this->assertSame(1, $this->count_payment_rows(), 'The ambiguous attempt must keep its preliminary row.');
        $pending_uuid = (string)($first['payment_uuid'] ?? '');
        $this->assertNotSame('', $pending_uuid, 'The kept row must report its reference.');

        $second = $this->plugin->create_payment(4200.00, 'Order #1');
        $this->assertTrue($second['ok']);
        $second_uuid = $this->mono->reference_of((string)$second['data']['invoiceId']);

        $this->assertNotSame(
            $pending_uuid,
            $second_uuid,
            'A create() without an idempotency key must mint a fresh payment, not adopt another attempt\'s kept row.'
        );
        $this->assertSame(2, $this->count_payment_rows(), 'A keyless create() must own its own payment row.');

        $pending = $this->reload_payment_by_uuid($pending_uuid);
        $this->assertNull($pending->get('invoice_id'), 'The abandoned pending row must not be bound to the new invoice.');
        $this->assertNotNull($pending->get('invoice_pending_at'), 'The abandoned pending row must stay untouched and pending.');
    }

    /**
     * Contract (1): the key must be a NON-EMPTY string. An empty key is the
     * same as no key and must not resume a payment created with a real key.
     */
    public function test_empty_idempotency_key_is_treated_as_no_key(): void
    {
        $this->mono->create_script[] = FakeMonobankApi::TIMEOUT_AFTER_CREATE;

        $seeded = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'real-key']);
        $this->assertFalse($seeded['ok']);
        $seeded_uuid = (string)($seeded['payment_uuid'] ?? '');
        $this->assertNotSame('', $seeded_uuid);

        $retry = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => '']);
        $this->assertTrue($retry['ok']);

        $this->assertNotSame(
            $seeded_uuid,
            $this->mono->reference_of((string)$retry['data']['invoiceId']),
            'An empty idempotency key must be ignored, not match the seeded payment.'
        );
        $this->assertSame(2, $this->count_payment_rows());
    }

    /**
     * Contract (1)+(2) / bug (a): two customers buying the same item must
     * never share a payment. Each caller's key names its own intent; a
     * pending row created under customer A's key is invisible to B.
     */
    public function test_different_idempotency_keys_never_share_a_payment(): void
    {
        $this->mono->create_script[] = FakeMonobankApi::TIMEOUT_AFTER_CREATE;

        $customer_a = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'customer-a-order-1']);
        $this->assertFalse($customer_a['ok']);
        $a_uuid = (string)($customer_a['payment_uuid'] ?? '');
        $this->assertNotSame('', $a_uuid);

        $customer_b = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'customer-b-order-1']);
        $this->assertTrue($customer_b['ok']);
        $b_uuid = $this->mono->reference_of((string)$customer_b['data']['invoiceId']);

        $this->assertNotSame($a_uuid, $b_uuid, 'Different keys must produce different payment references.');
        $this->assertSame(2, $this->count_payment_rows(), 'Customer B must get their own payment row.');
    }

    /**
     * Contract (4): replaying a completed key returns the existing invoice
     * data and must NOT call invoice/create again.
     */
    public function test_same_key_replays_the_existing_invoice_without_a_new_monobank_call(): void
    {
        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'order-1']);
        $this->assertTrue($first['ok']);
        $first_uuid = $this->mono->reference_of((string)$first['data']['invoiceId']);
        $this->assertSame(1, $this->mono->invoice_count());

        $second = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'order-1']);

        $this->assertTrue($second['ok']);
        $this->assertSame($first['data']['invoiceId'], $second['data']['invoiceId'], 'A completed key must replay the same invoice id.');
        $this->assertSame($first['data']['pageUrl'], $second['data']['pageUrl'], 'A completed key must replay the same payment page.');
        $this->assertSame($first_uuid, $second['reference'] ?? null, 'A completed key must replay the same reference.');
        $this->assertSame(1, $this->mono->invoice_count(), 'A replayed create must not call invoice/create again.');
        $this->assertSame(1, $this->count_payment_rows(), 'A replayed create must not add a payment row.');
    }

    /**
     * Finding 3 regression guard: replay must ignore the payment status.
     * A payment that already succeeded (or is on hold / still processing)
     * must NEVER be paid a second time, so the key keeps replaying the
     * existing invoice without a new monobank call.
     */
    public function test_success_payment_key_still_replays_without_a_new_monobank_call(): void
    {
        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'paid-order']);
        $this->assertTrue($first['ok']);
        $first_reference = (string)$first['reference'];

        $this->mono->set_invoice_status($first_reference, PaymentStatus::SUCCESS->value);
        [$body, $sign] = $this->signed_body($first_reference, PaymentStatus::SUCCESS->value);
        $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);
        $this->assertSame(PaymentStatus::SUCCESS->value, $this->reload_payment_by_uuid($first_reference)->get('status'));

        $replay = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'paid-order']);

        $this->assertTrue($replay['ok']);
        $this->assertSame($first['data']['invoiceId'], $replay['data']['invoiceId'], 'A successful payment must replay its invoice.');
        $this->assertSame($first_reference, $replay['reference'] ?? null, 'A successful payment must replay its reference.');
        $this->assertSame(1, $this->mono->invoice_count(), 'A successful payment must never trigger a second invoice/create.');
        $this->assertSame(1, $this->count_payment_rows(), 'A successful payment must keep exactly one row.');
    }

    /**
     * Finding 3 regression guard: a key bound to a payment that ended in a
     * terminal NON-PAID status ('failure' = card declined, 'reversed' =
     * refunded, 'expired') points at an invoice that can never be paid
     * again. Replaying it strands the customer on a dead link forever, so
     * a retry with the same key must release the key from the dead row and
     * create a fresh payable invoice under it. The dead row is kept for
     * audit but no longer owns the key.
     *
     * @return array<string, array{string}>
     */
    public static function terminal_non_paid_status_provider(): array
    {
        return [
            'failure' => [PaymentStatus::FAILURE->value],
            'reversed' => [PaymentStatus::REVERSED->value],
            'expired' => [PaymentStatus::EXPIRED->value],
        ];
    }

    #[DataProvider('terminal_non_paid_status_provider')]
    public function test_retry_after_terminal_non_paid_status_creates_a_new_invoice(string $terminal_status): void
    {
        $key = 'retry-terminal-' . $terminal_status;

        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => $key]);
        $this->assertTrue($first['ok']);
        $first_invoice_id = (string)$first['data']['invoiceId'];
        $first_reference = (string)$first['reference'];
        $this->assertSame(1, $this->mono->invoice_count());

        $this->drive_payment_to_terminal_status($first_reference, $terminal_status);

        $retry = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => $key]);

        $this->assertTrue($retry['ok'], 'A retry after a terminal non-paid status must succeed.');
        $this->assertSame(
            2,
            $this->mono->invoice_count(),
            'A dead (' . $terminal_status . ') invoice must not be replayed — the retry must call invoice/create.'
        );
        $this->assertNotSame($first_invoice_id, $retry['data']['invoiceId'], 'The retry must get a new invoice id.');
        $this->assertNotSame(
            $first_reference,
            $retry['reference'] ?? null,
            'The retry must mint a new reference instead of reusing the dead payment uuid.'
        );
        $this->assertSame(2, $this->count_payment_rows(), 'The dead row is kept for audit and a fresh row owns the key.');

        $new_payment = Payment::get_by_idempotency_key($key);
        $this->assertNotFalse($new_payment, 'The idempotency key must be bound to the fresh payment.');
        $this->assertSame((string)$retry['reference'], (string)$new_payment->get('uuid'), 'The key must move to the new row.');
        $this->assertSame((string)$retry['data']['invoiceId'], (string)$new_payment->get('invoice_id'));

        $dead_payment = $this->reload_payment_by_uuid($first_reference);
        $this->assertNull($dead_payment->get('idempotency_key'), 'The dead payment must release the key.');
        $this->assertSame($terminal_status, $dead_payment->get('status'), 'The dead payment status must stay untouched.');
        $this->assertSame($first_invoice_id, (string)$dead_payment->get('invoice_id'), 'The dead payment keeps its invoice for audit.');
    }

    /**
     * Contract (5): the same key with a DIFFERENT amount is a caller bug —
     * reject it without creating a row or calling monobank.
     */
    public function test_same_key_with_a_different_amount_is_rejected_without_side_effects(): void
    {
        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'order-1']);
        $this->assertTrue($first['ok']);
        $this->assertSame(1, $this->mono->invoice_count());

        $conflict = $this->plugin->create_payment(4300.00, 'Order #1', ['idempotency_key' => 'order-1']);

        $this->assertFalse($conflict['ok'], 'Reusing a key with a different amount must be rejected.');
        $this->assertNotEmpty((string)($conflict['error'] ?? ''));
        $this->assertSame(1, $this->count_payment_rows(), 'A key conflict must not create a payment row.');
        $this->assertSame(1, $this->mono->invoice_count(), 'A key conflict must not call invoice/create.');
    }

    /** Contract (5): the same key with a different currency is rejected too. */
    public function test_same_key_with_a_different_currency_is_rejected_without_side_effects(): void
    {
        $first = $this->plugin->create_payment(4200.00, 'Order #1', ['idempotency_key' => 'order-1']);
        $this->assertTrue($first['ok']);
        $this->assertSame(1, $this->mono->invoice_count());

        $conflict = $this->plugin->create_payment(4200.00, 'Order #1', [
            'idempotency_key' => 'order-1',
            'ccy' => Monopay::CURRENCY_USD,
        ]);

        $this->assertFalse($conflict['ok'], 'Reusing a key with a different currency must be rejected.');
        $this->assertNotEmpty((string)($conflict['error'] ?? ''));
        $this->assertSame(1, $this->count_payment_rows(), 'A key conflict must not create a payment row.');
        $this->assertSame(1, $this->mono->invoice_count(), 'A key conflict must not call invoice/create.');
    }

    /** A create() that fails at the API level must not leave an orphan payment row. */
    public function test_failed_create_erases_preliminary_payment(): void
    {
        $this->mono->create_script[] = ['err', 'BAD_REQUEST', 'invalid amount'];

        $result = $this->plugin->create_payment(4200.00, 'Order #1');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->count_payment_rows(), 'Preliminary payment must be erased after a failed create.');
        $this->assertSame(0, $this->mono->invoice_count());
    }

    /** Amount conversion to minor units must survive float representation. */
    public function test_create_converts_amounts_to_minor_units_exactly(): void
    {
        foreach ([[4200.00, 420000], [0.01, 1], [419.99, 41999], [99.99, 9999]] as [$amount, $expected]) {
            $this->mono->reset_requests();

            $result = $this->plugin->create_payment($amount, 'Order');

            $this->assertTrue($result['ok']);
            $this->assertSame($expected, $this->mono->last_create_payload()['amount'], "amount {$amount} must convert to {$expected} minor units");
        }
    }

    /**
     * Characterization: the create payload carries no idempotency token
     * of any kind (monobank documents none either — the only client-side
     * anchor is merchantPaymInfo.reference, which the plugin regenerates
     * on every attempt).
     */
    public function test_create_payload_contains_no_idempotency_token(): void
    {
        $this->plugin->create_payment(4200.00, 'Order #1');

        $payload = $this->mono->last_create_payload();
        foreach (array_keys($payload) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('idempoten', (string)$key);
        }
        $this->assertArrayHasKey('merchantPaymInfo', $payload);
        $this->assertNotEmpty($payload['merchantPaymInfo']['reference'], 'reference (our only dedup anchor) must be present');
    }

    /**
     * Characterization: negative amounts must be rejected and must not
     * leave orphan payment rows. The rejection may come from model
     * validation or from monobank's 400 — both are acceptable; the
     * invariant under test is the cleanup.
     */
    public function test_negative_amount_is_rejected_without_orphan_rows(): void
    {
        $result = $this->plugin->create_payment(-5.00, 'Order #1');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->count_payment_rows());
    }

    // ==================================================================
    // B) Webhook delivery (monobank retries up to 3x until HTTP 200)
    // ==================================================================

    /** Happy path: one successful webhook fulfills the payment exactly once. */
    public function test_success_webhook_fulfills_payment_exactly_once(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $response = $this->deliver_webhook($body, $sign);

        $this->assertSame(200, $response['http_status'], 'monobank must receive 200 or it will retry');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value));
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_UPDATED->value));

        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertNotNull($payment->get('fulfilled_at'), 'success webhook must fulfill the payment');
    }

    /**
     * Finding 3 regression guard: monobank re-delivers the same webhook
     * until it gets a 200. When a success webhook is delivered twice (our
     * first 200 response was lost, or the handler answered 500 because
     * re-verification failed), the payment history must contain exactly
     * ONE record for that logical event. success→success is a duplicate
     * delivery, not a transition — the handler must not append history
     * for it.
     */
    public function test_monobank_retry_of_success_webhook_logs_history_once(): void
    {
        $uuid = $this->create_paid_payment();

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);
        $this->deliver_webhook($body, $sign);

        $this->assertSame(
            1,
            PaymentHistory::count_by_payment_uuid($uuid),
            'Duplicate delivery of the same success webhook must not append a second history row.'
        );
    }

    /**
     * Finding 3 regression guard: same retry as logs_history_once, observed
     * through the hook system — downstream listeners (fulfilment pipelines,
     * emails, ERP sync) must observe a status change exactly once, so a
     * duplicate delivery must not re-fire monopay.payment.updated.
     */
    public function test_monobank_retry_of_success_webhook_fires_status_hooks_once(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);
        $this->deliver_webhook($body, $sign);

        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_UPDATED->value), 'monopay.payment.updated must fire once per logical event');
    }

    /**
     * Finding 5 regression guard: the handler must dispatch ONE status
     * event per status change. Event::emit() is hierarchical — emitting
     * the generic tag monopay.payment.status broadcast-fires listeners
     * registered on the specific child tags, so emitting the generic tag
     * AND then the specific tag made leaf listeners run TWICE per status
     * change. The handler now emits only the specific (leaf) tag; generic
     * listeners still fire exactly once via emit()'s ancestor traversal.
     */
    public function test_leaf_status_listener_fires_once_per_status_change(): void
    {
        $uuid = $this->create_paid_payment();

        $fires = 0;
        $tag = MonopayHook::status(PaymentStatus::SUCCESS->value);
        $closure = static function () use (&$fires): void {
            $fires++;
        };
        Hook::instance()->add_action($tag, $closure, 10, 5);
        $this->hook_listeners[$tag] = $closure;

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);

        $this->assertSame(1, $fires, 'leaf tag listener must be invoked exactly once for ONE status change');
    }

    /** Fulfillment itself must not run twice for duplicate success webhooks. */
    public function test_monobank_retry_of_success_webhook_does_not_refulfill(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);
        $first_fulfilled_at = $this->reload_payment_by_uuid($uuid)->get('fulfilled_at');
        $this->assertNotNull($first_fulfilled_at);

        $this->deliver_webhook($body, $sign);

        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame($first_fulfilled_at, $payment->get('fulfilled_at'), 'fulfilled_at must not be overwritten by a retry');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'fulfillment must happen exactly once');
    }

    /**
     * Finding 6 regression guard: fulfillment must be an atomic claim.
     * is_fulfilled() -> mark_fulfilled() is a check-then-act pair that
     * two concurrent deliveries can both pass, so mark_fulfilled() must
     * claim conditionally (UPDATE ... WHERE fulfilled_at IS NULL AND
     * status = 'success') and report the loser — exactly one delivery may
     * fire the success hooks.
     */
    public function test_mark_fulfilled_claims_atomically_and_reports_the_race_loser(): void
    {
        $uuid = $this->create_paid_payment();
        $winner = $this->reload_payment_by_uuid($uuid);
        $this->assertNull($winner->get('fulfilled_at'));

        // Fulfillment only applies to a payment that actually reached
        // success; the claim itself must re-check that status.
        $this->assertTrue($winner->update_from_webhook(
            ['status' => PaymentStatus::SUCCESS->value],
            PaymentStatus::CREATED->value
        ));
        $this->assertSame(PaymentStatus::SUCCESS->value, (string)$winner->get('status'));

        $this->assertTrue($winner->mark_fulfilled(), 'the first claim must win');
        $first_fulfilled_at = (string)$winner->get('fulfilled_at');
        $this->assertNotSame('', $first_fulfilled_at);

        $loser = $this->reload_payment_by_uuid($uuid);
        $this->assertFalse($loser->mark_fulfilled(), 'a second claim must lose the race');
        $this->assertSame(
            $first_fulfilled_at,
            (string)$this->reload_payment_by_uuid($uuid)->get('fulfilled_at'),
            'the losing claim must not overwrite fulfilled_at'
        );
    }

    /**
     * Out-of-order retry (processing arrives after success): status must
     * stay success and the payment must remain fulfilled.
     *
     * Finding 7 regression guard: the audit trail must only record
     * transitions that happened — an ignored (stale) delivery must not
     * append a payment_history row.
     */
    public function test_out_of_order_webhook_after_success_is_ignored(): void
    {
        $uuid = $this->create_paid_payment();

        [$success_body, $success_sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($success_body, $success_sign);
        $history_after_success = PaymentHistory::count_by_payment_uuid($uuid);

        [$stale_body, $stale_sign] = $this->signed_body($uuid, 'processing');
        $response = $this->deliver_webhook($stale_body, $stale_sign);

        $this->assertSame(200, $response['http_status']);
        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame('success', $payment->get('status'), 'stale processing webhook must not downgrade status');
        $this->assertNotNull($payment->get('fulfilled_at'));

        $this->assertSame(
            $history_after_success,
            PaymentHistory::count_by_payment_uuid($uuid),
            'An ignored (out-of-order) webhook must not append a history row — '
            . 'the mirrored handler logs every verified delivery, even ignored ones.'
        );
    }

    /**
     * Success webhook while the status API is rate-limiting us (TMR):
     * re-verification inside the webhook handler throws, the handler
     * answers 500, monobank retries. Characterizes how fulfillment
     * depends on a live monobank API call per webhook.
     */
    public function test_success_webhook_with_status_api_down_leaves_payment_unfulfilled(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();
        $this->mono->status_endpoint_failing = true;

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $response = $this->deliver_webhook($body, $sign);

        $this->assertSame(500, $response['http_status'], 'verification failure currently bubbles up as 500 so monobank retries');
        $this->assertSame(0, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'fulfillment hooks must not fire on unverified success');

        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertNull($payment->get('fulfilled_at'));
    }

    /** After the API recovers, the retried webhook must complete fulfillment. */
    public function test_webhook_redelivery_after_api_recovery_self_heals(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();
        $this->mono->status_endpoint_failing = true;

        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);

        $this->mono->status_endpoint_failing = false;
        $response = $this->deliver_webhook($body, $sign);

        $this->assertSame(200, $response['http_status']);
        $this->assertNotNull($this->reload_payment_by_uuid($uuid)->get('fulfilled_at'), 'recovered retry must complete fulfillment');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'success hook must fire exactly once across all deliveries');
    }

    /** A tampered body must never be accepted, whatever the payload says. */
    public function test_tampered_webhook_body_is_rejected(): void
    {
        $uuid = $this->create_paid_payment();

        $body = $this->webhook_body($uuid, 'success');
        $sign = $this->sign($body);
        $tampered = json_decode($body, true);
        $tampered['amount'] = 1; // attacker alters the payload after signing
        $tampered['status'] = 'success';

        $response = $this->deliver_webhook((string)json_encode($tampered), $sign);

        $this->assertSame(400, $response['http_status']);
        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame('created', $payment->get('status'));
        $this->assertNull($payment->get('fulfilled_at'));
    }

    /**
     * Finding 4 regression guard: the webhook endpoint is public and
     * signature verification is its first gate — without throttling,
     * anyone can flood it with garbage signatures and turn it into a
     * lookup amplifier against api.monobank.ua, whose rate limiting then
     * breaks legitimate webhooks too. Refresh attempts are bounded: a
     * refetch is allowed once per failure streak and at most once per
     * minimum interval otherwise.
     */
    public function test_flood_of_tampered_webhooks_does_not_amplify_pubkey_lookups(): void
    {
        $uuid = $this->create_paid_payment();

        // Warm the key cache exactly like production traffic would.
        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->deliver_webhook($body, $sign);
        $baseline = $this->mono->pubkey_fetches;
        $this->assertSame(1, $baseline, 'exactly one pubkey lookup warms the cache');

        for ($i = 0; $i < 10; $i++) {
            $forged = json_encode(['invoiceId' => 'p2_forged_' . $i, 'status' => 'success', 'reference' => $uuid]);
            $this->deliver_webhook((string)$forged, $this->sign((string)$forged, wrong_key: true));
        }

        $this->assertLessThanOrEqual(
            $baseline + 2,
            $this->mono->pubkey_fetches,
            'Tampered webhooks must not each trigger a pubkey lookup — got '
            . ($this->mono->pubkey_fetches - $baseline) . ' lookups for 10 tampered deliveries.'
        );
    }

    /** Key rotation: a webhook signed with a NEW key must still be accepted. */
    public function test_webhook_signed_with_rotated_key_is_accepted_after_refetch(): void
    {
        $uuid = $this->create_paid_payment();

        // Warm the cache with the current key...
        [$body, $sign] = $this->signed_body($uuid, 'success');
        $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);

        // ...monobank rotates the signing key...
        $this->mono->rotate_key();

        // ...and the next delivery, signed with the new key, must pass.
        [$rotated_body, $rotated_sign] = $this->signed_body($uuid, 'success');
        $response = $this->deliver_webhook($rotated_body, $rotated_sign);

        $this->assertSame(200, $response['http_status'], 'webhook signed with the rotated key must be accepted after refetch');
    }

    /** Valid JSON that is not an object must be rejected without crashing. */
    public function test_scalar_json_webhook_body_is_rejected_gracefully(): void
    {
        $uuid = $this->create_paid_payment();

        $response = $this->deliver_webhook('123', $this->sign('123'));

        $this->assertSame(400, $response['http_status'], 'non-object JSON must be rejected, not crash');
        $this->assertSame('created', $this->reload_payment_by_uuid($uuid)->get('status'));
    }

    /**
     * Characterization: a correctly-signed webhook for a reference we
     * don't know is answered with 200. monobank therefore stops
     * retrying — if the payment row was missing due to a transient DB
     * issue, the event is silently lost forever.
     */
    public function test_webhook_for_unknown_reference_is_accepted_without_side_effects(): void
    {
        $unknown_uuid = FakeMonobankApi::uuid4();

        [$body, $sign] = $this->signed_body($unknown_uuid, 'success');
        $response = $this->deliver_webhook($body, $sign);

        $this->assertSame(200, $response['http_status'], 'current behaviour: unknown reference is ACKed with 200');
        $this->assertSame(0, PaymentHistory::count_by_payment_uuid($unknown_uuid));
    }

    // ==================================================================
    // Structural guard: the in-process mirror must match the real handler
    // ==================================================================

    /**
     * WebhookHandler::handle() terminates via exit(), so deliver_webhook()
     * mirrors its sequence in-process. This test pins the real source to
     * that sequence: verify → shared verified-event path → 200, where the
     * shared path is update → history → status dispatch. The reconciler
     * calls the very same apply_verified_event(), so the marker order also
     * guards that both entry points still share one transition path.
     */
    public function test_webhook_handler_source_matches_mirrored_sequence(): void
    {
        $source = (string)file_get_contents(ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/WebhookHandler.php');

        $markers = [
            '$plugin->handle_webhook($x_sign, $raw_body)',
            'self::apply_verified_event($data)',
            '$response->send_json_success()',
            'public static function apply_verified_event(array $data): void',
            '$payment = self::update_payment_from_webhook($data)',
            'PaymentHistory::log_operation(',
            'handle_success_payment($data)',
        ];

        $position = 0;
        foreach ($markers as $marker) {
            $found = strpos($source, $marker, $position);
            $this->assertNotFalse($found, "WebhookHandler must still contain '{$marker}' after the previous markers");
            $position = $found + 1;
        }
    }

    /**
     * Registration probe (Finding 1 regression guard).
     *
     * Monopay::register() → register_helpers() → require global_functions.php.
     * The file used to place a top-level `if (!defined('ATOMIC_START')) exit;`
     * statement BEFORE the bracketed `namespace { ... }` block, which PHP
     * rejects with a compile-time fatal — registering the plugin killed the
     * entire application. The guard now lives inside the namespace block.
     *
     * Correct behaviour: registering the plugin must complete cleanly.
     */
    public function test_plugin_registration_does_not_fatal(): void
    {
        $script = (string)sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monopay_register_probe.php';
        file_put_contents($script, '<?php
declare(strict_types=1);
define("ATOMIC_START", microtime(true));
require ' . var_export(ATOMIC_VENDOR . 'autoload.php', true) . ';
$plugin = new \Engine\Atomic\Plugins\Monopay\Monopay();
$plugin->register();
echo "REGISTERED";
');

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit_code);
        unlink($script);

        $this->assertSame(
            0,
            $exit_code,
            'Monopay::register() must not fatal. Child process said: ' . implode(PHP_EOL, $output)
        );
        $this->assertContains('REGISTERED', $output);
    }

    /**
     * Guards the in-process shim: global_functions.php must declare
     * monopay_get_order() with the same logic (plugin manager lookup →
     * plugin order). If the shipped helpers change semantics, the shim
     * used by deliver_webhook() would diverge from production.
     */
    public function test_global_functions_source_declares_the_shimmed_helpers(): void
    {
        $source = (string)file_get_contents(ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/global_functions.php');

        $this->assertStringContainsString('function monopay(', $source);
        $this->assertStringContainsString('function monopay_get_order(', $source);
        $this->assertStringContainsString(
            "return get_plugin('Monopay');",
            $source,
            'monopay() must resolve the plugin through the plugin manager'
        );
        $this->assertStringContainsString(
            'return $plugin ? $plugin->get_order() : null;',
            $source,
            'monopay_get_order() must return the plugin order service'
        );
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function make_configured_plugin(Api $api): Monopay
    {
        $plugin = new Monopay();
        // NOTE: $plugin->register() is deliberately NOT called here — it
        // currently fatals the whole process (global_functions.php declares
        // a namespace block after a top-level statement, which PHP rejects;
        // see test_plugin_registration_does_not_fatal and the report).

        $ref = new ReflectionClass($plugin);
        $ref->getProperty('api')->setValue($plugin, $api);
        $ref->getProperty('order')->setValue($plugin, new Order($api));
        $ref->getProperty('public_key')->setValue($plugin, null);

        // Register in the plugin manager so get_plugin()/verify_with_monopay() resolve.
        $pm = PluginManager::instance();
        $pm_ref = new ReflectionClass($pm);
        $plugins_prop = $pm_ref->getProperty('plugins');
        $plugins = $plugins_prop->getValue($pm);
        $plugins['Monopay'] = $plugin;
        $plugins_prop->setValue($pm, $plugins);

        \Base::instance()->set('PLUGIN.Monopay.booted', true);

        $this->shim_global_helpers();

        return $plugin;
    }

    /**
     * global_functions.php cannot be included at all (namespace fatal),
     * so the one helper the tested code paths rely on —
     * Payment::verify_with_monopay() → monopay_get_order() — is shimmed
     * here with logic identical to the shipped file (asserted by
     * test_global_functions_source_declares_the_shimmed_helpers).
     */
    private function shim_global_helpers(): void
    {
        if (function_exists('monopay_get_order')) {
            return;
        }

        eval('function monopay_get_order(): ?\Engine\Atomic\Plugins\Monopay\Order {
            $plugin = \Engine\Atomic\App\PluginManager::instance()->get("Monopay");
            return $plugin ? $plugin->get_order() : null;
        }');
    }

    private function listen_for_hooks(): void
    {
        // NOTE: 'monopay.payment.status.success' is deliberately NOT
        // registered here — see test_leaf_status_listener_fires_twice_per_
        // status_change: the handler's double dispatch (generic tag +
        // specific tag) makes leaf listeners of that tag fire twice per
        // status change. PAYMENT_UPDATED and PAYMENT_SUCCESS are clean
        // leaf tags (no children, emitted once).
        $tags = [
            MonopayHook::PAYMENT_UPDATED->value,
            MonopayHook::PAYMENT_SUCCESS->value,
            MonopayHook::PAYMENT_VERIFIED->value,
        ];

        $hook = Hook::instance();
        foreach ($tags as $tag) {
            $events = &$this->hook_events;
            $closure = static function (...$args) use (&$events, $tag): void {
                $events[$tag][] = $args;
            };
            $hook->add_action($tag, $closure, 10, 5);
            $this->hook_listeners[$tag] = $closure;
        }
    }

    private function hook_fired_count(string $tag): int
    {
        return count($this->hook_events[$tag] ?? []);
    }

    /**
     * Mirrors WebhookHandler::handle() minus the terminating Response
     * calls; the HTTP status the real handler would emit is returned.
     * The verified-event work is the exact production method (it is shared
     * with the reconciler), so this cannot drift from the handler body.
     *
     * @return array{http_status: int, error?: string, exception?: \Throwable}
     */
    private function deliver_webhook(string $raw_body, string $x_sign): array
    {
        $result = $this->plugin->handle_webhook($x_sign, $raw_body);

        if (!$result['ok']) {
            return ['http_status' => 400, 'error' => (string)($result['error'] ?? 'Webhook validation failed')];
        }

        try {
            WebhookHandler::apply_verified_event($result['data']);

            return ['http_status' => 200];
        } catch (\Throwable $e) {
            return ['http_status' => 500, 'exception' => $e];
        }
    }

    private function create_plugin_tables(): void
    {
        $db = \Base::instance()->get('DB');
        if ($db !== null) {
            // Drop first: the migration skips existing tables, so stale
            // tables from older schema versions would never be upgraded.
            $history_table = PaymentHistory::resolveConfiguration()['table'];
            $payments_table = Payment::resolveConfiguration()['table'];
            $db->exec("DROP TABLE IF EXISTS `{$history_table}`");
            $db->exec("DROP TABLE IF EXISTS `{$payments_table}`");
        }

        $executor = new MigrationExecutor(new MigrationContext(
            App::instance(),
            ConnectionManager::instance(),
            new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b')),
        ));
        foreach (['monopay_create_payments_tables', 'monopay_upgrade_payments_idempotency'] as $name) {
            $executor->up([
                'source' => 'plugin:Monopay',
                'migration' => $name,
                'path' => ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/Migrations/' . $name . '.php',
            ]);
        }
    }

    private function clear_plugin_tables(): void
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return;
        }
        $payments_table = Payment::resolveConfiguration()['table'];
        $history_table = PaymentHistory::resolveConfiguration()['table'];
        $db->exec("DELETE FROM `{$history_table}`");
        $db->exec("DELETE FROM `{$payments_table}`");
    }

    private function count_payment_rows(): int
    {
        $db = \Base::instance()->get('DB');
        $table = Payment::resolveConfiguration()['table'];

        return (int)($db->exec("SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'] ?? 0);
    }

    /** Creates a preliminary payment and marks its invoice as paid on the fake monobank. */
    private function create_paid_payment(): string
    {
        $result = $this->plugin->create_payment(4200.00, 'Stress order');
        $this->assertTrue($result['ok'], 'payment fixture must create cleanly: ' . ($result['error'] ?? ''));

        $uuid = $this->mono->reference_of((string)$result['data']['invoiceId']);
        $this->assertNotSame('', $uuid, 'fake monobank must have recorded the invoice reference');

        $this->mono->set_invoice_status($uuid, 'success');

        return $uuid;
    }

    /**
     * Drives an existing keyed payment to a terminal status through the
     * same webhook sequence production uses. A reversal is a refund, so it
     * is only reachable after the payment succeeded.
     */
    private function drive_payment_to_terminal_status(string $reference, string $terminal_status): void
    {
        if ($terminal_status === PaymentStatus::REVERSED->value) {
            $this->mono->set_invoice_status($reference, PaymentStatus::SUCCESS->value);
            [$success_body, $success_sign] = $this->signed_body($reference, PaymentStatus::SUCCESS->value);
            $this->assertSame(200, $this->deliver_webhook($success_body, $success_sign)['http_status']);
        }

        [$body, $sign] = $this->signed_body($reference, $terminal_status);
        $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);
        $this->assertSame(
            $terminal_status,
            $this->reload_payment_by_uuid($reference)->get('status'),
            'fixture must leave the payment in ' . $terminal_status
        );
    }

    /** @return array{0: string, 1: string} [raw body, base64 X-Sign] */
    private function signed_body(string $uuid, string $status): array
    {
        $body = $this->webhook_body($uuid, $status);

        return [$body, $this->sign($body)];
    }

    private function webhook_body(string $uuid, string $status): string
    {
        return (string)json_encode([
            'invoiceId' => $this->mono->invoice_id_of($uuid),
            'status' => $status,
            'amount' => 420000,
            'ccy' => 980,
            'finalAmount' => 420000,
            'createdDate' => '2026-09-06T10:00:00Z',
            'modifiedDate' => '2026-09-06T10:01:00Z',
            'reference' => $uuid,
            'destination' => 'Stress order',
            'paymentInfo' => [
                'maskedPan' => '444403******1902',
                'approvalCode' => '662476',
                'rrn' => '060189181768',
                'terminal' => 'MI001088',
                'paymentSystem' => 'visa',
                'paymentMethod' => 'pan',
            ],
            'cancelList' => [],
        ]);
    }

    private function sign(string $body, bool $wrong_key = false): string
    {
        $signature = '';
        $key = $wrong_key ? $this->mono->stale_key() : $this->mono->current_key();
        openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function reload_payment_by_uuid(string $uuid): Payment
    {
        $payment = (new Payment())->get_by_uuid($uuid);
        $this->assertNotFalse($payment, "payment row for {$uuid} must exist");

        return $payment;
    }
}

/**
 * Create API whose first call fails with the exact shape produced by
 * Api::request()'s Throwable catch block (no 'status' key), after monobank
 * has already accepted the invoice.
 */
final class ExceptionShapeCreateApi extends Api
{
    public int $create_calls = 0;

    /** @var list<string> references received, in call order */
    public array $references = [];

    public int $invoices_created = 0;

    public function __construct()
    {
        parent::__construct('test-token');
    }

    public function create_invoice(array $data): array
    {
        $this->create_calls++;
        $this->references[] = (string)($data['merchantPaymInfo']['reference'] ?? '');
        $this->invoices_created++;

        if ($this->create_calls === 1) {
            return [
                'ok' => false,
                'error' => 'cURL error 28: Operation timed out after 30001 milliseconds',
                'data' => null,
            ];
        }

        $invoice_id = 'exception_invoice_' . $this->create_calls;

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'invoiceId' => $invoice_id,
                'pageUrl' => 'https://pay.mbnk.biz/' . $invoice_id,
            ],
        ];
    }
}

