<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay\Models;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\App\Model;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Enums\Rule;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Plugins\Monopay;

use function Engine\Atomic\Plugins\monopay_get_order;

class Payment extends Model
{
    const STATUS_CREATED = 'created';
    const STATUS_PROCESSING = 'processing';
    const STATUS_HOLD = 'hold';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';
    const STATUS_REVERSED = 'reversed';
    const STATUS_EXPIRED = 'expired';

    protected $table = 'payments';
    protected $db = 'DB';

    protected static function tariffModel(): ?string { return null; }
    protected static function storeModel(): ?string { return null; }
    protected static function userModel(): ?string { return null; }

    public function __construct()
    {
        $tariffModel = static::tariffModel();
        if ($tariffModel !== null) {
            $this->fieldConf['tariff']['relType'] = 'belongs-to-one';
            $this->fieldConf['tariff']['belongs-to-one'] = $tariffModel;
        }

        $storeModel = static::storeModel();
        if ($storeModel !== null) {
            $this->fieldConf['store']['relType'] = 'belongs-to-one';
            $this->fieldConf['store']['belongs-to-one'] = $storeModel;
        }

        $userModel = static::userModel();
        if ($userModel !== null) {
            $this->fieldConf['user']['relType'] = 'belongs-to-one';
            $this->fieldConf['user']['belongs-to-one'] = $userModel;
        }

        parent::__construct();
    }

    protected $fieldConf = [
        'tariff' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
        ],
        'store' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
        ],
        'user' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
        ],
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

    public function get_by_store(int $store_id, array $options = []): array
    {
        return $this->afind(['store = ?', $store_id], $options) ?? [];
    }

    public function get_by_user(int $user_id, array $options = []): array
    {
        return $this->afind(['user = ?', $user_id], $options) ?? [];
    }

    public function get_by_tariff(int $tariff_id, array $options = []): array
    {
        return $this->afind(['tariff = ?', $tariff_id], $options) ?? [];
    }

    public function is_successful(): bool
    {
        return $this->get('status') === self::STATUS_SUCCESS;
    }

    public function is_pending(): bool
    {
        $status = $this->get('status');
        return in_array($status, [self::STATUS_CREATED, self::STATUS_PROCESSING], true);
    }

    public function is_failed(): bool
    {
        return $this->get('status') === self::STATUS_FAILURE;
    }

    public function is_hold(): bool
    {
        return $this->get('status') === self::STATUS_HOLD;
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

    private const STATUS_WEIGHT = [
        self::STATUS_CREATED    => 0,
        self::STATUS_PROCESSING => 1,
        self::STATUS_HOLD       => 2,
        self::STATUS_SUCCESS    => 3,
        self::STATUS_FAILURE    => 3,
        self::STATUS_REVERSED   => 4,
        self::STATUS_EXPIRED    => 3,
    ];

    public static function is_valid_status_transition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $from_weight = self::STATUS_WEIGHT[$from] ?? -1;
        $to_weight = self::STATUS_WEIGHT[$to] ?? -1;

        if ($to_weight < $from_weight) {
            return false;
        }

        if ($from === self::STATUS_SUCCESS && $to !== self::STATUS_REVERSED) {
            return false;
        }

        if (in_array($from, [self::STATUS_FAILURE, self::STATUS_EXPIRED], true)) {
            return false;
        }

        return true;
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

    public static function create_preliminary(float $amount, $currency, ?int $tariff_id = null, ?int $store_id = null, ?int $user_id = null): ?self
    {
        $model = new self();
        
        $data = [
            'uuid' => ID::uuid_v4(),
            'status' => self::STATUS_CREATED,
            'amount' => $amount,
            'currency' => $currency,
            'created_date' => date('Y-m-d H:i:s'),
        ];

        if ($tariff_id) {
            $data['tariff'] = $tariff_id;
        }
        
        if ($store_id) {
            $data['store'] = $store_id;
        }
        
        if ($user_id) {
            $data['user'] = $user_id;
        }
        
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

        $map = [
            self::STATUS_CREATED => 'payment.status.created',
            self::STATUS_PROCESSING => 'payment.status.processing',
            self::STATUS_HOLD => 'payment.status.hold',
            self::STATUS_SUCCESS => 'payment.status.success',
            self::STATUS_FAILURE => 'payment.status.failure',
            self::STATUS_REVERSED => 'payment.status.reversed',
            self::STATUS_EXPIRED => 'payment.status.expired',
        ];

        $key = $map[$status] ?? 'payment.status.unknown';
        return $i18n->t($key);
    }

    public function get_status_badge_class(): string
    {
        return match($this->get('status')) {
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILURE => 'danger',
            self::STATUS_HOLD => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_CREATED => 'secondary',
            self::STATUS_REVERSED => 'warning',
            self::STATUS_EXPIRED => 'secondary',
            default => 'secondary'
        };
    }

    public function verify_with_monopay(): array
    {
        if (!$this->atomic->get('PLUGIN.Monopay.booted')) {
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

        if ($monopay_status !== self::STATUS_SUCCESS) {
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

