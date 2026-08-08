<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use Engine\Atomic\Core\App;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the skeleton users migration honours DB_CONFIG.prefix (П-5).
 */
final class SkeletonMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists('DB\Cortex\Schema\Schema'); // DB\SQL\Schema class_alias

        if (App::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }
    }

    private function dropUserTables(App $app): void
    {
        $db      = $app->get('DB');
        $tables  = array_map('current', (array)$db->exec('SHOW TABLES'));

        foreach (['users', (string)$app->get('DB_CONFIG.prefix') . 'users'] as $table) {
            if (in_array($table, $tables, true)) {
                $db->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        }
    }

    public function test_users_migration_creates_prefixed_table(): void
    {
        $app    = App::instance();
        $prefix = (string)$app->get('DB_CONFIG.prefix');

        $this->dropUserTables($app);

        $migration = require ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages'
            . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'create_users_table.php';
        ob_start();
        try {
            $migration['up']();
        } finally {
            ob_end_clean();
        }

        try {
            $tables = array_map('current', (array)$app->get('DB')->exec('SHOW TABLES'));

            $this->assertContains($prefix . 'users', $tables, 'Migration must create the DB_PREFIX-prefixed users table.');
            $this->assertNotContains('users', $tables, 'Migration must not create an unprefixed users table.');
        } finally {
            $this->dropUserTables($app);
        }
    }
}
