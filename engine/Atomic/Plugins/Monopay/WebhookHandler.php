<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;

class WebhookHandler
{
    public static function handle(): void
    {
        $plugin = monopay();
        $response = Response::instance();
        
        if (!$plugin) {
            Log::error('Monopay: Plugin not loaded');
            $response->send_json_error('Plugin not loaded', 500);
        }
        
        $x_sign = (string)(App::atomic()->get('HEADERS.X-Sign') ?? '');
        
        if ($x_sign === '') {
            Log::warning('Monopay: Webhook missing X-Sign header');
            $response->send_json_error('Missing signature', 400);
        }
        
        $raw_body = Request::instance()->raw_body();
        
        if ($raw_body === '') {
            Log::warning('Monopay: Webhook empty body');
            $response->send_json_error('Empty body', 400);
        }
        
        $result = $plugin->handle_webhook($x_sign, $raw_body);
        
        if (!$result['ok']) {
            Log::error('Monopay: Webhook validation failed ' . json_encode(['error' => $result['error']]));
            $response->send_json_error($result['error'] ?? 'Webhook validation failed', 400);
        }
        
        $data = $result['data'];
        
        try {
            self::apply_verified_event($data);

            $response->send_json_success();

        } catch (\Throwable $e) {
            Log::error('Monopay: Webhook processing error - ' . $e->getMessage() . $e->getTraceAsString());
            $response->send_json_error('Processing failed', 500);
        }
    }

    /**
     * Applies one verified status event (a webhook delivery or a
     * reconciler poll) through the single transition path: the CAS in
     * Payment::update_from_webhook() elects one winner, only that winner
     * logs history and fires PAYMENT_UPDATED / the leaf status hook, and
     * success fulfilment is claimed atomically before PAYMENT_VERIFIED /
     * PAYMENT_SUCCESS. Both callers therefore share race semantics: a
     * webhook and the reconciler racing on the same payment produce one
     * transition and one hook dispatch.
     */
    public static function apply_verified_event(array $data): void
    {
        $payment = self::update_payment_from_webhook($data);

        $reference = $data['reference'] ?? '';
        $status = $data['status'] ?? 'unknown';

        // Audit trail records only accepted transitions: duplicate
        // (same->same) and stale deliveries return no payment record.
        if ($reference && $payment) {
            $payment_id = (int)$payment->get('id');
            PaymentHistory::log_operation($payment_id, $reference, $status, $data);
            Log::info('Monopay: Payment history logged ' . json_encode([
                'payment_uuid' => $reference,
                'payment_id' => $payment_id,
                'status' => $status
            ]));
        } elseif ($reference) {
            Log::warning('Monopay: Webhook delivery produced no payment state change ' . json_encode([
                'payment_uuid' => $reference,
                'status' => $status
            ]));
        }

        // Status-specific handling runs for accepted transitions, and
        // additionally for success re-deliveries whose fulfillment is
        // still pending (e.g. the first delivery 500-ed during
        // re-verification and monobank is retrying). handle_success_
        // payment() re-resolves the payment and guards on fulfilled_at,
        // so duplicates stay side-effect free.
        if ($payment || ($reference && $status === PaymentStatus::SUCCESS->value)) {
            switch ($data['status']) {
                case PaymentStatus::SUCCESS->value:
                    self::handle_success_payment($data);
                    break;

                case PaymentStatus::FAILURE->value:
                    self::handle_failed_payment($data);
                    break;

                case PaymentStatus::PROCESSING->value:
                case PaymentStatus::CREATED->value:
                    self::handle_pending_payment($data);
                    break;

                case PaymentStatus::REVERSED->value:
                    self::handle_reversed_payment($data);
                    break;

                case PaymentStatus::HOLD->value:
                    self::handle_hold_payment($data);
                    break;

                default:
                    Log::info('Monopay: Unknown payment status ' . json_encode(['status' => $data['status']]));
            }
        }
    }
    
    /**
     * Applies a verified webhook to the payment record.
     *
     * Returns the payment ONLY when the delivery carries a state change
     * (an accepted status transition). Null means "nothing to record":
     *   - missing reference or unknown payment,
     *   - a duplicate delivery (same status re-delivered — monobank
     *     retries up to 3 times until a 200, so this is guaranteed),
     *   - a stale delivery (an out-of-order status that would downgrade).
     * Callers must not log history or fire hooks for null results.
     */
    private static function update_payment_from_webhook(array $data): ?Payment
    {
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            Log::warning('Monopay: Missing reference in webhook data');
            return null;
        }

        $payment_model = new Payment();
        $payment = $payment_model->get_by_uuid($reference);

        if (!$payment) {
            return null;
        }

        $old_status = $payment->get('status');
        $new_status = $data['status'] ?? $old_status;

        if ($new_status === $old_status) {
            // Duplicate delivery: the payment already carries this status.
            // Re-saving/re-firing would double every downstream effect
            // (history rows, update hooks, ERP sync) per monobank retry.
            Log::info('Monopay: Duplicate webhook ignored, payment already in status ' . json_encode([
                'payment_uuid' => $reference,
                'status' => $old_status
            ]));
            return null;
        }

        if (!Payment::is_valid_status_transition($old_status, $new_status)) {
            Log::warning('Monopay: Ignored invalid status transition ' . json_encode([
                'payment_uuid' => $reference,
                'from' => $old_status,
                'to' => $new_status
            ]));
            return null;
        }

        // A concurrent duplicate delivery may have committed the very same
        // transition between our SELECT and this UPDATE. The conditional
        // UPDATE (WHERE status = old) elects exactly one winner; losers
        // must behave exactly like the duplicate path above.
        if (!$payment->update_from_webhook($data, $old_status)) {
            Log::info('Monopay: Webhook transition lost to a concurrent delivery ' . json_encode([
                'payment_uuid' => $reference,
                'old_status' => $old_status,
                'new_status' => $new_status
            ]));
            return null;
        }

        // update_from_webhook() may have replayed the transition from a
        // status committed by a parallel delivery; report that fresh origin
        // rather than the stale one read at the start of this delivery.
        $from_status = $payment->transitioned_from() ?? $old_status;

        Log::info('Monopay: Payment record updated ' . json_encode([
            'payment_uuid' => $reference,
            'old_status' => $from_status,
            'new_status' => $new_status
        ]));

        Hook::instance()->do_action(MonopayHook::PAYMENT_UPDATED, $payment, $data, $from_status, $new_status);

        // Emit ONLY the leaf tag. Event::emit() is hierarchical: a leaf
        // emission fires the leaf listeners exactly and the generic
        // monopay.payment.status listeners exactly once via ancestor
        // traversal. Emitting the generic tag as well would broadcast to
        // the child tags and make every leaf listener run twice.
        Hook::instance()->do_action(MonopayHook::status($new_status), $payment, $data, $new_status, $from_status);

        return $payment;
    }
    
    private static function handle_success_payment(array $data): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            Log::warning('Monopay: No reference for successful payment ' . json_encode(['data' => $data]));
            return;
        }

        $payment_model = new Payment();
        $payment = $payment_model->get_by_uuid($reference);

        if (!$payment) {
            Log::warning('Monopay: Payment record not found for success webhook ' . json_encode(['reference' => $reference]));
            return;
        }

        if ($payment->is_fulfilled()) {
            Log::info('Monopay: Duplicate success webhook ignored (already fulfilled) ' . json_encode([
                'payment_uuid' => $reference,
            ]));
            return;
        }

        $amount = $data['final_amount'] ?? $data['amount'];

        Log::info('Monopay: Payment successful ' . json_encode([
            'payment_uuid' => $reference,
            'invoice_id' => $data['invoice_id'] ?? null,
            'amount' => $amount
        ]));

        $verification = $payment->verify_with_monopay();

        if (!$verification['ok']) {
            throw new \RuntimeException('Monopay re-verification API call failed: ' . ($verification['error'] ?? 'unknown'));
        }

        if (!$verification['verified']) {
            Log::error('Monopay: Payment verification failed ' . json_encode([
                'payment_uuid' => $reference,
                'errors' => $verification['errors'] ?? []
            ]));
            Hook::instance()->do_action(MonopayHook::PAYMENT_VERIFICATION_FAILED, $payment, $data, $verification);
            return;
        }

        $should_mark_fulfilled = Hook::instance()->apply_filters(
            MonopayHook::PAYMENT_SHOULD_MARK_FULFILLED,
            true,
            $payment,
            $data,
            $verification
        );

        if ($should_mark_fulfilled) {
            // Claim BEFORE announcing: is_fulfilled() above is only a fast
            // path — two concurrent deliveries can both pass it. The
            // conditional claim in mark_fulfilled() elects exactly one
            // winner, so the success hooks below fire for exactly one
            // delivery even under concurrent monobank retries.
            if (!$payment->mark_fulfilled()) {
                Log::info('Monopay: Fulfillment lost the claim race, skipping success hooks ' . json_encode([
                    'payment_uuid' => $reference
                ]));
                return;
            }
        }

        Log::info('Monopay: Payment verified successfully ' . json_encode([
            'payment_uuid' => $reference
        ]));

        Hook::instance()->do_action(MonopayHook::PAYMENT_VERIFIED, $payment, $data, $verification);

        Hook::instance()->do_action(MonopayHook::PAYMENT_SUCCESS, $payment, $data, $verification);

        if ($should_mark_fulfilled) {
            Log::info('Monopay: Payment fulfilled ' . json_encode([
                'payment_uuid' => $reference
            ]));
        }
    }
    
    private static function handle_failed_payment(array $data): void
    {
        $payment = self::find_payment_by_reference($data['reference'] ?? null);

        Log::warning('Monopay: Payment failed ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'reason' => $data['failure_reason'] ?? 'Unknown'
        ]));

        if ($payment) {
            Hook::instance()->do_action(MonopayHook::PAYMENT_FAILED, $payment, $data);
        }
    }

    private static function handle_pending_payment(array $data): void
    {
        $payment = self::find_payment_by_reference($data['reference'] ?? null);

        Log::info('Monopay: Payment pending ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'status' => $data['status'] ?? 'unknown'
        ]));

        if ($payment) {
            Hook::instance()->do_action(MonopayHook::PAYMENT_PENDING, $payment, $data);
        }
    }

    private static function handle_reversed_payment(array $data): void
    {
        $payment = self::find_payment_by_reference($data['reference'] ?? null);

        Log::info('Monopay: Payment reversed ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null
        ]));

        if ($payment) {
            Hook::instance()->do_action(MonopayHook::PAYMENT_REVERSED, $payment, $data);
        }
    }

    private static function handle_hold_payment(array $data): void
    {
        $payment = self::find_payment_by_reference($data['reference'] ?? null);

        Log::info('Monopay: Payment on hold ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null
        ]));

        if ($payment) {
            Hook::instance()->do_action(MonopayHook::PAYMENT_HOLD, $payment, $data);
        }
    }

    private static function find_payment_by_reference(?string $reference): ?Payment
    {
        if (empty($reference)) {
            return null;
        }

        $payment_model = new Payment();
        $payment = $payment_model->get_by_uuid($reference);

        return $payment ?: null;
    }

}
