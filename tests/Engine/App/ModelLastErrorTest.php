<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

class LastErrorModel extends Model
{
    protected $table = 'last_error_records';
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128', 'required' => true]];
}

final class ModelLastErrorTest extends TestCase
{
    private \DB\SQL $db;
    private string $table;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) $this->markTestSkipped('pdo_mysql required');
        $this->db = ConnectionManager::instance()->get_db();
        $this->table = App::instance()->get('DB_CONFIG.prefix').'last_error_records';
        $this->db->exec('DROP TABLE IF EXISTS `'.$this->table.'`');
        $this->db->exec('CREATE TABLE `'.$this->table.'` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL)');
        ModelDefinitionManager::reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) $this->db->exec('DROP TABLE IF EXISTS `'.$this->table.'`');
        ModelDefinitionManager::reset();
    }

    public function test_last_err_code_is_null_before_any_error(): void
    {
        $model = new LastErrorModel();
        $model->name = 'ok';
        $model->save();
        $this->assertNull($model->get_last_err_code());
        $this->assertSame([null, []], $model->get_last_err_data());
    }

    public function test_successful_save_clears_previous_error(): void
    {
        $model = new LastErrorModel();
        $model->save();
        $this->assertSame('500', $model->get_last_err_code());
        $model->name = 'fixed';
        $model->save();
        $this->assertFalse($model->dry());
        $this->assertNull($model->get_last_err_code());
        $this->assertSame([], $model->get_last_err_vars());
    }
}
