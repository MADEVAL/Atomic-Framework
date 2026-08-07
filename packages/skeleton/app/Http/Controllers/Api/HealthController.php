<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\Response;

class HealthController extends Controller
{
    public function index(\Base $f3): void
    {
        Response::instance()->send_json_success([
            'status' => 'ok',
            'timestamp' => time(),
            'version' => ATOMIC_VERSION,
        ]);
    }
}
