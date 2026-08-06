<?php
declare(strict_types=1);
namespace Engine\Atomic\Validator\PreValidation;

if (!defined('ATOMIC_START')) {
	exit;
}

trait NullableEmptyToNullTrait
{
	protected function pre_validate_nullable_empty_to_null(): void
	{
		foreach ($this->getFieldConfiguration() as $field => $conf) {
			if (in_array($conf['relType'] ?? null, ['has-many', 'belongs-to-many'], true)) {
				continue;
			}
			if (empty($conf['nullable'])) {
				continue;
			}
			$val = isset($conf['relType']) ? $this->getRaw($field) : $this->get($field);
			if ($val === null) {
				continue;
			}
			if ($val === '' || (is_array($val) && $val === [])) {
				$this->set($field, null);
			}
		}
	}
}
