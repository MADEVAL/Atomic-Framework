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

    /** @var array<string, true> BIGINT UNSIGNED columns, rebuilt whenever the schema is set */
    private array $unsigned_bigint = [];

    public function schema($fields = null)
    {
        // Cortex reapplies cached schemas when changing its field whitelist.
        // F3 ignores an empty array, so the cache must too.
        if (is_array($fields) && $fields) {
            $this->unsigned_bigint = [];
            foreach ($fields as $name => &$field) {
                if (preg_match('/^bigint(?:\(\d+\))?\s+unsigned\b/i', $field['type'])) {
                    $this->unsigned_bigint[$name] = true;
                    // Keep every SQL path (including generated IDs and primary-key
                    // filters) exact; public values are normalized separately.
                    $field['pdo_type'] = \PDO::PARAM_STR;
                }
            }
            unset($field);
        }
        return parent::schema($fields);
    }

    /** @var array<string, mixed> unsigned BIGINT input that could not be stored exactly, by field */
    private array $rejected = [];

    /**
     * Assignment never throws for BIGINT UNSIGNED: valid input is stored
     * exactly, anything else is kept as given so model validation can
     * report it on save. write_guard() stops it before the query.
     */
    public function set($key, $val)
    {
        // F3 classifies MySQL BIT as string: binding '1' writes ASCII 49.
        if ($this->engine === 'mysql' && isset($this->fields[$key])
            && preg_match('/^bit\(1\)$/i', $this->fields[$key]['type'])) {
            $this->fields[$key]['pdo_type'] = \PDO::PARAM_INT;
        }
        if (!$this->is_unsigned_bigint($key)) {
            return parent::set($key, $val);
        }
        unset($this->rejected[$key]);
        $exact = $this->exact_unsigned_bigint($val);
        if ($exact === false) {
            $this->rejected[$key] = $val;
            return parent::set($key, $val);
        }
        parent::set($key, $exact);
        return $this->fields[$key]['value'] = $exact;
    }

    public function reset()
    {
        $this->rejected = [];
        parent::reset();
    }

    public function update()
    {
        $this->write_guard();
        return parent::update();
    }

    /** Last line before SQL, after model validation and save hooks. */
    private function write_guard(): void
    {
        foreach ($this->rejected as $key => $val) {
            if (empty($this->fields[$key]['changed'])) continue;
            if (is_float($val)) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" is BIGINT UNSIGNED; float input may already have lost precision. PHP int is 64-bit signed on this platform (PHP_INT_SIZE=%d). Pass the value as a numeric string and use the bcmath or gmp PHP extension for arithmetic on such numbers.',
                    $key, PHP_INT_SIZE
                ));
            }
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" is BIGINT UNSIGNED and requires an integer from 0 to 18446744073709551615; got %s.',
                $key, is_scalar($val) ? var_export($val, true) : get_debug_type($val)
            ));
        }
    }

    private function is_unsigned_bigint($key): bool
    {
        return isset($this->unsigned_bigint[$key], $this->fields[$key]);
    }

    /**
     * Exact value for an unsigned BIGINT: int when it fits PHP int, numeric
     * string above it, null kept. Accepts at least what model validation
     * accepts (surrounding whitespace, a "+" sign, "-0"). False otherwise.
     */
    private function exact_unsigned_bigint($value): int|string|null|false
    {
        if ($value === null || (is_int($value) && $value >= 0)) return $value;
        if (!is_string($value)) return false;
        $value = trim($value);
        if (preg_match('/^-0+$/D', $value)) return 0;
        if (!preg_match('/^\+?[0-9]+$/D', $value)) return false;
        $digits = ltrim(ltrim($value, '+'), '0');
        if ($digits === '') return 0;
        $maximum = '18446744073709551615';
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return false;
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
                $exact = $this->exact_unsigned_bigint($value);
                if ($exact !== false) $row[$key] = $exact;
            }
        }
        return parent::factory($row);
    }

    public function insert()
    {
        $this->write_guard();
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
