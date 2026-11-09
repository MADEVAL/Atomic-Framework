<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Structure;

if (!defined('ATOMIC_START')) exit;

/** F3 otherwise silently skips an insert with no changed columns. */
final class DefaultSqlMapper extends \DB\SQL\Mapper
{
    public function set($key, $val)
    {
        // F3 classifies MySQL BIT as string: binding '1' writes ASCII 49.
        if ($this->engine === 'mysql' && isset($this->fields[$key])
            && preg_match('/^bit\(1\)$/i', $this->fields[$key]['type'])) {
            $this->fields[$key]['pdo_type'] = \PDO::PARAM_INT;
        }
        return parent::set($key, $val);
    }

    public function insert()
    {
        if ($this->changed() || !$this->dry()) return parent::insert();

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
