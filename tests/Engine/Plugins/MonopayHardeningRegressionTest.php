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
use Engine\Atomic\Plugins\Monopay\Reconciler;
use Engine\Atomic\Plugins\Monopay\WebhookHandler;
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
 * Adversarial findings against the Monopay idempotency/concurrency hardening.
 *
 * Every test here asserts the CORRECT behaviour and fails on the current
 * branch. The failures demonstrate wrong money/state outcomes, not style.
 */
final class MonopayHardeningRegressionTest extends TestCase
{
    /** @var array<string, callable> hook tag => listener closure (for removal) */
    private array $hook_listeners = [];

    /** @var array<string, list<list<mixed>>> hook tag => collected invocations */
    private array $hook_events = [];

    public static function setUpBeforeClass(): void
    {
        PlatformGuard::requireExtension('openssl');
        PlatformGuard::requireMySql();
    }

    protected function setUp(): void
    {
        if (\Base::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }

        $this->create_plugin_tables();
        $this->clear_plugin_tables();

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
    // FINDING 4 — reconciler trusts a payload for another invoice
    // ==================================================================

    /**
     * The reconciler queries status for the row's invoice_id. It rejects a
     * payload only when the payload carries a non-null `reference` that
     * differs; it never compares the returned `invoiceId` with the invoice
     * it asked about. A payload with no reference is therefore applied to
     * this payment as if it were its own status.
     *
     * Correct behaviour: a status payload that identifies a different
     * invoice must not transition the local payment at all.
     */
    public function test_reconciler_ignores_a_status_payload_for_another_invoice(): void
    {
        $api = new BugHuntStatusApi();
        $plugin = $this->make_configured_plugin($api);

        $payment = Payment::create_preliminary(4200.00, Monopay::CURRENCY_UAH, 'Reconcile order');
        $this->assertInstanceOf(Payment::class, $payment);
        $uuid = (string)$payment->get('uuid');
        $invoice_id = 'bughunt_ours_' . bin2hex(random_bytes(4));
        $this->assertTrue($payment->bind_invoice($invoice_id, 'https://pay.mbnk.biz/' . $invoice_id));
        $this->backdate_payment($uuid);

        $other_invoice = 'bughunt_other_' . bin2hex(random_bytes(4));
        $api->responses[$invoice_id] = $this->status_api_response(
            $other_invoice,
            null,
            amount_minor: 420000,
            final_amount_minor: 420000,
            status: PaymentStatus::SUCCESS->value
        );

        $summary = Reconciler::run();

        $this->assertSame(1, $summary['candidates']);
        $this->assertSame(
            PaymentStatus::CREATED->value,
            (string)$this->reload_payment_by_uuid($uuid)->get('status'),
            'the reconciler must not apply a status payload whose invoiceId is not the invoice it queried '
            . '(queried ' . $invoice_id . ', payload said ' . $other_invoice . ')'
        );
        $this->assertSame(0, PaymentHistory::count_by_payment_uuid($uuid), 'no transition history may be recorded for a foreign payload');
    }

    // ==================================================================
    // FINDING 5 — a permanent paid backlog starves the pending queue
    // ==================================================================

    /**
     * Category (a) (success + unfulfilled) has priority and NO backoff, so
     * the same rows are re-selected on every run. A batch full of rows that
     * can never pass re-verification (permanent amount/reference mismatch)
     * therefore consumes the whole limit forever, and category (b) is never
     * reached: an invoice paid while its webhook was lost is never
     * reconciled.
     *
     * Correct behaviour: over a bounded number of runs the reconciler must
     * still make progress on the stale pending row.
     */
    public function test_a_permanent_paid_backlog_must_not_starve_pending_reconciliation(): void
    {
        $api = new BugHuntStatusApi();
        $plugin = $this->make_configured_plugin($api);

        $limit = 3;

        // Poison: success rows that can never verify (invoice says 1.00,
        // order says 4200.00). They stay success + unfulfilled forever.
        for ($i = 0; $i < $limit; $i++) {
            $poison = Payment::create_preliminary(4200.00, Monopay::CURRENCY_UAH, 'Poison order ' . $i);
            $this->assertInstanceOf(Payment::class, $poison);
            $poison_uuid = (string)$poison->get('uuid');
            $poison_invoice = 'bughunt_poison_' . $i . '_' . bin2hex(random_bytes(3));
            $this->assertTrue($poison->bind_invoice($poison_invoice, 'https://pay.mbnk.biz/' . $poison_invoice));

            $api->responses[$poison_invoice] = $this->status_api_response(
                $poison_invoice,
                $poison_uuid,
                amount_minor: 100,
                final_amount_minor: 100,
                status: PaymentStatus::SUCCESS->value
            );

            WebhookHandler::apply_verified_event([
                'invoice_id' => $poison_invoice,
                'status' => PaymentStatus::SUCCESS->value,
                'amount' => 1.00,
                'final_amount' => 1.00,
                'currency' => Monopay::CURRENCY_UAH,
                'reference' => $poison_uuid,
                'destination' => 'Poison order ' . $i,
            ]);

            $fresh = $this->reload_payment_by_uuid($poison_uuid);
            $this->assertSame(PaymentStatus::SUCCESS->value, (string)$fresh->get('status'));
            $this->assertNull($fresh->get('fulfilled_at'), 'poison fixture must stay unfulfilled');
        }

        // The victim: paid while its webhook was lost. Only category (b)
        // can discover it.
        $victim = Payment::create_preliminary(4200.00, Monopay::CURRENCY_UAH, 'Victim order');
        $this->assertInstanceOf(Payment::class, $victim);
        $victim_uuid = (string)$victim->get('uuid');
        $victim_invoice = 'bughunt_victim_' . bin2hex(random_bytes(3));
        $this->assertTrue($victim->bind_invoice($victim_invoice, 'https://pay.mbnk.biz/' . $victim_invoice));
        $this->backdate_payment($victim_uuid);

        $api->responses[$victim_invoice] = $this->status_api_response(
            $victim_invoice,
            $victim_uuid,
            amount_minor: 420000,
            final_amount_minor: 420000,
            status: PaymentStatus::SUCCESS->value
        );

        for ($run = 0; $run < 3; $run++) {
            Reconciler::run($limit);
        }

        $fresh = $this->reload_payment_by_uuid($victim_uuid);
        $this->assertSame(
            PaymentStatus::SUCCESS->value,
            (string)$fresh->get('status'),
            'a paid pending row must be reconciled even when unfulfillable success rows fill the batch; '
            . 'status API calls for the victim invoice: ' . ($api->status_calls_by_invoice[$victim_invoice] ?? 0)
        );
        $this->assertNotNull($fresh->get('fulfilled_at'), 'the paid pending row must be fulfilled');
    }

    // ==================================================================
    // FINDING 6 — idempotency keys are compared case-insensitively
    // ==================================================================

    /**
     * The shipped default collation is utf8mb4_general_ci (skeleton
     * .env.example / config/database.php). The unique index and
     * get_by_idempotency_key() therefore fold case: two callers using
     * 'Customer-A-Key' and 'customer-a-key' share one payment.
     *
     * Correct behaviour: caller-supplied idempotency keys are opaque tokens
     * and must match byte-exactly. A different key is a different payment.
     */
    public function test_idempotency_keys_match_exactly_not_case_insensitively(): void
    {
        $mono = new FakeMonobankApi('test-token');
        $plugin = $this->make_configured_plugin($mono);

        $first = $plugin->create_payment(4200.00, 'Case order', ['idempotency_key' => 'Customer-A-Key']);
        $this->assertTrue((bool)$first['ok']);

        $second = $plugin->create_payment(4200.00, 'Case order', ['idempotency_key' => 'customer-a-key']);
        $this->assertTrue((bool)$second['ok']);

        $this->assertNotSame(
            (string)$first['data']['invoiceId'],
            (string)$second['data']['invoiceId'],
            'keys differing only by case are different keys and must not replay the same invoice'
        );
        $this->assertSame(2, $this->count_payment_rows(), 'each distinct key must own its payment row');
        $this->assertSame(2, $mono->invoice_count(), 'each distinct key must create its own monobank invoice');
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function make_configured_plugin(Api $api): Monopay
    {
        $plugin = new Monopay();

        $reflection = new ReflectionClass($plugin);
        $reflection->getProperty('api')->setValue($plugin, $api);
        $reflection->getProperty('order')->setValue($plugin, new Order($api));
        $reflection->getProperty('public_key')->setValue($plugin, null);

        $pm = PluginManager::instance();
        $pm_reflection = new ReflectionClass($pm);
        $plugins_property = $pm_reflection->getProperty('plugins');
        $plugins = $plugins_property->getValue($pm);
        $plugins['Monopay'] = $plugin;
        $plugins_property->setValue($pm, $plugins);

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

    /** @return array<string, mixed> shape returned by Api::get_invoice_status() */
    private function status_api_response(
        string $invoice_id,
        ?string $reference,
        int $amount_minor,
        int $final_amount_minor,
        string $status
    ): array {
        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'invoiceId' => $invoice_id,
                'status' => $status,
                'amount' => $amount_minor,
                'finalAmount' => $final_amount_minor,
                'ccy' => Monopay::CURRENCY_UAH,
                'reference' => $reference,
                'destination' => 'Bug hunt order',
                'createdDate' => '2026-09-06T10:00:00Z',
                'modifiedDate' => '2026-09-06T10:01:00Z',
                'cancelList' => [],
            ],
        ];
    }

    private function listen_for_hooks(): void
    {
        $tags = [
            MonopayHook::PAYMENT_SUCCESS->value,
            MonopayHook::PAYMENT_VERIFIED->value,
            MonopayHook::PAYMENT_VERIFICATION_FAILED->value,
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

    private function reload_payment_by_uuid(string $uuid): Payment
    {
        $payment = (new Payment())->get_by_uuid($uuid);
        $this->assertNotFalse($payment, "payment row for {$uuid} must exist");

        return $payment;
    }

    private function backdate_payment(string $uuid): void
    {
        $payment = $this->reload_payment_by_uuid($uuid);
        $table = Payment::resolveConfiguration()['table'];
        $db = \Base::instance()->get('DB');
        $db->exec(
            "UPDATE `{$table}` SET updated_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s', time() - 3600), (int)$payment->get('id')]
        );
    }

    private function count_payment_rows(): int
    {
        $db = \Base::instance()->get('DB');
        $table = Payment::resolveConfiguration()['table'];

        return (int)($db->exec("SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'] ?? 0);
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
}

/**
 * Status API stand-in that returns scripted raw status payloads per invoice.
 */
final class BugHuntStatusApi extends Api
{
    /** @var array<string, array<string, mixed>> invoice_id => raw Api::get_invoice_status() shape */
    public array $responses = [];

    /** @var array<string, callable> invoice_id => side effect before the response */
    public array $callbacks = [];

    public int $status_calls = 0;

    /** @var array<string, int> invoice_id => status call count */
    public array $status_calls_by_invoice = [];

    public function __construct()
    {
        parent::__construct('bug-hunt-token');
    }

    public function get_invoice_status(string $invoice_id): array
    {
        $this->status_calls++;
        $this->status_calls_by_invoice[$invoice_id] = ($this->status_calls_by_invoice[$invoice_id] ?? 0) + 1;

        if (isset($this->callbacks[$invoice_id])) {
            ($this->callbacks[$invoice_id])($invoice_id);
        }

        if (isset($this->responses[$invoice_id])) {
            return $this->responses[$invoice_id];
        }

        return ['ok' => false, 'error' => 'NOT_FOUND', 'data' => null];
    }
}
