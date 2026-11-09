<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\Core\Migrations\Migration;

/**
 * Adds the client-side idempotency columns to installs that already ran
 * monopay_create_payments_tables (which shipped without them).
 *
 * - invoice_pending_at: the create() response was lost; the row is kept so a
 *   retry with the same idempotency key can resume it.
 * - idempotency_key: caller-supplied key; the UNIQUE index decides the
 *   concurrent first-create race (MySQL allows many NULLs, so keyless
 *   payments are unaffected). The column uses utf8mb4_bin so keys compare
 *   byte-exactly instead of with the connection's case-folding collation.
 * - create_claim_at: in-flight invoice/create claim, so only one retry calls
 *   monobank at a time.
 * - reconciled_at: when the reconciler last polled monobank for this row. It
 *   rotates the reconciliation queue so unchanged pending invoices cannot
 *   starve newer candidates.
 *
 * up() and down() are safe to retry: every column and the index are checked
 * before they are changed.
 */
return new class extends Migration {
    public function up(): void
    {
        $out = $this->output();
        $db = $this->db();
        $schema = $this->schema();
        $pay_table = $this->table('payments');

        if (!$this->has_table($pay_table)) {
            $out->writeln("Table '{$pay_table}' does not exist. Nothing to upgrade.");
            return;
        }

        $columns = $schema->alterTable($pay_table)->getCols();
        $tm = $schema->alterTable($pay_table);
        $added = false;

        if (!in_array('invoice_pending_at', $columns, true)) {
            $tm->addColumn('invoice_pending_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
            $added = true;
        }
        if (!in_array('create_claim_at', $columns, true)) {
            $tm->addColumn('create_claim_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
            $added = true;
        }
        if (!in_array('reconciled_at', $columns, true)) {
            $tm->addColumn('reconciled_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
            $added = true;
        }
        if ($added) {
            $tm->build();
            $out->writeln("Table '{$pay_table}' upgraded with idempotency columns.");
        }

        // Idempotency keys are opaque caller tokens and must compare
        // byte-exactly, but the connection's default collation
        // (utf8mb4_general_ci) folds case: 'Order-A' and 'order-a' would
        // resolve to the same payment and replay the same invoice. The
        // Schema builder cannot express a per-column collation, so the
        // column is created (or converted) with an explicit binary one.
        // information_schema and CHARACTER SET/COLLATE are MySQL-only.
        if (str_contains($db->driver(), 'mysql')) {
            $table = $db->quotekey($pay_table);
            $definition = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL';

            if (!in_array('idempotency_key', $columns, true)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN `idempotency_key` {$definition}");
                $out->writeln("Column '{$pay_table}.idempotency_key' added with utf8mb4_bin collation.");
            } else {
                $rows = (array)$db->exec(
                    'SELECT COLLATION_NAME FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$pay_table, 'idempotency_key']
                );
                $row = array_change_key_case((array)($rows[0] ?? []), CASE_LOWER);
                $collation = strtolower((string)($row['collation_name'] ?? ''));

                if ($collation !== 'utf8mb4_bin') {
                    $db->exec("ALTER TABLE {$table} MODIFY `idempotency_key` {$definition}");
                    $out->writeln(
                        "Column '{$pay_table}.idempotency_key' converted from"
                        . " '{$collation}' to utf8mb4_bin."
                    );
                }
            }
        } elseif (!in_array('idempotency_key', $columns, true)) {
            $km = $schema->alterTable($pay_table);
            $km->addColumn('idempotency_key')->type(Schema::DT_VARCHAR256)->nullable(true);
            $km->build();
            $out->writeln("Column '{$pay_table}.idempotency_key' added.");
        }

        // Re-check after the collation conversion: a MODIFY rebuilds the
        // column, so make sure the byte-exact UNIQUE index still exists.
        if (!$schema->hasIndex($pay_table, ['idempotency_key'], true)) {
            $im = $schema->alterTable($pay_table);
            $im->addIndex(['idempotency_key'], true);
            $im->build();
            $out->writeln("Unique index on '{$pay_table}.idempotency_key' created.");
        }
    }

    public function down(): void
    {
        $out = $this->output();
        $schema = $this->schema();
        $pay_table = $this->table('payments');

        if (!$this->has_table($pay_table)) {
            $out->writeln("Table '{$pay_table}' does not exist. Nothing to roll back.");
            return;
        }

        // Drop the index before its column: MySQL removes the index with
        // the column, which would make a later DROP INDEX fail.
        if ($schema->hasIndex($pay_table, ['idempotency_key'], false)) {
            $im = $schema->alterTable($pay_table);
            $im->dropIndex(['idempotency_key']);
            $im->build();
            $out->writeln("Unique index on '{$pay_table}.idempotency_key' dropped.");
        }

        $columns = $schema->alterTable($pay_table)->getCols();
        $tm = $schema->alterTable($pay_table);
        $removed = false;
        foreach (['reconciled_at', 'create_claim_at', 'idempotency_key', 'invoice_pending_at'] as $column) {
            if (in_array($column, $columns, true)) {
                $tm->dropColumn($column);
                $removed = true;
            }
        }
        if ($removed) {
            $tm->build();
            $out->writeln("Idempotency columns dropped from '{$pay_table}'.");
        }
    }
};
