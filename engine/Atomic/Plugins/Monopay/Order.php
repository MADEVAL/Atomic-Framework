<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\Monopay\Enums\MonopayHook;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Models\Payment;

class Order
{
    private Api $api;
    private array $order_data = [];
    
    public function __construct(Api $api)
    {
        $this->api = $api;
    }
    
    public function create(
        float $amount,
        string $destination,
        array $options = []
    ): array {
        if ($amount <= 0) {
            return [
                'ok' => false,
                'error' => 'Amount must be greater than zero.',
            ];
        }

        $amount_minor = (int)round($amount * 100);
        $currency = (int)($options['ccy'] ?? Monopay::CURRENCY_DEFAULT);

        // Idempotency: monobank has no server-side mechanism for it, so the
        // reference (our preliminary uuid) is the only dedup anchor. It is
        // reused only when the caller supplies a non-empty idempotency key;
        // without one, every create() is a fresh payment.
        $idempotency_key = $options['idempotency_key'] ?? null;
        if (!is_string($idempotency_key) || $idempotency_key === '') {
            $idempotency_key = null;
        }

        $resolved = $idempotency_key === null
            ? $this->create_unkeyed_payment($amount, $currency, $destination)
            : $this->resolve_keyed_payment($amount, $amount_minor, $currency, $destination, $idempotency_key);

        if (isset($resolved['response'])) {
            return $resolved['response'];
        }

        $payment = $resolved['payment'];
        $payment_uuid = $payment->get('uuid');

        $invoice_data = [
            'amount' => $amount_minor,
            'ccy' => $currency,
            'merchantPaymInfo' => [
                'reference' => $payment_uuid,
                'destination' => $destination,
            ],
        ];

        $invoice_data = Hook::instance()->apply_filters(
            MonopayHook::PAYMENT_INVOICE_DATA,
            $invoice_data,
            $payment,
            $options
        );
        
        if (isset($options['comment'])) {
            $invoice_data['merchantPaymInfo']['comment'] = $options['comment'];
        }
        
        if (isset($options['customerEmails']) && is_array($options['customerEmails'])) {
            $invoice_data['merchantPaymInfo']['customerEmails'] = $options['customerEmails'];
        }
        
        if (isset($options['redirectUrl'])) {
            $invoice_data['redirectUrl'] = $options['redirectUrl'];
        }
        
        if (isset($options['webHookUrl'])) {
            $invoice_data['webHookUrl'] = $options['webHookUrl'];
        }
        
        if (isset($options['validity'])) {
            $invoice_data['validity'] = (int)$options['validity'];
        }
        
        if (isset($options['paymentType'])) {
            $invoice_data['paymentType'] = $options['paymentType'];
        }
        
        if (isset($options['qrId'])) {
            $invoice_data['qrId'] = $options['qrId'];
        }
        
        if (isset($options['basketOrder']) && is_array($options['basketOrder'])) {
            $invoice_data['merchantPaymInfo']['basketOrder'] = $options['basketOrder'];
        }
        
        if (isset($options['discounts']) && is_array($options['discounts'])) {
            $invoice_data['merchantPaymInfo']['discounts'] = $options['discounts'];
        }
        
        if (isset($options['saveCardData'])) {
            $invoice_data['saveCardData'] = $options['saveCardData'];
        }
        
        if (isset($options['tipsEmployeeId'])) {
            $invoice_data['tipsEmployeeId'] = $options['tipsEmployeeId'];
        }
        
        if (isset($options['agentFeePercent'])) {
            $invoice_data['agentFeePercent'] = (float)$options['agentFeePercent'];
        }

        if (isset($options['code'])) {
            $invoice_data['code'] = $options['code'];
        }

        $result = $this->api->create_invoice($invoice_data);

        if ($result['ok']) {
            $this->order_data = [
                'invoiceId' => $result['data']['invoiceId'],
                'pageUrl' => $result['data']['pageUrl'],
                'reference' => $payment_uuid,
                'amount' => $amount,
                'amount_minor' => $amount_minor,
                'currency' => $invoice_data['ccy'],
                'destination' => $destination,
                'created_at' => time(),
            ];

            Log::info("Monopay: Invoice created " . json_encode([
                'invoiceId' => $this->order_data['invoiceId'],
                'reference' => $payment_uuid,
                'amount' => $amount
            ]));

            $payment->bind_invoice(
                (string)$result['data']['invoiceId'],
                (string)($result['data']['pageUrl'] ?? '')
            );

            $result['reference'] = $payment_uuid;

            Hook::instance()->do_action(
                MonopayHook::PAYMENT_CREATED,
                $payment,
                $options,
                $result['data'],
                $invoice_data
            );

        } else {
            Hook::instance()->do_action(
                MonopayHook::PAYMENT_CREATE_FAILED,
                $payment,
                $options,
                $result,
                $invoice_data
            );

            $result['payment_uuid'] = $payment_uuid;

            if (self::is_ambiguous_failure($result)) {
                // Ambiguous outcome: monobank may have created the invoice
                // even though we never saw the response. Keep the row and
                // its reference — a retry resumes it instead of creating a
                // second, independently payable invoice.
                $payment->mark_create_pending();
                Log::warning('Monopay: Invoice creation outcome ambiguous, payment kept for resume ' . json_encode([
                    'payment_uuid' => $payment_uuid,
                    'error' => $result['error'] ?? 'Unknown error'
                ]));
            } else {
                // Monobank rejected the request definitively — no invoice
                // can exist, the preliminary row is dead weight.
                $payment->erase();
                Log::error('Monopay: Invoice creation failed, preliminary payment deleted ' . json_encode([
                    'payment_uuid' => $payment_uuid,
                    'error' => $result['error'] ?? 'Unknown error'
                ]));
            }
        }

        return $result;
    }

    /** How many times a keyed caller re-reads the row after losing an insert/claim race. */
    private const MAX_KEY_RESOLVE_ATTEMPTS = 5;

    /**
     * @return array{payment: Payment}|array{response: array<string, mixed>}
     */
    private function create_unkeyed_payment(float $amount, int $currency, string $destination): array
    {
        try {
            $payment = Payment::create_preliminary($amount, $currency, $destination);

            if (!$payment) {
                throw new \Exception('Failed to create preliminary payment record.');
            }
        } catch (\Throwable $e) {
            Log::error("Monopay: Failed to create payment record - " . $e->getMessage());

            return ['response' => ['ok' => false, 'error' => 'Failed to create payment record.']];
        }

        return ['payment' => $payment];
    }

    /**
     * Resolves which payment row a keyed create() may use.
     *
     * The UNIQUE index on idempotency_key decides the first-insert race:
     * exactly one INSERT wins, losers catch the duplicate-key error and load
     * the winner's row. From there the row is either replayed (invoice
     * already bound), rejected (amount/currency conflict), released (the
     * bound invoice already ended in a terminal non-paid status, so it can
     * never be paid again), claimed for a resume (no invoice yet, no live
     * claim) or reported as "in progress" (another caller currently owns
     * the claim).
     *
     * @return array{payment: Payment}|array{response: array<string, mixed>}
     */
    private function resolve_keyed_payment(
        float $amount,
        int $amount_minor,
        int $currency,
        string $destination,
        string $idempotency_key
    ): array {
        for ($attempt = 0; $attempt < self::MAX_KEY_RESOLVE_ATTEMPTS; $attempt++) {
            $payment = Payment::get_by_idempotency_key($idempotency_key);

            if ($payment) {
                $response = self::key_conflict_response($payment, $amount_minor, $currency);
                if ($response !== null) {
                    return ['response' => $response];
                }

                if ($payment->is_terminal_non_paid()) {
                    // The key is bound to an invoice that can never be paid
                    // (card declined, refunded, expired). Release it and
                    // retry the normal path so this create mints a fresh,
                    // payable payment; replays would strand the customer.
                    $payment->release_idempotency_key();
                    continue;
                }

                $response = self::invoice_replay_response($payment);
                if ($response !== null) {
                    return ['response' => $response];
                }

                if ($payment->claim_create()) {
                    return ['payment' => $payment];
                }

                // Lost the claim race: the owner may have bound the invoice
                // in the meantime. Re-read once and replay if so.
                $payment = Payment::get_by_idempotency_key($idempotency_key);

                if ($payment) {
                    $response = self::key_conflict_response($payment, $amount_minor, $currency);
                    if ($response !== null) {
                        return ['response' => $response];
                    }

                    if ($payment->is_terminal_non_paid()) {
                        $payment->release_idempotency_key();
                        continue;
                    }

                    $response = self::invoice_replay_response($payment);
                    if ($response !== null) {
                        return ['response' => $response];
                    }
                }

                return ['response' => self::creation_in_progress_response()];
            }

            try {
                $payment = Payment::create_preliminary(
                    $amount,
                    $currency,
                    $destination,
                    $idempotency_key
                );

                if ($payment) {
                    return ['payment' => $payment];
                }
            } catch (\Throwable $e) {
                // Most likely the UNIQUE index rejected us because a racer
                // inserted first. Fall through to load the winner's row.
                Log::info('Monopay: keyed preliminary insert lost a race ' . json_encode([
                    'idempotency_key' => $idempotency_key,
                    'error' => $e->getMessage(),
                ]));
            }

            if (!Payment::get_by_idempotency_key($idempotency_key)) {
                return ['response' => ['ok' => false, 'error' => 'Failed to create payment record.']];
            }
        }

        return ['response' => self::creation_in_progress_response()];
    }

    /** @return array<string, mixed>|null */
    private static function key_conflict_response(Payment $payment, int $amount_minor, int $currency): ?array
    {
        $stored_minor = (int)round((float)$payment->get('amount') * 100);
        $stored_currency = (int)$payment->get('currency');

        if ($stored_minor === $amount_minor && $stored_currency === $currency) {
            return null;
        }

        return [
            'ok' => false,
            'error' => 'The idempotency key was already used with a different amount or currency.',
        ];
    }

    /** @return array<string, mixed>|null */
    private static function invoice_replay_response(Payment $payment): ?array
    {
        $invoice_id = $payment->get('invoice_id');

        if ($invoice_id === null || $invoice_id === '') {
            return null;
        }

        return [
            'ok' => true,
            'data' => [
                'invoiceId' => (string)$invoice_id,
                'pageUrl' => (string)($payment->get('page_url') ?? ''),
            ],
            'error' => null,
            'reference' => (string)$payment->get('uuid'),
        ];
    }

    /** @return array<string, mixed> */
    private static function creation_in_progress_response(): array
    {
        return [
            'ok' => false,
            'error' => 'Payment creation for this idempotency key is already in progress. Retry shortly.',
        ];
    }

    /**
     * True unless monobank definitively rejected the request at the HTTP
     * level.
     *
     * Only a 4xx status proves the request was refused without side
     * effects, so only that may erase the preliminary row. Everything else
     * — a missing status (Api::request()'s Throwable catch), status 0, a
     * 5xx, or a 2xx whose body could not be parsed — leaves the outcome
     * ambiguous: monobank may already hold the invoice, so the row and its
     * reference must be kept for a resume.
     */
    private static function is_ambiguous_failure(array $result): bool
    {
        $status = $result['status'] ?? null;

        return !(is_int($status) && $status >= 400 && $status < 500);
    }
    
    public function get_status(string $invoice_id): array
    {
        $result = $this->api->get_invoice_status($invoice_id);
        
        if ($result['ok']) {
            $status = $result['data'];
            
            return [
                'ok' => true,
                'status' => $status['status'],
                'invoice_id' => $status['invoiceId'],
                'amount' => $status['amount'] / 100,
                'final_amount' => isset($status['finalAmount']) ? $status['finalAmount'] / 100 : null,
                'currency' => $status['ccy'],
                'reference' => $status['reference'] ?? null,
                'destination' => $status['destination'] ?? null,
                'failure_reason' => $status['failureReason'] ?? null,
                'error_code' => $status['errCode'] ?? null,
                'created_at' => isset($status['createdDate']) ? self::format_webhook_date($status['createdDate']) : null,
                'updated_at' => isset($status['modifiedDate']) ? self::format_webhook_date($status['modifiedDate']) : null,
                'payment_info' => $status['paymentInfo'] ?? null,
                'cancel_list' => $status['cancelList'] ?? [],
                'wallet_data' => $status['walletData'] ?? null,
                'tips_info' => $status['tipsInfo'] ?? null,
                'raw' => $status
            ];
        }
        
        return $result;
    }
    
    public function is_paid(string $invoice_id, ?array $status_result = null): bool
    {
        $result = $status_result ?? $this->get_status($invoice_id);
        return $result['ok'] && $result['status'] === PaymentStatus::SUCCESS->value;
    }

    public function is_pending(string $invoice_id, ?array $status_result = null): bool
    {
        $result = $status_result ?? $this->get_status($invoice_id);

        if (!$result['ok']) {
            return false;
        }

        return in_array($result['status'], PaymentStatus::pending_values(), true);
    }

    public function is_failed(string $invoice_id, ?array $status_result = null): bool
    {
        $result = $status_result ?? $this->get_status($invoice_id);
        return $result['ok'] && $result['status'] === PaymentStatus::FAILURE->value;
    }

    public function is_hold(string $invoice_id, ?array $status_result = null): bool
    {
        $result = $status_result ?? $this->get_status($invoice_id);
        return $result['ok'] && $result['status'] === PaymentStatus::HOLD->value;
    }
    
    public function cancel(string $invoice_id, ?float $amount = null, array $options = []): array
    {
        $cancel_data = [];
        
        if ($amount !== null) {
            $cancel_data['amount'] = (int)round($amount * 100);
        }
        
        if (isset($options['extRef'])) {
            $cancel_data['extRef'] = $options['extRef'];
        }
        
        if (isset($options['items']) && is_array($options['items'])) {
            $cancel_data['items'] = $options['items'];
        }
        
        $result = $this->api->cancel_invoice($invoice_id, $cancel_data);
        
        if ($result['ok']) {
            Log::info('Monopay: Invoice cancelled ' . json_encode([
                'invoiceId' => $invoice_id,
                'status' => $result['data']['status'] ?? null,
            ]));
        }
        
        return $result;
    }
    
    public function invalidate(string $invoice_id): array
    {
        $result = $this->api->remove_invoice($invoice_id);
        
        if ($result['ok']) {
            Log::info("Monopay: Invoice invalidated " . json_encode(['invoiceId' => $invoice_id]));
        }
        
        return $result;
    }
    
    public function finalize_hold(string $invoice_id, ?float $amount = null, array $items = []): array
    {
        $amount_minor = $amount !== null ? (int)round($amount * 100) : null;
        
        $result = $this->api->finalize_hold($invoice_id, $amount_minor, $items);
        
        if ($result['ok']) {
            Log::info("Monopay: Hold finalized " . json_encode([
                'invoiceId' => $invoice_id,
                'amount' => $amount,
                'status' => $result['data']['status'] ?? 'unknown'
            ]));
        }
        
        return $result;
    }
    
    public function parse_webhook(array $webhook_data): array
    {
        return [
            'invoice_id' => $webhook_data['invoiceId'] ?? null,
            'status' => $webhook_data['status'] ?? null,
            'amount' => isset($webhook_data['amount']) ? $webhook_data['amount'] / 100 : null,
            'final_amount' => isset($webhook_data['finalAmount']) ? $webhook_data['finalAmount'] / 100 : null,
            'currency' => $webhook_data['ccy'] ?? null,
            'reference' => $webhook_data['reference'] ?? null,
            'destination' => $webhook_data['destination'] ?? null,
            'failure_reason' => $webhook_data['failureReason'] ?? null,
            'error_code' => $webhook_data['errCode'] ?? null,
            'created_at' => isset($webhook_data['createdDate']) ? self::format_webhook_date($webhook_data['createdDate']) : null,
            'updated_at' => isset($webhook_data['modifiedDate']) ? self::format_webhook_date($webhook_data['modifiedDate']) : null,
            'payment_info' => $webhook_data['paymentInfo'] ?? null,
            'cancel_list' => $webhook_data['cancelList'] ?? [],
            'wallet_data' => $webhook_data['walletData'] ?? null,
            'tips_info' => $webhook_data['tipsInfo'] ?? null,
        ];
    }

    private static function format_webhook_date($date): ?string
    {
        if (!$date) return null;
        try {
            $dt = new \DateTime($date);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning('Monopay: Invalid webhook date format ' . json_encode(['date' => $date, 'error' => $e->getMessage()]));
            return null;
        }
    }
    
    public function get_order_data(): array
    {
        return $this->order_data;
    }
}
