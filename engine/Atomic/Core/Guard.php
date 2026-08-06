<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;

class Guard
{
    public static function is_authenticated(): bool
    {
        $user = Auth::instance()->get_current_user();
        return $user !== null;
    }
    
    public static function is_guest(): bool
    {
        $user = Auth::instance()->get_current_user();
        return $user === null;
    }

    public static function has_role(string|\BackedEnum $role): bool
    {
        $user = Auth::instance()->get_current_user();
        
        if (!$user || !($user instanceof HasRolesInterface)) {
            return false;
        }

        $role_slug = self::role_to_slug($role);
        $userRoles = $user->get_role_slugs();
        return in_array($role_slug, $userRoles, true);
    }
    
    public static function has_any_role(array $roles): bool
    {
        $user = Auth::instance()->get_current_user();
        
        if (!$user || !($user instanceof HasRolesInterface)) {
            return false;
        }
        
        $userRoles = $user->get_role_slugs();
        
        foreach ($roles as $role) {
            if (!is_string($role) && !($role instanceof \BackedEnum)) {
                continue;
            }
            $slug = self::role_to_slug($role);
            if (in_array($slug, $userRoles, true)) {
                return true;
            }
        }
        
        return false;
    }
    
    public static function lacks_role(string|\BackedEnum $role): bool
    {
        $user = Auth::instance()->get_current_user();
        
        if (!$user || !($user instanceof HasRolesInterface)) {
            return true;
        }

        $role_slug = self::role_to_slug($role);
        $userRoles = $user->get_role_slugs();
        return !in_array($role_slug, $userRoles, true);
    }
    
    public static function lacks_any_role(array $roles): bool
    {
        return !self::has_any_role($roles);
    }

    protected static function role_to_slug(string|\BackedEnum $role): string
    {
        return $role instanceof \BackedEnum ? (string)$role->value : (string)$role;
    }
}
