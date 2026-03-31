<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Codes\Code;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\Cast;
use Engine\Atomic\Validator\Validator;

abstract class Model extends Cortex 
{
	protected $table = null;
	protected $db = 'DB';
	protected $fieldConf = null;
	protected ?string $last_err_code = null;
	protected array $last_err_vars = [];

	public function __construct() {
		$this->db = 'DB';
		if ($this->table !== null) {
			$prefix = (string) App::instance()->get('DB_CONFIG.ATOMIC_DB_PREFIX');
			if ($prefix !== '' && !str_starts_with((string) $this->table, $prefix)) {
				$this->table = $prefix . $this->table;
			}
		}
		parent::__construct();
		$saveHandler = function(Cortex $self): bool {
			foreach ($self->getFieldConfiguration() as $field => $conf) {
				if (($conf['nullable'] ?? false) && ($self->get($field) === '')) {
					$self->set($field, null);
				}
			}
			if (method_exists($self, 'normalize_for_presave')) {
				$self->normalize_for_presave($self);
			}
			$valid = true;
			foreach ($self->getFieldConfiguration() as $field => $conf) {
				// TODO reltype
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

	public function get_last_err_code(): false|string {
		return $this->last_err_code;
	}

	public function get_last_err_vars(): array {
		return $this->last_err_vars;
	}

	public function get_last_err_data(): array {
		return [$this->last_err_code, $this->last_err_vars];
	}

	public function updateProperty(mixed $filter, string $key, mixed $value): bool
	{
		$this->load($filter);
		if ($this->dry()) {
			return false;
		}
		while (!$this->dry()) {
			$this->set($key, $value);
			$this->save();
			$this->next();
		}
		return true;
	}
}