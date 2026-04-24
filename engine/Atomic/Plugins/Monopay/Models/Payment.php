<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay\Models;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\App\Model;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Enums\Rule;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Plugins\Monopay\Enums\PaymentStatus;
use Engine\Atomic\Plugins\Monopay\Monopay;

class Payment extends Model
{
    protected $table = 'payments';
    protected $db = 'DB';

    protected $fieldConf = [
        'uuid' => [
            'type' => Schema::DT_VARCHAR128,
            'unique' => true,
            'rules' => [Rule::UUID_V4]
        ],
        'invoice_id' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
            'unique' => true,
        ],
        'status' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'required' => true,
            'default' => 'created',
        ],
        'amount' => [
            'type' => Schema::DT_DECIMAL,
            'nullable' => false,
            'required' => true,
            'rules' => [Rule::NUM_MIN],
            'min' => 0,
        ],
        'final_amount' => [
            'type' => Schema::DT_DECIMAL,
            'nullable' => true,
        ],
        'currency' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'required' => true,
            'default' => Monopay::CURRENCY_UAH,
        ],
        'destination' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'failure_reason' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'error_code' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
        ],
        'created_date' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'modified_date' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'payment_info' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'cancel_list' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'wallet_data' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'tips_info' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'page_url' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'webhook_data' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'fulfilled_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
    ];

    public function get_by_uuid(string $uuid): self|false
    {
        return $this->findone(['uuid = ?', $uuid]);
    }

    public function get_by_status(string $status, array $options = []): array
    {
        return $this->afind(['status = ?', $status], $options) ?? [];
    }

    public function get_by_field(string $field, mixed $value, array $options = []): array
    {
        if (!array_key_exists($field, $this->getFieldConfiguration())) {
            return [];
        }

        return $this->afind([$field . ' = ?', $value], $options) ?? [];
    }

    public function is_successful(): bool
    {
        return PaymentStatus::is_successful_status((string)$this->get('status'));
    }

    public function is_pending(): bool
    {
        return PaymentStatus::is_pending_status((string)$this->get('status'));
    }

    public function is_failed(): bool
    {
        return PaymentStatus::is_failed_status((string)$this->get('status'));
    }

    public function is_hold(): bool
    {
        return PaymentStatus::is_hold_status((string)$this->get('status'));
    }

    public function is_fulfilled(): bool
    {
        return $this->get('fulfilled_at') !== null;
    }

    public function mark_fulfilled(): bool
    {
        $this->set('fulfilled_at', date('Y-m-d H:i:s'));
        return (bool)$this->save();
    }

    public static function is_valid_status_transition(string $from, string $to): bool
    {
        return PaymentStatus::is_valid_status_transition($from, $to);
    }

    public function update_from_webhook(array $webhook_data): bool
    {
        $new_status = $webhook_data['status'] ?? $this->get('status');
        $old_status = $this->get('status');

        if (self::is_valid_status_transition($old_status, $new_status)) {
            $this->set('status', $new_status);
        }
        
        if (isset($webhook_data['invoice_id']) && !$this->get('invoice_id')) {
            $this->set('invoice_id', $webhook_data['invoice_id']);
        }

        if (isset($webhook_data['final_amount'])) {
            $this->set('final_amount', $webhook_data['final_amount']);
        }
        
        if (isset($webhook_data['failure_reason'])) {
            $this->set('failure_reason', $webhook_data['failure_reason']);
        }
        
        if (isset($webhook_data['error_code'])) {
            $this->set('error_code', $webhook_data['error_code']);
        }
        
        if (isset($webhook_data['modified_date'])) {
            $this->set('modified_date', $webhook_data['modified_date']);
        }
        
        if (isset($webhook_data['payment_info'])) {
            $this->set('payment_info', json_encode($webhook_data['payment_info']));
        }
        
        if (isset($webhook_data['cancel_list'])) {
            $this->set('cancel_list', json_encode($webhook_data['cancel_list']));
        }
        
        if (isset($webhook_data['wallet_data'])) {
            $this->set('wallet_data', json_encode($webhook_data['wallet_data']));
        }
        
        if (isset($webhook_data['tips_info'])) {
            $this->set('tips_info', json_encode($webhook_data['tips_info']));
        }
        
        $this->set('webhook_data', json_encode($webhook_data));
        
        return (bool)$this->save();
    }

    public static function create_preliminary(float $amount, $currency): ?self
    {
        $model = new self();
        
        $data = [
            'uuid' => ID::uuid_v4(),
            'status' => PaymentStatus::CREATED->value,
            'amount' => $amount,
            'currency' => $currency,
            'created_date' => date('Y-m-d H:i:s'),
        ];
        
        foreach ($data as $key => $value) {
            $model->set($key, $value);
        }
        
        if ($model->save()) {
            return $model;
        }
        
        return null;
    }
    
    public function get_formatted_amount(): string
    {
        $amount = $this->get('amount');
        $currency = $this->get('currency');
        
        $symbol = Monopay::currency_symbol_from_code((int)$currency);
        
        return number_format((float)$amount, 2, '.', ' ') . ' ' . $symbol;
    }

    public function get_formatted_final_amount(): ?string
    {
        $amount = $this->get('final_amount');
        if ($amount === null) {
            return null;
        }
        
        $currency = $this->get('currency');
        
        $symbol = Monopay::currency_symbol_from_code((int)$currency);
        
        return number_format((float)$amount, 2, '.', ' ') . ' ' . $symbol;
    }

    public function get_status_label(): string
    {
        $i18n = I18n::instance();
        $status = (string)$this->get('status');

        $plugin_key = 'plugins.monopay.status.' . $status;
        $val = $i18n->t($plugin_key);
        if ($val !== $plugin_key) {
            return $val;
        }

        $enum = PaymentStatus::tryFrom($status);
        $key = $enum ? $enum->label_key() : 'payment.status.unknown';
        return $i18n->t($key);
    }

    public function get_status_badge_class(): string
    {
        $enum = PaymentStatus::tryFrom((string)$this->get('status'));
        return $enum ? $enum->badge_class() : 'secondary';
    }

    public function verify_with_monopay(): array
    {
        $atomic = App::instance();

        if (!$atomic->get('PLUGIN.Monopay.booted')) {
            return [
                'ok' => false,
                'error' => 'Monopay plugin not loaded',
                'verified' => false
            ];
        }
        
        $order = monopay_get_order();
        
        if (!$order) {
            return [
                'ok' => false,
                'error' => 'Monopay not configured',
                'verified' => false
            ];
        }
        
        $invoice_id = $this->get('invoice_id');
        
        if (!$invoice_id) {
            return [
                'ok' => false,
                'error' => 'No invoice_id in payment record',
                'verified' => false
            ];
        }
        
        $result = $order->get_status($invoice_id);
        
        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Failed to verify with Monopay',
                'verified' => false
            ];
        }
        
        $stored_uuid = $this->get('uuid');
        $monopay_reference = $result['reference'] ?? null;

        $stored_amount = (float)$this->get('amount');
        $monopay_amount = $result['amount'] ?? 0;

        $monopay_status = $result['status'] ?? '';

        $verified = true;
        $errors = [];

        if ($stored_uuid !== $monopay_reference) {
            $verified = false;
            $errors[] = 'UUID/Reference mismatch';
        }

        if (abs($stored_amount - $monopay_amount) > 0.01) {
            $verified = false;
            $errors[] = 'Amount mismatch';
        }

        if ($monopay_status !== PaymentStatus::SUCCESS->value) {
            $verified = false;
            $errors[] = 'Monopay status is not success (got: ' . $monopay_status . ')';
        }
        
        return [
            'ok' => true,
            'verified' => $verified,
            'errors' => $errors,
            'monopay_data' => $result
        ];
    }
}
