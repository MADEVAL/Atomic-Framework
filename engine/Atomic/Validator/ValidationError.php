<?php
declare(strict_types=1);
namespace Engine\Atomic\Validator;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Codes\Code;
use Engine\Atomic\Codes\CodeCatalog;

final class ValidationError
{
	public function __construct(
		public readonly string $model,
		public readonly string $field,
		public readonly string $rule,
		public readonly array $vars = [],
	) {}

	public function code_name(): string {
		return strtoupper($this->model) . '_' . strtoupper($this->field) . '_' . $this->rule;
	}

	public function code_in(string $codes_class): string {
		$const = $codes_class . '::' . $this->code_name();
		return defined($const) ? (string) constant($const) : Code::SERVER_ERROR;
	}

	public function message(?CodeCatalog $catalog = null, ?string $lang = null): string {
		$catalog ??= new CodeCatalog();
		return $catalog->message($this->code_in($catalog->codes_class), $this->vars, $lang);
	}
}
