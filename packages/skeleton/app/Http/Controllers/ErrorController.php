<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;

class ErrorController extends Controller
{
    public function notFound(\Base $f3): void
    {
        $f3->set('PAGE.title', '404 Not Found');
        echo \View::instance()->render('layout/errors/404.atom.php');
    }

    public function forbidden(\Base $f3): void
    {
        $f3->set('PAGE.title', '403 Forbidden');
        echo \View::instance()->render('layout/errors/403.atom.php');
    }

    public function serverError(\Base $f3): void
    {
        $f3->set('PAGE.title', '500 Server Error');
        echo \View::instance()->render('layout/errors/500.atom.php');
    }

    public function maintenance(\Base $f3): void
    {
        $f3->set('PAGE.title', '503 Maintenance');
        echo \View::instance()->render('layout/errors/503.atom.php');
    }
}
