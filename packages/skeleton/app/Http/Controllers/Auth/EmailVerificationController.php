<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\Response;

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

        // Token validation would go here
        $response->send_json_success([
            'message' => 'Email verified successfully.',
            'redirect' => '/dashboard',
        ]);
    }
}
