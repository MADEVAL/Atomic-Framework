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
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'updated_at' => [
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
        'invoice_pending_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'idempotency_key' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
            'unique' => true,
        ],
        'create_claim_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'reconciled_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
    ];

    /**
     * How long an in-flight invoice/create claim stays valid. A claim older
     * than this is considered abandoned (its owner crashed) and may be
     * re-acquired by a later retry.
     */
    private const CREATE_CLAIM_TTL = 300;

    /**
     * Terminal statuses in which the invoice can never be paid again:
     * declined (failure), refunded (reversed) or expired. A key bound to
     * such a payment must be released so a retry can be paid, otherwise
     * the customer is stuck on a dead invoice link forever.
     */
    private const TERMINAL_NON_PAID_STATUSES = ['failure', 'reversed', 'expired'];

    /**
     * How many times a webhook transition may re-read the committed status
     * and retry its compare-and-swap after losing to a parallel delivery.
     * Bounded so a perpetual race becomes a loud 500 (monobank retries)
     * instead of an infinite loop.
     */
    private const MAX_TRANSITION_ATTEMPTS = 5;

    /**
     * The status this object actually transitioned FROM during the last
     * successful update_from_webhook() call. Differs from the status it
     * read when a parallel delivery committed a different valid transition
     * in between; callers must use it for audit/hook args.
     */
    private ?string $transitioned_from = null;

    public function transitioned_from(): ?string
    {
        return $this->transitioned_from;
    }

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

    /**
     * Atomically claims fulfillment: the UPDATE only matches when the
     * payment is not fulfilled yet AND is still in `success`, so two
     * concurrent deliveries cannot both claim it and a delivery whose
     * re-verification response was snapshotted before a parallel
     * `reversed` committed cannot fulfill a refunded payment. Returns true
     * when THIS call won the claim — losers must not fire the success
     * hooks again.
     */
    public function mark_fulfilled(): bool
    {
        $id = (int)$this->get('id');

        if ($id <= 0) {
            return false;
        }

        $table = static::resolveConfiguration()['table'];
        $fulfilled_at = date('Y-m-d H:i:s');

        $affected = $this->db->exec(
            "UPDATE `{$table}` SET fulfilled_at = ? WHERE id = ? AND fulfilled_at IS NULL AND status = ?",
            [$fulfilled_at, $id, PaymentStatus::SUCCESS->value]
        );

        if ($affected) {
            $this->set('fulfilled_at', $fulfilled_at);
        }

        return (bool)$affected;
    }

    /**
     * Records that the reconciler just polled monobank for this payment.
     *
     * Kept separate from updated_at on purpose: an unchanged status must
     * not make the row look freshly touched (updated_at drives the pending
     * grace period), while reconciled_at is what rotates the queue so the
     * same oldest rows cannot be polled forever.
     */
    public function mark_reconciled(): bool
    {
        $id = (int)$this->get('id');

        if ($id <= 0) {
            return false;
        }

        $table = static::resolveConfiguration()['table'];
        $reconciled_at = date('Y-m-d H:i:s');

        $affected = $this->db->exec(
            "UPDATE `{$table}` SET reconciled_at = ? WHERE id = ?",
            [$reconciled_at, $id]
        );

        if ($affected) {
            $this->set('reconciled_at', $reconciled_at);
        }

        return (bool)$affected;
    }

    public static function is_valid_status_transition(string $from, string $to): bool
    {
        return PaymentStatus::is_valid_status_transition($from, $to);
    }

    /**
     * Applies a verified webhook transition as a compare-and-swap: the
     * UPDATE only matches while the row still carries the expected status,
     * so parallel deliveries race on the row and exactly one of them
     * affects it.
     *
     * Delivery order is not guaranteed, so a loser may not simply be a
     * duplicate: a parallel delivery can have committed a DIFFERENT valid
     * transition from the same origin (e.g. created->processing while we
     * carry created->success). Dropping that delivery would strand the
     * payment in an intermediate state forever, so on a lost CAS the
     * committed status is re-read and the transition is re-evaluated:
     *   - already at the target  -> duplicate, no-op (returns false);
     *   - transition now invalid -> stale, no-op (returns false);
     *   - transition still valid -> retry the CAS from the fresh status.
     * The loop is bounded; persistent losers throw so the caller answers
     * 500 and monobank retries.
     *
     * Returns true when THIS delivery won the transition. Losers must not
     * log history or fire hooks. transitioned_from() reports the status the
     * winning update actually started from.
     */
    public function update_from_webhook(array $webhook_data, ?string $expected_status = null): bool
    {
        $this->transitioned_from = null;

        $id = (int)$this->get('id');

        if ($id <= 0) {
            return false;
        }

        $old_status = $expected_status ?? (string)$this->get('status');
        $new_status = (string)($webhook_data['status'] ?? $old_status);

        $columns = ['status'];
        $values = [$new_status];

        if (isset($webhook_data['invoice_id']) && !$this->get('invoice_id')) {
            $columns[] = 'invoice_id';
            $values[] = $webhook_data['invoice_id'];
        }

        foreach (['final_amount', 'failure_reason', 'error_code', 'created_at', 'updated_at'] as $field) {
            if (isset($webhook_data[$field])) {
                $columns[] = $field;
                $values[] = $webhook_data[$field];
            }
        }

        foreach (['payment_info', 'cancel_list', 'wallet_data', 'tips_info'] as $field) {
            if (isset($webhook_data[$field])) {
                $columns[] = $field;
                $values[] = json_encode($webhook_data[$field]);
            }
        }

        $columns[] = 'webhook_data';
        $values[] = json_encode($webhook_data);

        $assignments = implode(', ', array_map(
            static fn (string $column): string => "`{$column}` = ?",
            $columns
        ));

        $table = static::resolveConfiguration()['table'];
        $sql = "UPDATE `{$table}` SET {$assignments} WHERE id = ? AND status = ?";

        for ($attempt = 0; $attempt < self::MAX_TRANSITION_ATTEMPTS; $attempt++) {
            if ($new_status === $old_status || !self::is_valid_status_transition($old_status, $new_status)) {
                return false;
            }

            $affected = $this->db->exec($sql, array_merge($values, [$id, $old_status]));

            if ($affected) {
                foreach ($columns as $index => $column) {
                    $this->set($column, $values[$index]);
                }

                $this->transitioned_from = $old_status;

                return true;
            }

            // Lost the CAS: another delivery committed between our read and
            // this UPDATE. Re-read the committed status and decide again.
            $committed_status = $this->read_current_status($id);

            if ($committed_status === null) {
                return false;
            }

            $old_status = $committed_status;
        }

        throw new \RuntimeException(
            'Monopay: webhook transition for payment ' . $id . ' kept losing the compare-and-swap race after '
            . self::MAX_TRANSITION_ATTEMPTS . ' attempts'
        );
    }

    /** Reads the status currently committed for a payment, or null if it is gone. */
    private function read_current_status(int $id): ?string
    {
        $table = static::resolveConfiguration()['table'];

        $rows = $this->db->exec("SELECT `status` FROM `{$table}` WHERE id = ?", [$id]);

        if (!$rows) {
            return null;
        }

        return (string)($rows[0]['status'] ?? '');
    }

    /**
     * Creates the preliminary payment row. When an idempotency key is given
     * the row is inserted with the key AND an in-flight create claim, so a
     * concurrent caller that loses the UNIQUE-index race sees an active
     * claim instead of resuming a row that is still being created.
     */
    public static function create_preliminary(
        float $amount,
        $currency,
        ?string $destination = null,
        ?string $idempotency_key = null
    ): ?self {
        $model = new self();

        $now = date('Y-m-d H:i:s');

        $data = [
            'uuid' => ID::uuid_v4(),
            'status' => PaymentStatus::CREATED->value,
            'amount' => $amount,
            'currency' => $currency,
            'destination' => $destination,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($idempotency_key !== null && $idempotency_key !== '') {
            $data['idempotency_key'] = $idempotency_key;
            $data['create_claim_at'] = $now;
        }

        foreach ($data as $key => $value) {
            $model->set($key, $value);
        }

        if ($model->save()) {
            return $model;
        }

        return null;
    }

    public static function get_by_idempotency_key(string $key): self|false
    {
        if ($key === '') {
            return false;
        }

        return (new self())->findone(['idempotency_key = ?', $key]);
    }

    /**
     * True when the payment ended in a status whose invoice can never be
     * paid again. Such a payment must not keep its idempotency key: every
     * retry would replay an unpayable invoice.
     */
    public function is_terminal_non_paid(): bool
    {
        return in_array((string)$this->get('status'), self::TERMINAL_NON_PAID_STATUSES, true);
    }

    /**
     * Atomically releases the idempotency key of a dead payment.
     *
     * The conditional UPDATE decides the winner by affected rows: it only
     * matches while this exact row still holds this exact key AND is still
     * in a terminal non-paid status. Concurrent retries therefore release
     * the key exactly once; the losers get 0 affected rows and must re-read
     * the key instead of assuming the row still owns it. The payment row
     * itself is kept for audit.
     */
    public function release_idempotency_key(): bool
    {
        $id = (int)$this->get('id');
        $key = (string)$this->get('idempotency_key');

        if ($id <= 0 || $key === '') {
            return false;
        }

        $table = static::resolveConfiguration()['table'];
        $placeholders = implode(', ', array_fill(0, count(self::TERMINAL_NON_PAID_STATUSES), '?'));

        $affected = $this->db->exec(
            "UPDATE `{$table}` SET idempotency_key = NULL"
            . " WHERE id = ? AND idempotency_key = ? AND status IN ({$placeholders})",
            array_merge([$id, $key], self::TERMINAL_NON_PAID_STATUSES)
        );

        if ($affected) {
            $this->set('idempotency_key', null);
        }

        return (bool)$affected;
    }

    /**
     * Atomically claims the right to call invoice/create for this payment.
     *
     * The conditional UPDATE decides the winner by affected rows: only a
     * payment that still has no bound invoice and either no claim or an
     * expired one can be claimed. A fresh row inserted by another caller
     * carries a live claim, so it is NOT resumable while its owner is still
     * in flight; a claim abandoned by a crashed owner becomes claimable
     * again after CREATE_CLAIM_TTL seconds.
     */
    public function claim_create(): bool
    {
        $id = (int)$this->get('id');

        if ($id <= 0) {
            return false;
        }

        $table = static::resolveConfiguration()['table'];
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - self::CREATE_CLAIM_TTL);

        $affected = $this->db->exec(
            "UPDATE `{$table}` SET create_claim_at = ?"
            . ' WHERE id = ? AND invoice_id IS NULL'
            . ' AND (create_claim_at IS NULL OR create_claim_at < ?)',
            [$now, $id, $cutoff]
        );

        if ($affected) {
            $this->set('create_claim_at', $now);
        }

        return (bool)$affected;
    }

    /**
     * Marks the invoice create for this payment as ambiguously failed: the
     * row (and its reference) is kept so a retry resumes it. The in-flight
     * claim is released, so the very next retry may claim it immediately.
     */
    public function mark_create_pending(): bool
    {
        $this->set('invoice_pending_at', date('Y-m-d H:i:s'));
        $this->set('create_claim_at', null);
        return (bool)$this->save();
    }

    /**
     * Binds the invoice returned by monobank and releases the create claim.
     */
    public function bind_invoice(string $invoice_id, ?string $page_url): bool
    {
        $this->set('invoice_id', $invoice_id);
        $this->set('page_url', $page_url);
        $this->set('invoice_pending_at', null);
        $this->set('create_claim_at', null);

        return (bool)$this->save();
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
