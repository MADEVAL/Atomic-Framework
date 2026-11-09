<?php
declare(strict_types=1);

/**
 * Child process for Tests\Engine\Plugins\MonopayConcurrencyRaceTest.
 *
 * Runs one verified success event. Its status API call blocks after the
 * success transition has been committed, so the parent can commit a
 * reversal (a parallel webhook delivery) while this worker is between
 * re-verification and the fulfillment claim.
 *
 * Usage: php fulfil_after_reverse_worker.php <uuid> <invoice-id> <verify-file> <gate-file>
 */

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Api;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Order;
use Engine\Atomic\Plugins\Monopay\WebhookHandler;

$uuid = (string)($argv[1] ?? '');
$invoice_id = (string)($argv[2] ?? '');
$verify_file = (string)($argv[3] ?? '');
$gate_file = (string)($argv[4] ?? '');

if ($uuid === '' || $invoice_id === '' || $verify_file === '' || $gate_file === '') {
    fwrite(STDERR, "usage: fulfil_after_reverse_worker.php <uuid> <invoice-id> <verify-file> <gate-file>\n");
    exit(2);
}

/**
 * Status API stand-in that snapshots a successful invoice but allows the
 * parent to commit a reversal before it answers.
 */
final class BugHuntBlockingStatusApi extends Api
{
    public function __construct(
        private string $uuid,
        private string $invoice_id,
        private string $verify_file,
        private string $gate_file
    ) {
        parent::__construct('bug-hunt-token');
    }

    public function get_invoice_status(string $invoice_id): array
    {
        file_put_contents($this->verify_file, 'VERIFYING');
        fflush(STDOUT);

        $deadline = microtime(true) + 30.0;
        while (!file_exists($this->gate_file)) {
            if (microtime(true) >= $deadline) {
                fwrite(STDERR, "gate timeout\n");
                exit(3);
            }
            usleep(200);
        }

        return [
            'ok' => true,
            'error' => null,
            'data' => [
                'invoiceId' => $this->invoice_id,
                'status' => 'success',
                'amount' => 420000,
                'finalAmount' => 420000,
                'ccy' => 980,
                'reference' => $this->uuid,
                'createdDate' => '2026-09-06T10:00:00Z',
                'modifiedDate' => '2026-09-06T10:01:00Z',
                'destination' => 'Bug hunt order',
                'cancelList' => [],
            ],
        ];
    }
}

$api = new BugHuntBlockingStatusApi($uuid, $invoice_id, $verify_file, $gate_file);
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
require_once ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/global_functions.php';

$success_hooks = 0;
$verified_hooks = 0;

Hook::instance()->add_action(
    MonopayHook::PAYMENT_SUCCESS->value,
    static function () use (&$success_hooks): void {
        $success_hooks++;
    },
    10,
    5
);

Hook::instance()->add_action(
    MonopayHook::PAYMENT_VERIFIED->value,
    static function () use (&$verified_hooks): void {
        $verified_hooks++;
    },
    10,
    5
);

try {
    WebhookHandler::apply_verified_event([
        'invoice_id' => $invoice_id,
        'status' => 'success',
        'amount' => 4200.00,
        'final_amount' => 4200.00,
        'currency' => 980,
        'reference' => $uuid,
        'destination' => 'Bug hunt order',
    ]);

    $fresh = (new Payment())->get_by_uuid($uuid);

    $result = [
        'ok' => true,
        'status' => $fresh ? (string)$fresh->get('status') : null,
        'fulfilled' => $fresh ? $fresh->get('fulfilled_at') !== null : null,
        'success_hooks' => $success_hooks,
        'verified_hooks' => $verified_hooks,
    ];
} catch (\Throwable $e) {
    $result = [
        'ok' => false,
        'error' => $e->getMessage(),
        'success_hooks' => $success_hooks,
    ];
}

fwrite(STDOUT, (string)json_encode($result) . "\n");
fflush(STDOUT);

exit(0);
