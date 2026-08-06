<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface HasRolesInterface
{
    public function get_role_slugs(): array;
}
