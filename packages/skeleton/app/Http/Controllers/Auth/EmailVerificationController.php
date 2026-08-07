<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Tools\Transient;
use App\Models\User;

class EmailVerificationController extends Controller
{
    public function notice(\Base $f3): void
    {
        $f3->set('PAGE.title', 'Verify Email');
        echo \View::instance()->render('layout/auth/verify-notice.atom.php');
    }

    public function verify(\Base $f3): void
    {
        $token = $f3->get('PARAMS.token');
        $response = Response::instance();

        $userId = Transient::get('email_verify_' . $token);
        if ($userId === false || $userId === null) {
            $response->send_json_error('Invalid or expired verification token.', 400);
            return;
        }

        $user = new User();
        $user->load(['uuid = ?', $userId]);
        if ($user->dry()) {
            $response->send_json_error('User not found.', 400);
            return;
        }

        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->save();

        Transient::delete('email_verify_' . $token);

        $response->send_json_success([
            'message' => 'Email verified successfully.',
            'redirect' => '/dashboard',
        ]);
    }
}
