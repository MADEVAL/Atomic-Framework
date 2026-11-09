<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Structure;

if (!defined('ATOMIC_START')) exit;

/** Exact unsigned BIGINT storage and optional inserts using database defaults. */
final class DefaultSqlMapper extends \DB\SQL\Mapper
{
    public function __construct(\DB\SQL $db, $table, $fields = null, $ttl = 60, private bool $default_insert = true)
    {
        parent::__construct($db, $table, $fields, $ttl);
        $this->schema($this->fields);
    }

    public function schema($fields = null)
    {
        // Cortex reapplies cached schemas when changing its field whitelist.
        if (is_array($fields)) {
            foreach ($fields as &$field) {
                if (preg_match('/^bigint(?:\(\d+\))?\s+unsigned\b/i', $field['type'])) {
                    // Keep every SQL path (including generated IDs and primary-key
                    // filters) exact; public values are normalized separately.
                    $field['pdo_type'] = \PDO::PARAM_STR;
                }
            }
            unset($field);
        }
        return parent::schema($fields);
    }

    public function set($key, $val)
    {
        if (isset($this->fields[$key])
            && preg_match('/^bigint(?:\(\d+\))?\s+unsigned\b/i', $this->fields[$key]['type'])
            && is_float($val)) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" is BIGINT UNSIGNED; float input may already have lost precision. PHP int is 64-bit signed on this platform (PHP_INT_SIZE=%d). Pass the value as a numeric string and use the bcmath or gmp PHP extension for arithmetic on such numbers.',
                $key, PHP_INT_SIZE
            ));
        }
        // F3 classifies MySQL BIT as string: binding '1' writes ASCII 49.
        if ($this->engine === 'mysql' && isset($this->fields[$key])
            && preg_match('/^bit\(1\)$/i', $this->fields[$key]['type'])) {
            $this->fields[$key]['pdo_type'] = \PDO::PARAM_INT;
        }
        if ($this->is_unsigned_bigint($key)) {
            $val = $this->normalize_unsigned_bigint($val);
        }
        $value = parent::set($key, $val);
        if ($this->is_unsigned_bigint($key)) {
            $value = $this->normalize_unsigned_bigint($value);
            $this->fields[$key]['value'] = $value;
        }
        return $value;
    }

    private function is_unsigned_bigint($key): bool
    {
        return isset($this->fields[$key])
            && (bool) preg_match('/^bigint(?:\(\d+\))?\s+unsigned\b/i', $this->fields[$key]['type']);
    }

    private function normalize_unsigned_bigint($value)
    {
        if ($value === null || (is_int($value) && $value >= 0)) return $value;
        if (!is_string($value) || !preg_match('/^\+?[0-9]+$/D', $value)) {
            throw new \InvalidArgumentException('BIGINT UNSIGNED requires an exact non-negative integer or numeric string.');
        }
        $digits = ltrim(ltrim($value, '+'), '0');
        if ($digits === '') return 0;
        $maximum = '18446744073709551615';
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new \InvalidArgumentException('BIGINT UNSIGNED value exceeds 18446744073709551615.');
        }
        $limit = (string) PHP_INT_MAX;
        return strlen($digits) < strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0)
            ? (int) $digits : $digits;
    }

    public function factory($row)
    {
        foreach ($row as $key => $value) {
            if ($this->is_unsigned_bigint($key)) {
                $row[$key] = $this->normalize_unsigned_bigint($value);
            }
        }
        return parent::factory($row);
    }

    public function insert()
    {
        if (!$this->default_insert || $this->changed() || !$this->dry()) return parent::insert();

        $keys = [];
        foreach ($this->fields as $name => $field) {
            if ($field['pkey']) $keys[$name] = $field['previous'];
        }
        if (isset($this->trigger['beforeinsert']) && \Base::instance()->call(
            $this->trigger['beforeinsert'], [$this, $keys]
        ) === false) return $this;

        $this->db->exec('INSERT INTO ' . $this->table .
            ($this->engine === 'mysql'
                ? ' () VALUES ()' : ' DEFAULT VALUES'));
        $this->_id = $this->db->lastinsertid();
        foreach ($this->fields as $name => $field) {
            if ($field['pkey'] && $field['auto_inc']) {
                $this->load([$name . ' = ?', $this->_id]);
                break;
            }
        }
        if (isset($this->trigger['afterinsert'])) {
            \Base::instance()->call($this->trigger['afterinsert'], [$this, $keys]);
        }
        return $this;
    }
}
