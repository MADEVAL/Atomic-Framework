<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;

class HomeController extends Controller
{
    public function index(\Base $f3): void
    {
        $f3->set('PAGE.title', 'Atomic Framework');
        echo \View::instance()->render('layout/home.atom.php');
    }
}
