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
            $payment = self::update_payment_from_webhook($data);
            
            $reference = $data['reference'] ?? '';
            $status = $data['status'] ?? 'unknown';
            
            if ($reference && $payment) {
                $payment_id = (int)$payment->get('id');
                PaymentHistory::log_operation($payment_id, $reference, $status, $data);
                Log::info('Monopay: Payment history logged ' . json_encode([
                    'payment_uuid' => $reference,
                    'payment_id' => $payment_id,
                    'status' => $status
                ]));
            } elseif ($reference) {
                Log::warning('Monopay: Skipped payment history logging because payment was not found ' . json_encode([
                    'payment_uuid' => $reference,
                    'status' => $status
                ]));
            }
            
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
            
            $response->send_json_success();
            
        } catch (\Throwable $e) {
            Log::error('Monopay: Webhook processing error - ' . $e->getMessage() . $e->getTraceAsString());
            $response->send_json_error('Processing failed', 500);
        }
    }
    
    private static function update_payment_from_webhook(array $data): ?Payment
    {
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            Log::warning('Monopay: Missing reference in webhook data');
            return null;
        }

        $payment_model = new Payment();
        $payment = $payment_model->get_by_uuid($reference);

        if ($payment) {
            $old_status = $payment->get('status');
            $new_status = $data['status'] ?? $old_status;

            if (!Payment::is_valid_status_transition($old_status, $new_status)) {
                Log::warning('Monopay: Ignored invalid status transition ' . json_encode([
                    'payment_uuid' => $reference,
                    'from' => $old_status,
                    'to' => $new_status
                ]));
                return $payment;
            }

            $payment->update_from_webhook($data);
            Log::info('Monopay: Payment record updated ' . json_encode([
                'payment_uuid' => $reference,
                'old_status' => $old_status,
                'new_status' => $new_status
            ]));

            Hook::instance()->do_action(MonopayHook::PAYMENT_UPDATED, $payment, $data, $old_status, $new_status);
            Hook::instance()->do_action(MonopayHook::PAYMENT_STATUS, $payment, $data, $new_status, $old_status);
            Hook::instance()->do_action(MonopayHook::status($new_status), $payment, $data, $old_status);

            return $payment;
        }

        return null;
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

        Log::info('Monopay: Payment verified successfully ' . json_encode([
            'payment_uuid' => $reference
        ]));

        Hook::instance()->do_action(MonopayHook::PAYMENT_VERIFIED, $payment, $data, $verification);

        Hook::instance()->do_action(MonopayHook::PAYMENT_SUCCESS, $payment, $data, $verification);

        $should_mark_fulfilled = Hook::instance()->apply_filters(
            MonopayHook::PAYMENT_SHOULD_MARK_FULFILLED,
            true,
            $payment,
            $data,
            $verification
        );

        if ($should_mark_fulfilled) {
            $payment->mark_fulfilled();
            Log::info('Monopay: Payment fulfilled', ['payment_uuid' => $reference]);
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
