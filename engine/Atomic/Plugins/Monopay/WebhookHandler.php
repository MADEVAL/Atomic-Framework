<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;

class WebhookHandler
{
    public static function handle(): void
    {
        $plugin = monopay();
        
        if (!$plugin) {
            Log::error('Monopay: Plugin not loaded');
            http_response_code(500);
            echo json_encode(['error' => 'Plugin not loaded']);
            exit;
        }
        
        $x_sign = $_SERVER['HTTP_X_SIGN'] ?? '';
        
        if (empty($x_sign)) {
            Log::warning('Monopay: Webhook missing X-Sign header');
            http_response_code(400);
            echo json_encode(['error' => 'Missing signature']);
            exit;
        }
        
        $raw_body = file_get_contents('php://input');
        
        if ($raw_body === false || $raw_body === '') {
            Log::warning('Monopay: Webhook empty body');
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            exit;
        }
        
        $result = $plugin->handle_webhook($x_sign, $raw_body);
        
        if (!$result['ok']) {
            Log::error('Monopay: Webhook validation failed ' . json_encode(['error' => $result['error']]));
            http_response_code(400);
            echo json_encode(['error' => $result['error']]);
            exit;
        }
        
        $data = $result['data'];
        
        try {
            self::update_payment_from_webhook($data);
            
            $reference = $data['reference'] ?? '';
            $status = $data['status'] ?? 'unknown';
            
            if ($reference) {
                PaymentHistory::log_operation($reference, $status, $data);
                Log::info('Monopay: Payment history logged ' . json_encode([
                    'payment_uuid' => $reference,
                    'status' => $status
                ]));
            }
            
            switch ($data['status']) {
                case 'success':
                    self::handle_success_payment($data);
                    break;
                    
                case 'failure':
                    self::handle_failed_payment($data);
                    break;
                    
                case 'processing':
                case 'created':
                    self::handle_pending_payment($data);
                    break;
                    
                case 'reversed':
                    self::handle_reversed_payment($data);
                    break;
                    
                case 'hold':
                    self::handle_hold_payment($data);
                    break;

                default:
                    Log::info('Monopay: Unknown payment status ' . json_encode(['status' => $data['status']]));
            }
            
            http_response_code(200);
            echo json_encode(['success' => true]);
            
        } catch (\Throwable $e) {
            Log::error('Monopay: Webhook processing error - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }
    
    private static function update_payment_from_webhook(array $data): void
    {
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            Log::warning('Monopay: Missing reference in webhook data');
            return;
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
                return;
            }

            $payment->update_from_webhook($data);
            Log::info('Monopay: Payment record updated ' . json_encode([
                'payment_uuid' => $reference,
                'old_status' => $old_status,
                'new_status' => $new_status
            ]));
        }
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
            return;
        }

        Log::info('Monopay: Payment verified successfully ' . json_encode([
            'payment_uuid' => $reference
        ]));

        $seller = $payment->get('user');

        if ($seller->activate_tariff($payment->tariff)) {
            $payment->mark_fulfilled();

            Log::info('Monopay: Seller tariff activated ' . json_encode([
                'seller_id' => $seller->id,
                'tariff_uuid' => $payment->tariff->uuid
            ]));
        } else {
            Log::error('Monopay: Failed to activate seller tariff ' . json_encode([
                'seller_id' => $seller->id,
                'tariff_uuid' => $payment->tariff->uuid
            ]));
            throw new \RuntimeException('Failed to activate tariff for seller ' . $seller->id);
        }
    }
    
    private static function handle_failed_payment(array $data): void
    {
        Log::warning('Monopay: Payment failed ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'reason' => $data['failure_reason'] ?? 'Unknown'
        ]));
    }

    private static function handle_pending_payment(array $data): void
    {
        Log::info('Monopay: Payment pending ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'status' => $data['status'] ?? 'unknown'
        ]));
    }

    private static function handle_reversed_payment(array $data): void
    {
        Log::info('Monopay: Payment reversed ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null
        ]));
    }

    private static function handle_hold_payment(array $data): void
    {
        Log::info('Monopay: Payment on hold ' . json_encode([
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null
        ]));
    }

}
