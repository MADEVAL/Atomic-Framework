<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use DB\SQL;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;

final class MigrationContext
{
    public function __construct(
        private readonly App $app,
        private readonly ConnectionManager $connections,
        private readonly Output $output,
    ) {}

    public function app(): App
    {
        return $this->app;
    }

    public function db(string $connection = 'default'): SQL
    {
        $db = $this->connections->get_db(true, false, $connection);
        if (!$db instanceof SQL) {
            throw new \RuntimeException("Database connection '{$connection}' is unavailable.");
        }
        return $db;
    }

    public function schema(string $connection = 'default'): Schema
    {
        return new Schema($this->db($connection));
    }

    public function output(): Output
    {
        return $this->output;
    }
}
