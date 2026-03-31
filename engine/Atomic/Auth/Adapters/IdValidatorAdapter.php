<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;

class IdValidatorAdapter
{
    public function is_valid_uuid_v4(string $id): bool
    {
        return ID::is_valid_uuid_v4($id);
    }
}
