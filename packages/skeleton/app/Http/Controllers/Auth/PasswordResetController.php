<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\Response;
use App\Models\User;
use Engine\Atomic\Core\Hash;

class PasswordResetController extends Controller
{
    public function showRequestForm(\Base $f3): void
    {
        $f3->set('PAGE.title', 'Reset Password');
        echo \View::instance()->render('layout/auth/password-request.atom.php');
    }

    public function sendResetLink(\Base $f3): void
    {
        $response = Response::instance();
        $email = trim((string)$f3->get('POST.email'));

        if ($email === '') {
            $response->send_json_error('Email is required.', 400);
            return;
        }

        // Generic message — no user enumeration
        $response->send_json_success([
            'message' => 'If the email exists, a reset link has been sent.',
        ]);
    }

    public function showResetForm(\Base $f3): void
    {
        $token = $f3->get('PARAMS.token');
        $f3->set('PAGE.title', 'New Password');
        $f3->set('reset_token', $token);
        echo \View::instance()->render('layout/auth/password-reset.atom.php');
    }

    public function reset(\Base $f3): void
    {
        $response = Response::instance();
        $token = $f3->get('PARAMS.token');
        $password = (string)$f3->get('POST.password');
        $confirm = (string)$f3->get('POST.password_confirm');

        if ($password === '' || $password !== $confirm) {
            $response->send_json_error('Passwords do not match.', 400);
            return;
        }

        if (mb_strlen($password) < 8) {
            $response->send_json_error('Password must be at least 8 characters.', 400);
            return;
        }

        // Token validation would go here
        $response->send_json_success([
            'message' => 'Password has been reset.',
            'redirect' => '/login',
        ]);
    }
}
