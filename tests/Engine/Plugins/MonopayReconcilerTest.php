<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Order;
use Engine\Atomic\Plugins\Monopay\Reconciler;
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
 * Tests for Engine\Atomic\Plugins\Monopay\Reconciler.
 *
 * The reconciler is the safety net behind the webhook: monobank retries a
 * failing webhook at most 3 times and sends NO webhook at all for
 * 'expired' invoices, so payments can otherwise stay unfulfilled or
 * pending forever.
 */
final class MonopayReconcilerTest extends TestCase
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

    // ------------------------------------------------------------------
    // 1. Success webhooks that failed re-verification self-heal
    // ------------------------------------------------------------------

    /**
     * The webhook marked the payment success but its re-verification call
     * hit a 429, so fulfillment never ran. Once the API recovers the
     * reconciler must complete it exactly once; a second run is a no-op.
     */
    public function test_unfulfilled_success_is_fulfilled_exactly_once(): void
    {
        $uuid = $this->make_success_but_unfulfilled();

        $summary = Reconciler::run();

        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(1, $summary['fulfilled']);
        $this->assertSame(0, $summary['errors']);
        $this->assertNotNull($this->reload_payment_by_uuid($uuid)->get('fulfilled_at'), 'the reconciler must fulfill a paid payment');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'PAYMENT_SUCCESS must fire exactly once');

        $requests_after_first_run = $this->mono->status_requests;
        $second = Reconciler::run();

        $this->assertSame(0, $second['candidates'], 'an already fulfilled payment must not be a candidate');
        $this->assertSame($requests_after_first_run, $this->mono->status_requests, 'a fulfilled payment must not hit the status API again');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'a second run must not re-fire PAYMENT_SUCCESS');
    }

    // ------------------------------------------------------------------
    // 2. Pending invoices that were paid while the webhook was lost
    // ------------------------------------------------------------------

    public function test_stale_pending_payment_becomes_success_and_is_fulfilled(): void
    {
        $uuid = $this->create_payment_fixture();
        $this->backdate_payment($uuid);
        $this->mono->set_invoice_status($uuid, PaymentStatus::SUCCESS->value);
        $this->listen_for_hooks();

        $summary = Reconciler::run();

        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summary['fulfilled']);

        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame(PaymentStatus::SUCCESS->value, $payment->get('status'));
        $this->assertNotNull($payment->get('fulfilled_at'));

        $this->assertSame(1, PaymentHistory::count_by_payment_uuid($uuid), 'exactly one transition must be recorded');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_UPDATED->value));
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value));
    }

    // ------------------------------------------------------------------
    // 3. Expired invoices (monobank sends NO webhook for these)
    // ------------------------------------------------------------------

    public function test_stale_pending_payment_becomes_expired_without_fulfillment(): void
    {
        $uuid = $this->create_payment_fixture();
        $this->backdate_payment($uuid);
        $this->mono->set_invoice_status($uuid, PaymentStatus::EXPIRED->value);
        $this->listen_for_hooks();

        $summary = Reconciler::run();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['fulfilled']);

        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame(PaymentStatus::EXPIRED->value, $payment->get('status'));
        $this->assertNull($payment->get('fulfilled_at'));
        $this->assertSame(1, PaymentHistory::count_by_payment_uuid($uuid));
        $this->assertSame(0, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value));
        $this->assertSame(0, $this->hook_fired_count(MonopayHook::PAYMENT_VERIFIED->value));
    }

    // ------------------------------------------------------------------
    // 4. The grace period protects in-flight checkouts
    // ------------------------------------------------------------------

    public function test_pending_payment_younger_than_grace_is_untouched_and_makes_no_api_call(): void
    {
        $uuid = $this->create_payment_fixture();
        $this->mono->reset_requests();

        $summary = Reconciler::run();

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame(0, $summary['processed']);
        $this->assertSame(0, $this->mono->status_requests, 'a payment younger than the grace period must not hit the status API');
        $this->assertSame(PaymentStatus::CREATED->value, $this->reload_payment_by_uuid($uuid)->get('status'));
    }

    // ------------------------------------------------------------------
    // 5. One failing lookup must not abort the run
    // ------------------------------------------------------------------

    public function test_status_api_failure_for_one_payment_does_not_abort_the_run(): void
    {
        $broken_uuid = $this->create_payment_fixture('Broken order');
        $broken_invoice = $this->mono->invoice_id_of($broken_uuid);
        $this->backdate_payment($broken_uuid);
        $this->mono->set_invoice_status($broken_uuid, PaymentStatus::SUCCESS->value);
        $this->mono->failing_invoice_ids[] = $broken_invoice;

        $healthy_uuid = $this->create_payment_fixture('Healthy order');
        $this->backdate_payment($healthy_uuid);
        $this->mono->set_invoice_status($healthy_uuid, PaymentStatus::SUCCESS->value);

        $summary = Reconciler::run();

        $this->assertSame(2, $summary['candidates']);
        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['errors'], 'the failed lookup must be counted');
        $this->assertSame(1, $summary['fulfilled'], 'the healthy payment must still be processed');

        $this->assertSame(PaymentStatus::CREATED->value, $this->reload_payment_by_uuid($broken_uuid)->get('status'), 'the failed payment stays untouched');
        $this->assertSame(PaymentStatus::SUCCESS->value, $this->reload_payment_by_uuid($healthy_uuid)->get('status'));
        $this->assertNotNull($this->reload_payment_by_uuid($healthy_uuid)->get('fulfilled_at'));
    }

    // ------------------------------------------------------------------
    // 6. Fully processed success is not re-polled
    // ------------------------------------------------------------------

    public function test_already_fulfilled_success_is_not_a_candidate(): void
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();

        [$body, $sign] = $this->signed_body($uuid, PaymentStatus::SUCCESS->value);
        $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);
        $this->assertNotNull($this->reload_payment_by_uuid($uuid)->get('fulfilled_at'));

        $this->mono->reset_requests();
        $summary = Reconciler::run();

        $this->assertSame(0, $summary['candidates']);
        $this->assertSame(0, $this->mono->status_requests, 'a fulfilled payment must not be polled');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value));
    }

    // ------------------------------------------------------------------
    // 7. Reconciler and webhook must not double-fire
    // ------------------------------------------------------------------

    /** @return array<string, array{bool}> */
    public static function delivery_order_provider(): array
    {
        return [
            'webhook then reconciler' => [true],
            'reconciler then webhook' => [false],
        ];
    }

    #[DataProvider('delivery_order_provider')]
    public function test_webhook_and_reconciler_fire_success_exactly_once(bool $webhook_first): void
    {
        $uuid = $this->create_payment_fixture();
        $this->backdate_payment($uuid);
        $this->mono->set_invoice_status($uuid, PaymentStatus::SUCCESS->value);
        $this->listen_for_hooks();

        [$body, $sign] = $this->signed_body($uuid, PaymentStatus::SUCCESS->value);

        if ($webhook_first) {
            $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);
            Reconciler::run();
        } else {
            Reconciler::run();
            $this->assertSame(200, $this->deliver_webhook($body, $sign)['http_status']);
        }

        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_SUCCESS->value), 'PAYMENT_SUCCESS must fire exactly once across both paths');
        $this->assertSame(1, $this->hook_fired_count(MonopayHook::PAYMENT_VERIFIED->value), 'PAYMENT_VERIFIED must fire exactly once across both paths');
        $this->assertSame(1, PaymentHistory::count_by_payment_uuid($uuid), 'the status transition must be logged once');
        $this->assertNotNull($this->reload_payment_by_uuid($uuid)->get('fulfilled_at'));
    }

    // ------------------------------------------------------------------
    // 8. Unchanged pending rows must not starve newer candidates
    // ------------------------------------------------------------------

    /**
     * A pending invoice whose monobank status never changes keeps its old
     * updated_at, so ordering by updated_at alone pins it to the head of
     * the queue. When more than $limit such rows exist, every run polls
     * the same oldest rows and a newer paid-but-unfulfilled payment is
     * never reached. The queue must rotate and category (a) must win.
     */
    public function test_unchanged_pending_rows_rotate_and_do_not_starve_a_paid_row(): void
    {
        $limit = 3;

        $pending = [];
        for ($i = 0; $i < $limit + 1; $i++) {
            $uuid = $this->create_payment_fixture('Pending order ' . $i);
            $this->backdate_payment($uuid);
            $pending[] = $uuid;
        }

        // Paid but not fulfilled, and newer than every pending row: it must
        // be reached even though the batch is full of stale pending rows.
        $paid = $this->make_success_but_unfulfilled();
        $this->backdate_payment($paid, 0);
        $this->mono->reset_requests();

        for ($run = 0; $run < 3; $run++) {
            Reconciler::run($limit);
        }

        $this->assertNotNull(
            $this->reload_payment_by_uuid($paid)->get('fulfilled_at'),
            'a paid row must be fulfilled even when unchanged pending rows fill the batch'
        );

        foreach ($pending as $uuid) {
            $invoice_id = $this->mono->invoice_id_of($uuid);
            $this->assertGreaterThanOrEqual(
                1,
                $this->mono->status_requests_by_invoice[$invoice_id] ?? 0,
                "pending payment {$uuid} must be polled within {$limit} runs"
            );
        }
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    /**
     * Creates a paid payment whose webhook was accepted but whose
     * re-verification call failed, leaving it success + unfulfilled.
     */
    private function make_success_but_unfulfilled(): string
    {
        $uuid = $this->create_paid_payment();
        $this->listen_for_hooks();
        $this->mono->status_endpoint_failing = true;

        [$body, $sign] = $this->signed_body($uuid, PaymentStatus::SUCCESS->value);
        $response = $this->deliver_webhook($body, $sign);

        $this->assertSame(500, $response['http_status'], 'the webhook must 500 while the status API is down');
        $payment = $this->reload_payment_by_uuid($uuid);
        $this->assertSame(PaymentStatus::SUCCESS->value, $payment->get('status'));
        $this->assertNull($payment->get('fulfilled_at'));

        $this->mono->status_endpoint_failing = false;
        $this->mono->reset_requests();
        $this->hook_events = [];

        return $uuid;
    }

    private function make_configured_plugin(FakeMonobankApi $api): Monopay
    {
        $plugin = new Monopay();

        $ref = new ReflectionClass($plugin);
        $ref->getProperty('api')->setValue($plugin, $api);
        $ref->getProperty('order')->setValue($plugin, new Order($api));
        $ref->getProperty('public_key')->setValue($plugin, null);

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

    /** @return array{http_status: int, error?: string, exception?: \Throwable} */
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

    /** Creates a payment in 'created' with its invoice bound on the fake. */
    private function create_payment_fixture(string $destination = 'Reconcile order'): string
    {
        $result = $this->plugin->create_payment(4200.00, $destination);
        $this->assertTrue($result['ok'], 'payment fixture must create cleanly: ' . ($result['error'] ?? ''));

        $uuid = $this->mono->reference_of((string)$result['data']['invoiceId']);
        $this->assertNotSame('', $uuid);

        return $uuid;
    }

    private function create_paid_payment(): string
    {
        $uuid = $this->create_payment_fixture('Paid order');
        $this->mono->set_invoice_status($uuid, PaymentStatus::SUCCESS->value);

        return $uuid;
    }

    private function backdate_payment(string $uuid, int $seconds = 3600): void
    {
        $payment = $this->reload_payment_by_uuid($uuid);
        $table = Payment::resolveConfiguration()['table'];
        $db = \Base::instance()->get('DB');

        $db->exec(
            "UPDATE `{$table}` SET updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s', time() - $seconds), (int)$payment->get('id')]
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
            'destination' => 'Reconcile order',
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

    private function sign(string $body): string
    {
        $signature = '';
        openssl_sign($body, $signature, $this->mono->current_key(), OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function reload_payment_by_uuid(string $uuid): Payment
    {
        $payment = (new Payment())->get_by_uuid($uuid);
        $this->assertNotFalse($payment, "payment row for {$uuid} must exist");

        return $payment;
    }
}
