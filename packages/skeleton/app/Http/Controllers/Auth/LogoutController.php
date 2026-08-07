<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Auth\Auth;

class LogoutController extends Controller
{
    /** POST only — requires CSRF token */
    public function logout(\Base $f3): void
    {
        Auth::instance()->logout();
        $f3->reroute('/login');
    }
}
