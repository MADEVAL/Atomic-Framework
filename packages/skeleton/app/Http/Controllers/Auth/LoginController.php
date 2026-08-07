<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Core\Hash;
use Engine\Atomic\Core\Response;

class LoginController extends Controller
{
    public function showForm(\Base $f3): void
    {
        if (Auth::instance()->get_current_user() !== null) {
            $f3->reroute('/dashboard');
        }

        $f3->set('PAGE.title', 'Login');
        echo \View::instance()->render('layout/auth/login.atom.php');
    }

    public function login(\Base $f3): void
    {
        $response = Response::instance();
        $email    = trim((string)$f3->get('POST.email'));
        $password = (string)$f3->get('POST.password');

        if ($email === '' || $password === '') {
            $response->send_json_error('Email and password are required.', 400);
            return;
        }

        $auth = Auth::instance();
        $user = $auth->login_with_secret(['email' => $email], $password);

        if ($user === null) {
            Hash::dummy_timing_mitigation();
            $response->send_json_error('Invalid credentials.', 401);
            return;
        }

        $response->send_json_success(['redirect' => '/dashboard']);
    }
}
