<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Security\PasswordPolicy;
use App\Models\User;
use Engine\Atomic\Core\Hash;
use Engine\Atomic\Tools\Transient;
use Engine\Atomic\Core\ID;

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
        $user = new User();
        $user->load(['email = ?', $email]);
        if (!$user->dry()) {
            $token = ID::generate_unique_id();
            $ttl = 3600;
            Transient::set('pwd_reset_' . $token, $user->get_auth_id(), $ttl);
        } else {
            Hash::dummy_timing_mitigation();
        }

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
        $token    = $f3->get('PARAMS.token');
        $password = (string)$f3->get('POST.password');
        $confirm  = (string)$f3->get('POST.password_confirm');

        // Token validation first — prevents timing enumeration of valid tokens
        $userId = Transient::get('pwd_reset_' . $token);
        if ($userId === false || $userId === null) {
            Hash::dummy_timing_mitigation();
            $response->send_json_error('Invalid or expired reset token.', 400);
            return;
        }

        if ($password === '' || $password !== $confirm) {
            $response->send_json_error('Passwords do not match.', 400);
            return;
        }

        $violations = [];
        if (!PasswordPolicy::default()->validate($password, $violations)) {
            $response->send_json_error(implode(' ', $violations), 400);
            return;
        }

        $user = new User();
        $user->load(['uuid = ?', $userId]);
        if ($user->dry()) {
            $response->send_json_error('User not found.', 400);
            return;
        }

        $user->password = Hash::password($password);
        $user->save();

        Transient::delete('pwd_reset_' . $token);

        $response->send_json_success([
            'message' => 'Password has been reset.',
            'redirect' => '/login',
        ]);
    }
}
