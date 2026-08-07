<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

/**
 * @deprecated Use App\Http\Controllers\Auth\* controllers instead.
 * Kept for backward compatibility — delegates to new controllers.
 */
class AuthController extends Controller
{
    public function login(\Base $f3): void
    {
        if ($f3->get('VERB') === 'GET') {
            (new Auth\LoginController(app()))->showForm($f3);
        } else {
            (new Auth\LoginController(app()))->login($f3);
        }
    }

    public function register(\Base $f3): void
    {
        if ($f3->get('VERB') === 'GET') {
            (new Auth\RegisterController(app()))->showForm($f3);
        } else {
            (new Auth\RegisterController(app()))->register($f3);
        }
    }

    public function logout(\Base $f3): void
    {
        (new Auth\LogoutController(app()))->logout($f3);
    }
}
