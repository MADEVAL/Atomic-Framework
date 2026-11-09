<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;

/**
 * Reconciles local payment rows against the monobank acquiring API.
 *
 * The webhook is the primary update path, but it is not reliable enough to
 * be the only one: monobank retries a failing delivery at most 3 times
 * (after which the payment stays success + unfulfilled forever) and sends
 * NO webhook at all for 'expired' invoices (leaving pending rows stuck).
 *
 * Two kinds of rows are candidates, in strict priority order:
 *  a) success but not fulfilled — the status API failed during the webhook
 *     re-verification, so fulfilment never ran;
 *  b) created/processing/hold with an invoice whose last update is older
 *     than GRACE_PERIOD_SECONDS — the webhook may never arrive.
 *
 * Pending invoices stay open for up to a day and keep their old updated_at
 * until the customer pays, so ordering by updated_at would pin the same
 * oldest rows to the head of the queue forever and starve category (a).
 * Every processed row is therefore stamped with reconciled_at, the queue
 * is ordered by it (NULLs first), and a row polled within the backoff is
 * skipped for category (b) until GRACE_PERIOD_SECONDS have passed.
 *
 * Every fetched status is applied through WebhookHandler::apply_verified_event(),
 * the exact path a webhook uses, so the CAS transition, history row, status
 * hooks and the atomic fulfilment claim are shared. An API failure for one
 * payment is logged and counted; run() never throws.
 */
final class Reconciler
{
    /** Pending invoices younger than this are left alone (checkout in flight). */
    public const GRACE_PERIOD_SECONDS = 600;

    /**
     * A pending row polled within this window is skipped, so a single
     * unchanged invoice is re-checked at most once per grace period.
     */
    public const RECONCILE_BACKOFF_SECONDS = 600;

    /**
     * @return array{
     *     candidates: int,
     *     processed: int,
     *     updated: int,
     *     fulfilled: int,
     *     unchanged: int,
     *     errors: int
     * }
     */
    public static function run(int $limit = 50): array
    {
        $summary = self::empty_summary();

        if ($limit <= 0) {
            return $summary;
        }

        try {
            $payments = self::candidates($limit);
        } catch (\Throwable $e) {
            Log::error('Monopay: reconciliation candidate query failed - ' . $e->getMessage());

            return $summary;
        }

        $summary['candidates'] = count($payments);
        $order = self::resolve_order();

        foreach ($payments as $payment) {
            $summary['processed']++;

            $uuid = (string)$payment->get('uuid');
            $invoice_id = (string)($payment->get('invoice_id') ?? '');
            $before_status = (string)$payment->get('status');
            $before_fulfilled = $payment->get('fulfilled_at');

            try {
                if ($invoice_id === '') {
                    throw new \RuntimeException('payment has no invoice_id');
                }

                if ($order === null) {
                    throw new \RuntimeException('Monopay is not configured');
                }

                $status = $order->get_status($invoice_id);

                if (!($status['ok'] ?? false)) {
                    throw new \RuntimeException('status API failed: ' . ($status['error'] ?? 'unknown error'));
                }

                if ((string)($status['invoice_id'] ?? '') !== $invoice_id) {
                    throw new \RuntimeException('status API returned another invoice');
                }

                $fetched_reference = $status['reference'] ?? null;

                if ($fetched_reference !== null && (string)$fetched_reference !== $uuid) {
                    throw new \RuntimeException('status API returned a reference for another payment');
                }

                // Order::get_status() already returns the webhook-shaped
                // array; pin the identity fields to this row.
                $status['reference'] = $uuid;
                $status['invoice_id'] = $invoice_id;

                WebhookHandler::apply_verified_event($status);

                $fresh = (new Payment())->get_by_uuid($uuid);
                $after_status = $fresh ? (string)$fresh->get('status') : $before_status;
                $after_fulfilled = $fresh ? $fresh->get('fulfilled_at') : $before_fulfilled;

                $status_changed = $after_status !== $before_status;
                $newly_fulfilled = $before_fulfilled === null && $after_fulfilled !== null;

                if ($status_changed) {
                    $summary['updated']++;
                }

                if ($newly_fulfilled) {
                    $summary['fulfilled']++;
                }

                if (!$status_changed && !$newly_fulfilled) {
                    $summary['unchanged']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::warning('Monopay: reconciliation failed for payment ' . json_encode([
                    'payment_uuid' => $uuid,
                    'invoice_id' => $invoice_id,
                    'error' => $e->getMessage(),
                ]));
            } finally {
                // Stamp every processed candidate, success or error, so the
                // queue rotates instead of re-polling the same rows forever.
                // updated_at is deliberately untouched: an unchanged status
                // must not look freshly updated.
                try {
                    $payment->mark_reconciled();
                } catch (\Throwable $e) {
                    Log::warning('Monopay: failed to stamp reconciled_at for payment ' . json_encode([
                        'payment_uuid' => $uuid,
                        'error' => $e->getMessage(),
                    ]));
                }
            }
        }

        Log::info('Monopay: reconciliation finished ' . json_encode($summary));

        return $summary;
    }

    /** Prints the run summary for the CLI route. */
    public function run_cli(): void
    {
        (new Output())->writeln((string)json_encode(self::run()));
    }

    /**
     * Paid-but-unfulfilled rows first, then stale pending rows, each group
     * least-recently-reconciled first and bounded by $limit.
     *
     * @return Payment[]
     */
    private static function candidates(int $limit): array
    {
        $recheck_cutoff = date('Y-m-d H:i:s', time() - self::RECONCILE_BACKOFF_SECONDS);

        // (a) Paid but not fulfilled: the customer is waiting, so these go
        // first. The backoff applies here too: a row that keeps failing
        // re-verification must not hold the whole batch on every run.
        $uuids = self::candidate_uuids(
            '`status` = ? AND `fulfilled_at` IS NULL'
            . ' AND (`reconciled_at` IS NULL OR `reconciled_at` < ?)',
            [PaymentStatus::SUCCESS->value, $recheck_cutoff],
            $limit
        );

        // (b) Stale pending invoices, using whatever budget is left. Rows
        // polled within the backoff are skipped so a single unchanged
        // invoice is not re-checked on every run.
        $remaining = $limit - count($uuids);
        if ($remaining > 0) {
            $pending_cutoff = date('Y-m-d H:i:s', time() - self::GRACE_PERIOD_SECONDS);

            $uuids = array_merge($uuids, self::candidate_uuids(
                '`status` IN (?, ?, ?) AND `invoice_id` IS NOT NULL'
                . ' AND `updated_at` IS NOT NULL AND `updated_at` < ?'
                . ' AND (`reconciled_at` IS NULL OR `reconciled_at` < ?)',
                [
                    PaymentStatus::CREATED->value,
                    PaymentStatus::PROCESSING->value,
                    PaymentStatus::HOLD->value,
                    $pending_cutoff,
                    $recheck_cutoff,
                ],
                $remaining
            ));
        }

        // Hydrate the rows so callers work with the model (mark_fulfilled(),
        // field access, save semantics).
        $payments = [];
        foreach ($uuids as $uuid) {
            $payment = (new Payment())->get_by_uuid($uuid);

            if ($payment) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    /**
     * Ordered UUIDs of rows matching $where. Ordering is done in SQL because
     * COALESCE() carries a comma, which Cortex splits into separate ORDER BY
     * terms.
     *
     * @param list<mixed> $params
     * @return list<string>
     */
    private static function candidate_uuids(string $where, array $params, int $limit): array
    {
        $conf = Payment::resolveConfiguration();
        $table = $conf['table'];
        $db = $conf['db'];

        $rows = (array)$db->exec(
            "SELECT `uuid` FROM `{$table}` WHERE {$where}"
            . " ORDER BY COALESCE(`reconciled_at`, '1970-01-01') ASC, `id` ASC"
            . ' LIMIT ' . (int)$limit,
            $params
        );

        $uuids = [];
        foreach ($rows as $row) {
            $uuid = (string)($row['uuid'] ?? '');

            if ($uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    private static function resolve_order(): ?Order
    {
        $plugin = PluginManager::instance()->get('Monopay');

        return $plugin instanceof Monopay ? $plugin->get_order() : null;
    }

    /**
     * @return array{
     *     candidates: int,
     *     processed: int,
     *     updated: int,
     *     fulfilled: int,
     *     unchanged: int,
     *     errors: int
     * }
     */
    private static function empty_summary(): array
    {
        return [
            'candidates' => 0,
            'processed' => 0,
            'updated' => 0,
            'fulfilled' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];
    }
}
