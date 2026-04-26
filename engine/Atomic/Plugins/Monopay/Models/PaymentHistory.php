<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay\Models;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\App\Model;

class PaymentHistory extends Model
{
    protected $table = 'payment_history';
    protected $db = 'DB';
    protected $fieldConf = [
        'payment' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'required' => true,
            'onDelete' => 'RESTRICT',
            'relType' => 'belongs-to-one',
            'belongs-to-one' => Payment::class,
        ],
        'payment_uuid' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
            'required' => true,
        ],
        'status' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'required' => true,
        ],
        'raw_data' => [
            'type' => Schema::DT_TEXT,
            'nullable' => false,
            'required' => true,
        ],
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'default' => Schema::DF_CURRENT_TIMESTAMP,
        ],
    ];

    public static function log_operation(int $payment_id, string $payment_uuid, string $status, array $webhook_data): bool
    {
        $history = new self();

        $history->payment = $payment_id;
        $history->payment_uuid = $payment_uuid;
        $history->status = $status;
        $history->raw_data = json_encode($webhook_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        return (bool)$history->save();
    }

    /** @return self[] */
    public static function get_by_payment(int $payment_id, array $options = [])
    {
        $model = new self();

        $default_options = [
            'order' => 'created_at DESC'
        ];

        $options = array_merge($default_options, $options);

        return $model->find(['payment = ?', $payment_id], $options) ?: [];
    }

    public static function get_latest_by_payment(int $payment_id): ?self
    {
        $model = new self();
        return $model->findone(
            ['payment = ?', $payment_id],
            ['order' => 'created_at DESC']
        );
    }

    /** @return self[] */
    public static function get_by_payment_uuid(string $payment_uuid, array $options = []): array
    {
        $model = new self();

        $default_options = [
            'order' => 'created_at DESC'
        ];

        $options = array_merge($default_options, $options);

        return $model->find(['payment_uuid = ?', $payment_uuid], $options) ?: [];
    }

    public static function get_latest_by_payment_uuid(string $payment_uuid): ?self
    {
        $model = new self();
        return $model->findone(
            ['payment_uuid = ?', $payment_uuid],
            ['order' => 'created_at DESC']
        );
    }

    /** @return self[] */
    public static function get_by_status(string $status, array $options = [])
    {
        $model = new self();
        
        $default_options = [
            'order' => 'created_at DESC'
        ];
        
        $options = array_merge($default_options, $options);
        
        return $model->find(['status = ?', $status], $options) ?: [];
    }

    public function get_raw_data_decoded(): array
    {
        $raw = $this->get('raw_data');
        
        if (empty($raw)) {
            return [];
        }
        
        $decoded = json_decode($raw, true);
        
        return is_array($decoded) ? $decoded : [];
    }

    public static function count_by_payment(int $payment_id): int
    {
        $model = new self();
        return $model->count(['payment = ?', $payment_id], null, 0);
    }

    public static function count_by_payment_uuid(string $payment_uuid): int
    {
        $model = new self();
        return $model->count(['payment_uuid = ?', $payment_uuid], null, 0);
    }
}
