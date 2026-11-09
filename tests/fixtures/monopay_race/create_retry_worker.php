<?php
declare(strict_types=1);

/**
 * Child process for the create-retry contract races in
 * Tests\Engine\Plugins\MonopayConcurrencyRaceTest.
 *
 * Two modes:
 *
 *   seed   — runs ONE create() whose monobank response is lost, leaving the
 *            preliminary payment row (and, once the contract is implemented,
 *            its idempotency key) behind. Prints a JSON result and exits.
 *
 *   create — prints READY, blocks on the gate file, then runs one create()
 *            with the same idempotency key as the other workers. Every
 *            actual monobank invoice/create call is appended to the counter
 *            file, so the parent can count cross-process calls.
 *
 * Usage: php create_retry_worker.php <mode> <amount> <destination> <key> <counter-file> <gate-file>
 */

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Engine\Atomic\Plugins\Monopay\Api;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Order;

/**
 * Network-free monobank stand-in. In `create` mode it records every
 * invoice/create call to the counter file (cross-process, append + lock) and
 * always succeeds.
 */
final class RaceCountingMonobankApi extends Api
{
    private int $invoice_counter = 0;

    public function __construct(
        string $token,
        private string $counter_file,
        private bool $seed
    ) {
        parent::__construct($token);
    }

    public function create_invoice(array $data): array
    {
        $reference = (string)($data['merchantPaymInfo']['reference'] ?? '');

        if ($this->seed) {
            // monobank accepted the request, the response never arrived.
            return [
                'ok' => false,
                'error' => 'cURL error 28: Connection timed out after 30 seconds',
                'status' => 0,
                'data' => null,
                'raw_body' => null,
                'url' => 'https://api.monobank.ua/api/merchant/invoice/create',
            ];
        }

        $this->invoice_counter++;
        $invoice_id = 'race_' . getmypid() . '_' . $this->invoice_counter;

        file_put_contents(
            $this->counter_file,
            (string)json_encode([
                'reference' => $reference,
                'invoice_id' => $invoice_id,
                'pid' => getmypid(),
                'at' => microtime(true),
            ]) . "\n",
            FILE_APPEND | LOCK_EX
        );

        return [
            'ok' => true,
            'data' => [
                'invoiceId' => $invoice_id,
                'pageUrl' => 'https://pay.mbnk.biz/' . $invoice_id,
            ],
            'error' => null,
        ];
    }
}

$mode = (string)($argv[1] ?? '');
$amount = (float)($argv[2] ?? 0);
$destination = (string)($argv[3] ?? '');
$key = (string)($argv[4] ?? '');
$counter_file = (string)($argv[5] ?? '');
$gate = (string)($argv[6] ?? '');

if (!in_array($mode, ['seed', 'create'], true) || $amount <= 0 || $key === '') {
    fwrite(STDERR, "usage: create_retry_worker.php <seed|create> <amount> <destination> <key> <counter-file> <gate-file>\n");
    exit(2);
}

if ($mode === 'create' && ($counter_file === '' || $gate === '')) {
    fwrite(STDERR, "create mode requires a counter file and a gate file\n");
    exit(2);
}

$db = \Base::instance()->get('DB');
if ($db === null) {
    fwrite(STDERR, "no DB available\n");
    exit(2);
}

$is_seed = $mode === 'seed';

// Warm the connection and the Cortex model metadata BEFORE the gate opens,
// so the tested window contains only the create() logic itself.
$db->exec('SELECT 1');
(new Payment())->get_by_uuid('create-warmup-' . getmypid());

$api = new RaceCountingMonobankApi('test-token', $counter_file, $is_seed);

$plugin = new Monopay();
$reflection = new ReflectionClass($plugin);
$reflection->getProperty('api')->setValue($plugin, $api);
$reflection->getProperty('order')->setValue($plugin, new Order($api));
$reflection->getProperty('public_key')->setValue($plugin, null);

$options = ['idempotency_key' => $key];

if ($is_seed) {
    $result = $plugin->create_payment($amount, $destination, $options);

    fwrite(STDOUT, (string)json_encode([
        'ok' => (bool)($result['ok'] ?? false),
        'payment_uuid' => $result['payment_uuid'] ?? null,
        'error' => $result['error'] ?? null,
    ]) . "\n");
    fflush(STDOUT);

    exit(0);
}

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

$result = $plugin->create_payment($amount, $destination, $options);

$out = [
    'ok' => (bool)($result['ok'] ?? false),
    'error' => $result['error'] ?? null,
];

if (($result['ok'] ?? false) === true) {
    $out['invoice_id'] = (string)($result['data']['invoiceId'] ?? '');
    $out['page_url'] = (string)($result['data']['pageUrl'] ?? '');
    $out['reference'] = (string)($result['reference'] ?? '');
}

if (isset($result['payment_uuid'])) {
    $out['payment_uuid'] = $result['payment_uuid'];
}

fwrite(STDOUT, (string)json_encode($out) . "\n");
fflush(STDOUT);

exit(0);
