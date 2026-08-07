<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;

class DashboardController extends Controller
{
    public function index(\Base $f3): void
    {
        $f3->set('PAGE.title', 'Admin Dashboard');
        echo \View::instance()->render('layout/admin/dashboard.atom.php');
    }
}
