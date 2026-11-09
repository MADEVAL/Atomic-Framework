<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Tests\Support\StreamCapture;
use PHPUnit\Framework\TestCase;
use Tests\Support\PlatformGuard;

/**
 * Regression test for Finding A of docs/monopay_concurrency_race_report.md:
 * parallel duplicate webhook deliveries are not deduplicated.
 *
 * WebhookHandler::update_payment_from_webhook() reads the payment status,
 * compares it to the delivery's status, then updates (check-then-act). Under
 * real OS-level concurrency every delivery can read 'created' before any of
 * them commits, so all of them pass the duplicate check, append their own
 * payment_history row and fire the leaf status hook.
 *
 * The test spawns 8 PHP worker processes (tests/fixtures/monopay_race/
 * webhook_delivery_worker.php) that all block on a gate file, releases them
 * together, and asserts that a single logical transition accepts exactly one
 * delivery, writes exactly one history row and fires the leaf hook once.
 *
 * On the unfixed code this test fails. It is a regression guard: keep it in
 * the suite and do not weaken the assertions.
 */
final class MonopayConcurrencyRaceTest extends TestCase
{
    private const CHILDREN = 8;
    private const GATE_TIMEOUT_SECONDS = 30;

    /** @var list<array{proc: resource, out: resource, err: resource}> */
    private array $children = [];

    private ?string $gate_file = null;

    /** Cross-process invoice/create counter used by the create-retry races. */
    private ?string $counter_file = null;

    /** @var list<string> */
    private array $temp_files = [];

    public static function setUpBeforeClass(): void
    {
        PlatformGuard::requireExtension('openssl');
        PlatformGuard::requireMySql();

        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is required for the multi-process race test.');
        }
    }

    protected function setUp(): void
    {
        if (\Base::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }

        $this->create_plugin_tables();

        $this->gate_file = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'atomic_monopay_race_'
            . bin2hex(random_bytes(8));
        @unlink($this->gate_file);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $this->close_child($child, true);
        }
        $this->children = [];

        if ($this->gate_file !== null) {
            @unlink($this->gate_file);
            $this->gate_file = null;
        }

        if ($this->counter_file !== null) {
            @unlink($this->counter_file);
            $this->counter_file = null;
        }

        foreach ($this->temp_files as $file) {
            @unlink($file);
        }
        $this->temp_files = [];

        $this->clear_plugin_tables();
    }

    public function test_parallel_duplicate_success_webhooks_are_accepted_exactly_once(): void
    {
        $payment = Payment::create_preliminary(4200.00, 980, 'Race order');
        $this->assertInstanceOf(Payment::class, $payment, 'race fixture must create a preliminary payment');

        $uuid = (string)$payment->get('uuid');
        $this->assertNotSame('', $uuid, 'race payment must carry a reference');

        $worker = ATOMIC_FRAMEWORK
            . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR
            . 'monopay_race' . DIRECTORY_SEPARATOR
            . 'webhook_delivery_worker.php';
        $this->assertFileExists($worker);

        for ($i = 0; $i < self::CHILDREN; $i++) {
            $this->children[] = $this->spawn_worker($worker, $uuid, (string)$this->gate_file);
        }

        $this->wait_for_all_ready();

        // Open the gate: every worker now enters the check-then-act window
        // within microseconds of the others.
        touch((string)$this->gate_file);

        $results = $this->collect_results();

        $this->assertCount(
            self::CHILDREN,
            $results,
            'every race worker must report a result; workers that crashed printed no JSON'
        );

        $accepted = 0;
        $history_logged = 0;
        $leaf_hook_fires = 0;

        foreach ($results as $result) {
            if (($result['accepted'] ?? false) === true) {
                $accepted++;
            }
            if (($result['history_logged'] ?? false) === true) {
                $history_logged++;
            }
            $leaf_hook_fires += (int)($result['leaf_hook_fires'] ?? 0);
        }

        $history_rows = PaymentHistory::count_by_payment_uuid($uuid);

        $observed = sprintf(
            'observed: accepted_deliveries=%d history_logged_by_handlers=%d history_rows=%d leaf_hook_fires=%d (expected 1/1/1/1)',
            $accepted,
            $history_logged,
            $history_rows,
            $leaf_hook_fires
        );

        $this->assertSame(
            1,
            $accepted,
            'Exactly ONE of the parallel duplicate deliveries may be accepted as a status transition. ' . $observed
        );

        $this->assertSame(
            1,
            $history_rows,
            'Exactly ONE payment_history row may be written for one logical status change. ' . $observed
        );

        $this->assertSame(
            1,
            $leaf_hook_fires,
            'The leaf status hook must fire exactly once in total across all deliveries. ' . $observed
        );
    }

    /**
     * Two DIFFERENT valid transitions race from the same old status
     * ('created' -> 'processing' vs 'created' -> 'success'). Monobank does
     * not guarantee webhook delivery order, so both deliveries can read
     * 'created' and then race their compare-and-swap.
     *
     * Deterministic single-process interleaving:
     *   1. both deliveries read the payment in status 'created';
     *   2. the 'processing' delivery commits first and wins the CAS;
     *   3. the 'success' delivery still carries the stale 'created' read.
     *
     * On the buggy code step 3 loses the CAS and is dropped, so the payment
     * stays 'processing' forever even though monobank reported 'success'
     * (the handler answers 200 and monobank stops retrying). The fix must
     * re-read the committed status and replay the still-valid transition.
     */
    public function test_cas_loss_to_a_different_valid_transition_is_replayed(): void
    {
        $payment = Payment::create_preliminary(4200.00, 980, 'Race order');
        $this->assertInstanceOf(Payment::class, $payment, 'race fixture must create a preliminary payment');

        $uuid = (string)$payment->get('uuid');
        $invoice_id = 'race_invoice_' . bin2hex(random_bytes(4));

        $processing_delivery = (new Payment())->get_by_uuid($uuid);
        $success_delivery = (new Payment())->get_by_uuid($uuid);

        $this->assertInstanceOf(Payment::class, $processing_delivery);
        $this->assertInstanceOf(Payment::class, $success_delivery);
        $this->assertSame('created', (string)$processing_delivery->get('status'));
        $this->assertSame('created', (string)$success_delivery->get('status'));

        $processing_committed = $processing_delivery->update_from_webhook([
            'reference' => $uuid,
            'status' => 'processing',
            'invoice_id' => $invoice_id,
            'amount' => 420000,
        ], 'created');

        $this->assertTrue(
            $processing_committed,
            'the processing delivery must win the CAS from its stale created read'
        );

        $success_committed = $success_delivery->update_from_webhook([
            'reference' => $uuid,
            'status' => 'success',
            'invoice_id' => $invoice_id,
            'amount' => 420000,
            'final_amount' => 420000,
        ], 'created');

        $reloaded = (new Payment())->get_by_uuid($uuid);
        $this->assertInstanceOf(Payment::class, $reloaded);

        $this->assertSame(
            'success',
            (string)$reloaded->get('status'),
            'a success delivery that loses the CAS to a parallel valid transition must be replayed, not dropped'
        );
        $this->assertTrue($success_committed, 'the replayed transition must report an accepted state change');
        $this->assertSame(
            'processing',
            $success_delivery->transitioned_from(),
            'the replayed transition must report the freshly committed status as its origin'
        );
    }

    /**
     * OS-level version of the same scenario: half the workers deliver
     * 'processing', half deliver 'success'. Delivery order is unspecified,
     * so whichever transition wins the first CAS, the payment must still
     * end in 'success' and the success leaf hook must fire exactly once.
     * payment_history must never contain two rows for the same status.
     */
    public function test_parallel_mixed_transitions_never_drop_the_success_delivery(): void
    {
        $payment = Payment::create_preliminary(4200.00, 980, 'Race order');
        $this->assertInstanceOf(Payment::class, $payment, 'race fixture must create a preliminary payment');

        $uuid = (string)$payment->get('uuid');
        $this->assertNotSame('', $uuid, 'race payment must carry a reference');

        $worker = ATOMIC_FRAMEWORK
            . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR
            . 'monopay_race' . DIRECTORY_SEPARATOR
            . 'webhook_delivery_worker.php';
        $this->assertFileExists($worker);

        $statuses = array_merge(
            array_fill(0, intdiv(self::CHILDREN, 2), 'processing'),
            array_fill(0, intdiv(self::CHILDREN, 2), 'success')
        );

        foreach ($statuses as $status) {
            $this->children[] = $this->spawn_worker($worker, $uuid, (string)$this->gate_file, $status);
        }

        $this->wait_for_all_ready();
        touch((string)$this->gate_file);

        $results = $this->collect_results();

        $this->assertCount(
            self::CHILDREN,
            $results,
            'every race worker must report a result; workers that crashed printed no JSON'
        );

        $accepted = 0;
        $success_leaf_fires = 0;

        foreach ($results as $result) {
            if (($result['accepted'] ?? false) === true) {
                $accepted++;
            }
            if (($result['status'] ?? '') === 'success') {
                $success_leaf_fires += (int)($result['leaf_hook_fires'] ?? 0);
            }
        }

        $reloaded = (new Payment())->get_by_uuid($uuid);
        $this->assertInstanceOf(Payment::class, $reloaded);
        $history_by_status = $this->history_counts_by_status($uuid);

        $observed = sprintf(
            'observed: accepted_deliveries=%d success_leaf_hook_fires=%d final_status=%s history=%s',
            $accepted,
            $success_leaf_fires,
            (string)$reloaded->get('status'),
            json_encode($history_by_status)
        );

        $this->assertSame(
            'success',
            (string)$reloaded->get('status'),
            'the payment must end in success whichever transition wins the first CAS. ' . $observed
        );
        $this->assertSame(
            1,
            $success_leaf_fires,
            'the success leaf status hook must fire exactly once in total across all deliveries. ' . $observed
        );
        $this->assertSame(
            1,
            $history_by_status['success'] ?? 0,
            'exactly one success history row may be written. ' . $observed
        );
        $this->assertLessThanOrEqual(
            1,
            $history_by_status['processing'] ?? 0,
            'processing history must never be duplicated. ' . $observed
        );
    }

    /**
     * A success delivery commits the success transition, calls the status
     * API to re-verify, and only THEN claims fulfillment. Delivery order is
     * not guaranteed, so a parallel `reversed` delivery can commit between
     * the verification response and the claim.
     *
     * Correct behaviour: when the payment is no longer `success` at claim
     * time, the success hooks must not fire and fulfilled_at must stay
     * NULL — the money was refunded before anything was delivered.
     *
     * This test proves the interleaving with two real OS processes: the
     * success worker blocks inside its status API call (after the success
     * transition is committed) until the parent has committed `reversed`.
     */
    public function test_reversed_payment_is_not_fulfilled_by_a_racing_success_delivery(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is required for the two-process race.');
        }

        $payment = Payment::create_preliminary(4200.00, Monopay::CURRENCY_UAH, 'Race order');
        $this->assertInstanceOf(Payment::class, $payment, 'race fixture must create a payment');
        $uuid = (string)$payment->get('uuid');
        $invoice_id = 'race_invoice_' . bin2hex(random_bytes(4));
        $this->assertTrue($payment->bind_invoice($invoice_id, 'https://pay.mbnk.biz/' . $invoice_id));

        $verify_file = $this->temp_path('verify');
        $gate_file = $this->temp_path('gate');
        @unlink($gate_file);

        $worker = ATOMIC_FRAMEWORK
            . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR
            . 'monopay_race' . DIRECTORY_SEPARATOR
            . 'fulfil_after_reverse_worker.php';
        $this->assertFileExists($worker);

        $this->children[] = $this->spawn_script($worker, [$uuid, $invoice_id, $verify_file, $gate_file]);

        $this->wait_for_file($verify_file, 'success worker never reached status re-verification');

        // The success transition is committed; the worker is parked inside
        // its status API call. A parallel reversed delivery now wins the
        // success -> reversed CAS.
        $live = (new Payment())->get_by_uuid($uuid);
        $this->assertInstanceOf(Payment::class, $live);
        $this->assertTrue(
            $live->update_from_webhook([
                'reference' => $uuid,
                'invoice_id' => $invoice_id,
                'status' => PaymentStatus::REVERSED->value,
            ], PaymentStatus::SUCCESS->value),
            'fixture: the reversal must win the CAS while the success worker is parked'
        );

        // Release the success worker: verification still reports success
        // (the response was snapshotted before the reversal), so it proceeds
        // to the fulfillment claim.
        touch($gate_file);

        $result = $this->collect_single_result();

        $this->assertSame(PaymentStatus::REVERSED->value, (string)$this->reload_payment_by_uuid($uuid)->get('status'));
        $this->assertFalse(
            (bool)($result['fulfilled'] ?? true),
            'a payment reversed before the fulfillment claim must NOT be fulfilled; worker result: ' . json_encode($result)
        );
        $this->assertSame(
            0,
            (int)($result['success_hooks'] ?? -1),
            'PAYMENT_SUCCESS must not fire for a payment that is reversed at claim time; worker result: ' . json_encode($result)
        );
    }

    /**
     * Create-retry contract (6): N concurrent create() calls with the SAME
     * fresh idempotency key must converge on exactly ONE local payment row
     * and exactly ONE monobank invoice/create call. Losers either replay the
     * winner's invoice data or report a retryable "in progress" error — they
     * must never mint a second row or a second invoice.
     */
    public function test_parallel_create_with_same_key_makes_one_row_and_one_invoice(): void
    {
        $worker = $this->create_worker_path();
        $counter = $this->make_counter_file();
        $key = 'race-order-' . bin2hex(random_bytes(6));

        for ($i = 0; $i < self::CHILDREN; $i++) {
            $this->children[] = $this->spawn_script($worker, [
                'create',
                '4200.00',
                'Race order',
                $key,
                $counter,
                (string)$this->gate_file,
            ]);
        }

        $this->wait_for_all_ready();
        touch((string)$this->gate_file);

        $results = $this->collect_results();
        $calls = $this->read_create_calls($counter);

        $this->assertCount(self::CHILDREN, $results, 'every create racer must report a result');
        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'N concurrent creates with the same key must converge on exactly ONE local payment row; '
            . 'observed rows=' . $this->count_payment_rows() . ', invoice/create calls=' . count($calls)
        );
        $this->assertCount(
            1,
            $calls,
            'exactly one monobank invoice/create call may happen across all concurrent creates with the same key; '
            . 'observed calls=' . count($calls)
        );

        $winning_invoice = (string)($calls[0]['invoice_id'] ?? '');

        foreach ($results as $index => $result) {
            if (($result['ok'] ?? false) === true) {
                $this->assertSame(
                    $winning_invoice,
                    (string)($result['invoice_id'] ?? ''),
                    'a successful loser must replay the winner\'s invoice, not report its own (racer #' . $index . ')'
                );
                continue;
            }

            $this->assertNotEmpty(
                (string)($result['error'] ?? ''),
                'a losing create must report a retryable "in progress" error (racer #' . $index . ')'
            );
        }
    }

    /**
     * Create-retry contract (3): with an existing pending row for key K, 8
     * parallel retries with key K must keep exactly ONE row and converge on
     * ONE reference. The atomic claim means only the single owner of the
     * resume may call invoice/create; the rest replay or report "in progress".
     */
    public function test_parallel_retries_with_same_key_keep_one_row_and_one_reference(): void
    {
        $worker = $this->create_worker_path();
        $counter = $this->make_counter_file();
        $key = 'race-retry-' . bin2hex(random_bytes(6));

        // Seed the ambiguous first attempt through the real public API so the
        // pending row carries the idempotency key exactly like production.
        $seed = $this->run_worker_sync($worker, ['seed', '4200.00', 'Race order', $key, '', '']);
        $this->assertFalse((bool)($seed['ok'] ?? true), 'the seed attempt must report its transport failure');

        $this->assertSame(1, $this->count_payment_rows(), 'the seed attempt must leave exactly one pending payment row');
        $pending_uuid = $this->single_payment_uuid();
        $this->assertNotSame('', $pending_uuid, 'the pending payment must carry a reference');

        for ($i = 0; $i < self::CHILDREN; $i++) {
            $this->children[] = $this->spawn_script($worker, [
                'create',
                '4200.00',
                'Race order',
                $key,
                $counter,
                (string)$this->gate_file,
            ]);
        }

        $this->wait_for_all_ready();
        touch((string)$this->gate_file);

        $results = $this->collect_results();
        $calls = $this->read_create_calls($counter);

        $this->assertCount(self::CHILDREN, $results, 'every retry racer must report a result');
        $this->assertSame(
            1,
            $this->count_payment_rows(),
            'parallel retries with the same key must keep exactly ONE local payment row; observed rows='
            . $this->count_payment_rows() . ', invoice/create calls=' . count($calls)
        );
        $this->assertCount(
            1,
            $calls,
            'only the single owner of the resume claim may call invoice/create; observed calls=' . count($calls)
        );
        $this->assertSame(
            $pending_uuid,
            (string)($calls[0]['reference'] ?? ''),
            'the resumed invoice must carry the pending payment\'s reference'
        );

        $winning_invoice = (string)($calls[0]['invoice_id'] ?? '');

        foreach ($results as $index => $result) {
            if (($result['ok'] ?? false) === true) {
                $this->assertSame(
                    $winning_invoice,
                    (string)($result['invoice_id'] ?? ''),
                    'a successful loser must replay the winner\'s invoice (racer #' . $index . ')'
                );
                continue;
            }

            $this->assertNotEmpty(
                (string)($result['error'] ?? ''),
                'a losing retry must report a retryable "in progress" error (racer #' . $index . ')'
            );
        }
    }

    /** @return array<string, int> status => row count */
    private function history_counts_by_status(string $uuid): array
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return [];
        }

        $table = PaymentHistory::resolveConfiguration()['table'];
        $rows = $db->exec(
            "SELECT status, COUNT(*) AS c FROM `{$table}` WHERE payment_uuid = ? GROUP BY status",
            [$uuid]
        );

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[(string)$row['status']] = (int)$row['c'];
        }

        return $counts;
    }

    /** @return array{proc: resource, out: resource, err: resource} */
    private function spawn_worker(string $worker, string $uuid, string $gate, string $status = 'success'): array
    {
        return $this->spawn_script($worker, [$uuid, $gate, $status]);
    }

    /**
     * Starts a PHP fixture with the given args and returns its pipes.
     *
     * @param list<string> $args
     * @return array{proc: resource, out: resource, err: resource}
     */
    private function spawn_script(string $script, array $args): array
    {
        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, $script], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            ATOMIC_FRAMEWORK
        );

        $this->assertIsResource($process, 'proc_open() must start the race worker');
        fclose($pipes[0]);

        return ['proc' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
    }

    private function temp_path(string $prefix): string
    {
        $file = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'atomic_monopay_race_' . $prefix . '_' . bin2hex(random_bytes(8));
        $this->temp_files[] = $file;

        return $file;
    }

    private function wait_for_file(string $file, string $message): void
    {
        $deadline = microtime(true) + self::GATE_TIMEOUT_SECONDS;
        while (!file_exists($file)) {
            if (microtime(true) >= $deadline) {
                $stderr = '';
                if (isset($this->children[0])) {
                    $stderr = (string)stream_get_contents($this->children[0]['err']);
                }
                $this->fail($message . '; worker stderr: ' . $stderr);
            }
            usleep(1000);
        }
    }

    /** @return array<string, mixed> */
    private function collect_single_result(): array
    {
        $this->assertCount(1, $this->children);
        $child = $this->children[0];

        $stdout = (string)stream_get_contents($child['out']);
        $stderr = (string)stream_get_contents($child['err']);
        fclose($child['out']);
        fclose($child['err']);
        $exit_code = proc_close($child['proc']);
        $this->children = [];

        $this->assertSame(0, $exit_code, 'worker must exit cleanly; stderr: ' . $stderr);

        foreach (array_filter(array_map('trim', explode("\n", $stdout))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->fail('worker must print a JSON result; stdout: ' . $stdout . '; stderr: ' . $stderr);
    }

    private function reload_payment_by_uuid(string $uuid): Payment
    {
        $payment = (new Payment())->get_by_uuid($uuid);
        $this->assertNotFalse($payment, "payment row for {$uuid} must exist");

        return $payment;
    }

    private function create_worker_path(): string
    {
        $worker = ATOMIC_FRAMEWORK
            . 'tests' . DIRECTORY_SEPARATOR
            . 'fixtures' . DIRECTORY_SEPARATOR
            . 'monopay_race' . DIRECTORY_SEPARATOR
            . 'create_retry_worker.php';

        $this->assertFileExists($worker);

        return $worker;
    }

    /** Creates an empty cross-process counter file and schedules its cleanup. */
    private function make_counter_file(): string
    {
        $file = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'atomic_monopay_create_counter_'
            . bin2hex(random_bytes(8));

        file_put_contents($file, '');
        $this->counter_file = $file;

        return $file;
    }

    /**
     * Runs a fixture to completion (no gate) and returns its JSON result.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function run_worker_sync(string $worker, array $args): array
    {
        $child = $this->spawn_script($worker, $args);

        $stdout = (string)stream_get_contents($child['out']);
        $stderr = (string)stream_get_contents($child['err']);
        fclose($child['out']);
        fclose($child['err']);
        $exit_code = proc_close($child['proc']);

        $this->assertSame(0, $exit_code, 'seed worker must exit cleanly; stderr: ' . $stderr);

        foreach (array_filter(array_map('trim', explode("\n", $stdout))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $this->fail('seed worker must print a JSON result; stdout: ' . $stdout);
    }

    /** @return list<array<string, mixed>> one entry per monobank invoice/create call */
    private function read_create_calls(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $calls = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $calls[] = $decoded;
            }
        }

        return $calls;
    }

    private function count_payment_rows(): int
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return 0;
        }

        $table = Payment::resolveConfiguration()['table'];

        return (int)($db->exec("SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'] ?? 0);
    }

    private function single_payment_uuid(): string
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return '';
        }

        $table = Payment::resolveConfiguration()['table'];
        $rows = $db->exec("SELECT uuid FROM `{$table}` LIMIT 1");

        return (string)($rows[0]['uuid'] ?? '');
    }

    private function wait_for_all_ready(): void
    {
        foreach ($this->children as $index => $child) {
            $line = fgets($child['out']);
            $trimmed = trim((string)$line);

            if ($trimmed !== 'READY') {
                $stderr = (string)stream_get_contents($child['err']);
                $this->fail(sprintf(
                    'race worker #%d failed to signal READY (got %s, stderr: %s)',
                    $index,
                    var_export($line, true),
                    $stderr
                ));
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect_results(): array
    {
        $results = [];
        $diagnostics = [];

        foreach ($this->children as $index => $child) {
            $stdout = (string)stream_get_contents($child['out']);
            $stderr = (string)stream_get_contents($child['err']);
            fclose($child['out']);
            fclose($child['err']);
            $exit_code = proc_close($child['proc']);

            foreach (array_filter(array_map('trim', explode("\n", $stdout))) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $results[] = $decoded;
                }
            }

            if ($stderr !== '' || $exit_code !== 0) {
                $diagnostics[] = sprintf(
                    'worker #%d exit=%d stderr=%s',
                    $index,
                    $exit_code,
                    $stderr
                );
            }
        }

        // Resources are closed above; tearDown must not touch them again.
        $this->children = [];

        $this->assertSame(
            [],
            $diagnostics,
            'race workers must exit cleanly: ' . implode(' | ', $diagnostics)
        );

        return $results;
    }

    /**
     * @param array{proc: resource, out: resource, err: resource} $child
     */
    private function close_child(array $child, bool $terminate): void
    {
        if ($terminate && is_resource($child['proc'])) {
            $status = proc_get_status($child['proc']);
            if (($status['running'] ?? false) === true) {
                proc_terminate($child['proc']);
            }
        }

        foreach (['out', 'err'] as $pipe) {
            if (is_resource($child[$pipe])) {
                fclose($child[$pipe]);
            }
        }

        if (is_resource($child['proc'])) {
            proc_close($child['proc']);
        }
    }

    private function create_plugin_tables(): void
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return;
        }

        // Drop first: the migration skips existing tables, so stale tables
        // from older schema versions would never be upgraded.
        $history_table = PaymentHistory::resolveConfiguration()['table'];
        $payments_table = Payment::resolveConfiguration()['table'];
        $db->exec("DROP TABLE IF EXISTS `{$history_table}`");
        $db->exec("DROP TABLE IF EXISTS `{$payments_table}`");

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
