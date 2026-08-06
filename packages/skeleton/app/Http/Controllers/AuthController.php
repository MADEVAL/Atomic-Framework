<?php
declare(strict_types=1);
namespace App\Http\Controllers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Response;
use App\Http\Models\User;

class AuthController extends Controller
{
    public function login(\Base $f3): void
    {
        if (Guard::is_authenticated()) {
            $f3->reroute('/dashboard');
        }

        if ($f3->get('VERB') === 'GET') {
            $f3->set('PAGE.title', 'Login');
            echo \View::instance()->render('layout/auth/login.atom.php');
            return;
        }

        $response = Response::instance();
        $email    = trim((string)$f3->get('POST.email'));
        $password = (string)$f3->get('POST.password');

        if (empty($email) || empty($password)) {
            $response->send_json_error('Email and password are required.', 400);
            return;
        }

        $auth = Auth::instance();
        $user = $auth->login_with_secret(['email' => $email], $password);

        if ($user === null) {
            $response->send_json_error('Invalid credentials.', 401);
            return;
        }

        $response->send_json_success(['redirect' => '/dashboard']);
    }

    public function register(\Base $f3): void
    {
        if (Guard::is_authenticated()) {
            $f3->reroute('/dashboard');
        }

        if ($f3->get('VERB') === 'GET') {
            $f3->set('PAGE.title', 'Register');
            echo \View::instance()->render('layout/auth/register.atom.php');
            return;
        }

        $response = Response::instance();
        $name     = trim((string)$f3->get('POST.name'));
        $email    = trim((string)$f3->get('POST.email'));
        $password = (string)$f3->get('POST.password');
        $confirm  = (string)$f3->get('POST.password_confirm');

        if (empty($email) || empty($password)) {
            $response->send_json_error('Email and password are required.', 400);
            return;
        }

        if ($password !== $confirm) {
            $response->send_json_error('Passwords do not match.', 400);
            return;
        }

        $existing = new User();
        $existing->load(['email = ?', $email]);
        if (!$existing->dry()) {
            $response->send_json_error('User with this email already exists.', 409);
            return;
        }

        $user = new User();
        $user->uuid       = bin2hex(random_bytes(16));
        $user->name       = $name;
        $user->email      = $email;
        $user->password   = \Bcrypt::instance()->hash($password);
        $user->created_at = date('Y-m-d H:i:s');
        $user->save();

        $auth = Auth::instance();
        $auth->login_with_secret(['email' => $email], $password);

        $response->send_json_success(['redirect' => '/dashboard']);
    }

    public function logout(\Base $f3): void
    {
        Auth::instance()->logout();
        $f3->reroute('/login');
    }
}
