<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Models;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;

class MutexLock extends Model
{
    protected $table = 'mutex_locks';
    
    protected $fieldConf = [
        'name' => [
            'type' => \DB\Cortex\Schema\Schema::DT_VARCHAR256,
            'unique' => true,
        ],
        'token' => [
            'type' => \DB\Cortex\Schema\Schema::DT_VARCHAR128,
        ],
        'expires_at' => [
            'type' => \DB\Cortex\Schema\Schema::DT_INT,
        ],
        'created_at' => [
            'type' => \DB\Cortex\Schema\Schema::DT_INT,
        ],
    ];

}
