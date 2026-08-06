<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Auth\Auth;

class DashboardController extends Controller
{
    public function index(\Base $f3): void
    {
        $user = Auth::instance()->get_current_user();
        $f3->set('PAGE.title', 'Dashboard');
        echo \View::instance()->render('account/dashboard.atom.php', 'text/html', [
            'user' => $user,
        ]);
    }
}
