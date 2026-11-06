<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

enum MigrationDirection: string
{
    case UP = 'up';
    case DOWN = 'down';
}
