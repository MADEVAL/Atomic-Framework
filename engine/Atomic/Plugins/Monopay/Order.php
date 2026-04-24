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
        $amount_minor = (int)round($amount * 100);

        try {
            $payment = Payment::create_preliminary(
                $amount,
                $options['ccy'] ?? Monopay::CURRENCY_DEFAULT
            );

            if (!$payment) {
                throw new \Exception('Failed to create preliminary payment record.');
            }
            
            $payment_uuid = $payment->get('uuid');

        } catch (\Throwable $e) {
            Log::error("Monopay: Failed to create payment record - " . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Failed to create payment record.',
            ];
        }
        
        $invoice_data = [
            'amount' => $amount_minor,
            'ccy' => $options['ccy'] ?? Monopay::CURRENCY_DEFAULT,
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

            $payment->set('invoice_id', $result['data']['invoiceId']);
            $payment->set('page_url', $result['data']['pageUrl']);
            $payment->save();

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
            $payment->erase();
            Log::error('Monopay: Invoice creation failed, preliminary payment deleted ' . json_encode([
                'payment_uuid' => $payment_uuid,
                'error' => $result['error'] ?? 'Unknown error'
            ]));
        }
        
        return $result;
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
                'created_date' => $status['createdDate'] ?? null,
                'modified_date' => $status['modifiedDate'] ?? null,
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
            'created_date' => isset($webhook_data['createdDate']) ? self::format_webhook_date($webhook_data['createdDate']) : null,
            'modified_date' => isset($webhook_data['modifiedDate']) ? self::format_webhook_date($webhook_data['modifiedDate']) : null,
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
