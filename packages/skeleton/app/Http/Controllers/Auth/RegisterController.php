<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Core\Hash;
use Engine\Atomic\Core\Response;
use App\Models\User;

class RegisterController extends Controller
{
    public function showForm(\Base $f3): void
    {
        if (Auth::instance()->get_current_user() !== null) {
            $f3->reroute('/dashboard');
        }

        $f3->set('PAGE.title', 'Register');
        echo \View::instance()->render('layout/auth/register.atom.php');
    }

    public function register(\Base $f3): void
    {
        $response = Response::instance();
        $name     = trim((string)$f3->get('POST.name'));
        $email    = trim((string)$f3->get('POST.email'));
        $password = (string)$f3->get('POST.password');
        $confirm  = (string)$f3->get('POST.password_confirm');

        if ($email === '' || $password === '') {
            $response->send_json_error('Email and password are required.', 400);
            return;
        }

        if ($password !== $confirm) {
            $response->send_json_error('Passwords do not match.', 400);
            return;
        }

        if (mb_strlen($password) < 8) {
            $response->send_json_error('Password must be at least 8 characters.', 400);
            return;
        }

        $existing = new User();
        $existing->load(['email = ?', $email]);
        if (!$existing->dry()) {
            $response->send_json_success(['message' => 'If the email is not registered, a verification link has been sent.']);
            return;
        }

        $user = new User();
        $user->uuid     = bin2hex(random_bytes(16));
        $user->name     = $name;
        $user->email    = $email;
        $user->password = Hash::password($password);
        $user->save();

        Auth::instance()->login_by_id((string)$user->uuid);

        $response->send_json_success(['redirect' => '/dashboard']);
    }
}
