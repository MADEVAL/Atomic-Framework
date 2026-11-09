<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use DB\Cortex\Schema\ColumnType;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Validator\PreValidation\NullableEmptyToNullTrait;
use Engine\Atomic\Validator\Validator;

abstract class Model extends Cortex 
{
	use NullableEmptyToNullTrait;
	
	protected $table = null;
	protected $db = 'DB';
	protected $fieldConf = null;
	protected ?string $last_err_code = null;
	protected array $last_err_vars = [];
	private static array $inherited_field_conf_cache = [];

	private function collect_inherited_field_conf(): array {
		if (isset(self::$inherited_field_conf_cache[static::class])) {
			return self::$inherited_field_conf_cache[static::class];
		}

		$chain = array_reverse(class_parents(static::class));
		$chain[] = static::class;

		$merged = [];
		foreach ($chain as $class_name) {
			$ref = new \ReflectionClass($class_name);
			if ($ref->hasProperty('fieldConf')) {
				$prop = $ref->getProperty('fieldConf');
				if ($prop->getDeclaringClass()->getName() === $class_name) {
					$defaults = $ref->getDefaultProperties();
					$conf = $defaults['fieldConf'] ?? null;
					if (is_array($conf) && !empty($conf)) {
						$merged = array_replace_recursive($merged, $conf);
					}
				}
			}
		}

		self::$inherited_field_conf_cache[static::class] = $merged;
		return $merged;
	}

	protected function initialize_field_conf(): void {
		$base = $this->collect_inherited_field_conf();
		if (!empty($base) || is_array($this->fieldConf)) {
			$this->fieldConf = $base;
		}
	}

	public function __construct() {
		$this->db = 'DB';
		$this->initialize_field_conf();
		if ($this->table !== null) {
			$prefix = (string) App::instance()->get('DB_CONFIG.prefix');
			if ($prefix !== '' && !str_starts_with((string) $this->table, $prefix)) {
				$this->table = $prefix . $this->table;
			}
		}
		// Cortex sets 'DB' in its constructor; open lazily on first use.
		ConnectionManager::instance()->get_db(false);
		parent::__construct();
		$saveHandler = function(self $self): bool {
			$self->before_validate();
			$valid = true;
			foreach ($self->getFieldConfiguration() as $field => $conf) {
				if (in_array($conf['relType'] ?? null, ['has-one', 'has-many', 'belongs-to-many'], true)) {
					continue;
				}
				if (!isset($conf['type']) || !is_string($conf['type'])) {
					throw new \LogicException(sprintf(
						'Model %s field "%s" has no column type; pre-save validation cannot run. Declare a "type" in fieldConf or create the column in a migration.',
						static::class,
						$field
					));
				}
				$val = isset($conf['relType']) ? $self->getRaw($field) : $self->get($field);
				$res = Validator::validate_model($conf, $val, $field, $self);
				if (!$res[0]) {
					$valid = false;
					$self->last_err_code = $res[1];
					$self->last_err_vars = $res[2] ?? [];
					break;
				}
			}
			return $valid;
		};
		$this->beforesave($saveHandler);
	}

	/**
	 * Cortex reads the type of a belongs-to-one target field from the related
	 * model's fieldConf. Primary keys never live there (legacy or domain), so
	 * an explicit ['Owner', 'id'] target falls back to the live column type.
	 * A target that cannot be resolved at all fails loudly.
	 */
	protected static function resolveRelationConf($field, $pkey = null) {
		$rel = $field['belongs-to-one'] ?? null;
		if (!is_array($rel) || ($rel[1] ?? '_id') === '_id') {
			return parent::resolveRelationConf($field, $pkey);
		}
		[$target, $key] = [$rel[0], $rel[1]];
		$fc = $target::resolveConfiguration();
		$type = $fc['fieldConf'][$key]['type'] ?? null;
		if ($type === null && $key === $fc['primary']) {
			$db = ConnectionManager::instance()->get_db();
			$table = (string) $fc['table'];
			$raw = ColumnType::withDeclaredTypes($db, $table, $db->schema($table, null, 60))[$key]['type'] ?? null;
			try {
				$type = is_string($raw) ? ColumnType::describe($raw, $table, $key, $db->driver())['type'] : null;
			} catch (\InvalidArgumentException) {
				$type = null;
			}
		}
		if (!is_string($type)) {
			throw new \LogicException(sprintf(
				'Model %s belongs-to-one relation targets %s.%s, which has no known column type. Declare it in %s fieldConf, or point the relation at an existing typed column.',
				static::class,
				$target,
				$key,
				$target
			));
		}
		$field['belongs-to-one']['relPK'] = $fc['primary'];
		$field['type'] = $type;
		if (!array_key_exists('nullable', $field)) {
			$field['nullable'] = true;
		}
		$field['relType'] = 'belongs-to-one';
		return $field;
	}

	protected function before_validate(): void {
		$this->pre_validate_nullable_empty_to_null();
	}

	public function get_field_configuration(): array {
		return $this->getFieldConfiguration();
	}

	public function get_last_err_code(): false|string {
		return $this->last_err_code;
	}

	public function get_last_err_vars(): array {
		return $this->last_err_vars;
	}

	public function get_last_err_data(): array {
		return [$this->last_err_code, $this->last_err_vars];
	}

    public function update_property(mixed $filter, string $key, mixed $value): bool
    {
        $fieldConf = $this->getFieldConfiguration();
        if (!array_key_exists($key, $fieldConf)) {
            return false;
        }

        $this->load($filter);
        if ($this->dry()) {
            return false;
        }

        $ids = [];
        while (!$this->dry()) {
            $ids[] = $this->_id;
            $this->next();
        }

        if ($ids === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->exec(
            "UPDATE `{$this->table}` SET `{$key}` = ? WHERE `_id` IN ({$placeholders})",
            array_merge([$value], $ids)
        );

        return true;
    }
}
