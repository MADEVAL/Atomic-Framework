<?php
declare(strict_types=1);
namespace Engine\Atomic\Session\Models;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use DB\SQL\Schema;

class Session extends Model
{
    protected $table = 'sessions';
    protected $fieldConf = [
        'session_id' => [
            'type' => Schema::DT_VARCHAR256,
        ],
        'data' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'ip' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
        ],
        'agent' => [
            'type' => Schema::DT_VARCHAR512,
            'nullable' => true,
        ],
        'stamp' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
        ],
    ];

}
