<?php
declare(strict_types=1);

/**
 * Child process for Tests\Engine\Plugins\MonopayConcurrencyRaceTest.
 *
 * Runs the real (private) WebhookHandler::update_payment_from_webhook()
 * against a payment whose status is still 'created', then logs history
 * exactly like WebhookHandler::handle() does.
 *
 * The process prints READY, blocks on a gate file, and only starts the
 * transition once the parent opens the gate — so all workers enter the
 * read-then-update window at the same time.
 *
 * Usage: php webhook_delivery_worker.php <payment-uuid> <gate-file> [status]
 *
 * The optional status (default 'success') lets the parent run a mixed
 * race where different valid transitions compete from the same old state.
 */

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;
use Engine\Atomic\Plugins\Monopay\WebhookHandler;

$uuid = (string)($argv[1] ?? '');
$gate = (string)($argv[2] ?? '');
$status = (string)($argv[3] ?? PaymentStatus::SUCCESS->value);

if ($uuid === '' || $gate === '') {
    fwrite(STDERR, "usage: webhook_delivery_worker.php <payment-uuid> <gate-file> [status]\n");
    exit(2);
}

$status_enum = PaymentStatus::tryFrom($status);

if (!$status_enum) {
    fwrite(STDERR, "unknown status: {$status}\n");
    exit(2);
}

$db = \Base::instance()->get('DB');
if ($db === null) {
    fwrite(STDERR, "no DB available\n");
    exit(2);
}

// Warm the connection, the Cortex model metadata and the hook registry
// BEFORE the gate opens, so the only work between the release and the
// tested read-then-update window is the transition logic itself.
$db->exec('SELECT 1');
(new Payment())->get_by_uuid('race-warmup-' . getmypid());
PaymentHistory::count_by_payment_uuid('race-warmup-' . getmypid());

// Count leaf status-hook firings in this worker. The handler emits ONLY
// the leaf tag, so one accepted delivery must produce exactly one fire.
$leaf_hook_fires = 0;
Hook::instance()->add_action(
    MonopayHook::status($status_enum->value),
    static function () use (&$leaf_hook_fires): void {
        $leaf_hook_fires++;
    },
    10,
    5
);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$deadline = microtime(true) + 30.0;
while (!file_exists($gate)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "gate timeout\n");
        exit(3);
    }
    usleep(200);
}

$data = [
    'reference' => $uuid,
    'status' => $status_enum->value,
    // Distinct per worker: the payment's invoice_id column is unique, and
    // the race under test is the status transition, not invoice binding.
    'invoice_id' => 'race_invoice_' . getmypid(),
    'amount' => 420000,
];

if ($status_enum === PaymentStatus::SUCCESS) {
    $data['final_amount'] = 420000;
}

$reflection = new ReflectionClass(WebhookHandler::class);
$method = $reflection->getMethod('update_payment_from_webhook');
$method->setAccessible(true);

$payment = $method->invoke(null, $data);

$history_logged = false;
if ($payment) {
    $history_logged = PaymentHistory::log_operation(
        (int)$payment->get('id'),
        $uuid,
        $status_enum->value,
        $data
    );
}

fwrite(STDOUT, (string)json_encode([
    'status' => $status_enum->value,
    'accepted' => $payment !== null,
    'history_logged' => $history_logged,
    'leaf_hook_fires' => $leaf_hook_fires,
]) . "\n");
fflush(STDOUT);

exit(0);
