<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Theme\Theme;

final class ExampleController extends Controller
{
    public function showcase(\Base $atomic): void
    {
        Theme::instance('example');
        $atomic->set('title', 'Atomic Example Showcase');
        $atomic->set('PAGE.title', 'Atomic Example Showcase');
        $atomic->set('PAGE.color', '#6c5ce7');
        echo \View::instance()->render('layout/showcase.atom.php');
    }
}
