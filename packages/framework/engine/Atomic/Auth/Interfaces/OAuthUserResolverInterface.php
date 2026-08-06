<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface OAuthUserResolverInterface
{
    public function resolve_oauth_user(array $claims): ?string;
}
