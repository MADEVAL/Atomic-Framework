<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface UserProviderInterface
{
    public function find_by_credentials(array $credentials): ?AuthenticatableInterface;
    public function find_by_id(string $auth_id): ?AuthenticatableInterface;
}
