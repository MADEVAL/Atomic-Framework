<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Enums\Rule;
use PHPUnit\Framework\TestCase;

class LastErrorModel extends Model
{
    protected $table = 'last_error_records';
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128', 'required' => true]];
}

class LastErrorPriceModel extends Model
{
    protected $table = 'last_error_records';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128', 'nullable' => true],
        'price' => ['type' => 'INT4', 'nullable' => true, 'rules' => [Rule::NUM_MIN], 'min' => 0],
    ];
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
        $this->db->exec('CREATE TABLE `'.$this->table.'` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL, price INT NULL)');
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

    public function test_failed_save_exposes_validation_error(): void
    {
        $model = new LastErrorPriceModel();
        $model->price = -1;
        $model->save();

        $error = $model->get_last_validation_error();
        $this->assertNotNull($error);
        $this->assertSame('LastErrorPriceModel', $error->model);
        $this->assertSame('price', $error->field);
        $this->assertSame('MIN', $error->rule);
        $this->assertSame(['min' => 0], $error->vars);
        $this->assertSame('LASTERRORPRICEMODEL_PRICE_MIN', $error->code_name());
        $this->assertSame(['500', ['min' => 0]], $model->get_last_err_data());
        $this->assertSame('500', $model->get_last_err_code());
    }

    public function test_successful_save_clears_validation_error(): void
    {
        $model = new LastErrorPriceModel();
        $model->price = -1;
        $model->save();
        $this->assertNotNull($model->get_last_validation_error());
        $model->price = 5;
        $model->save();
        $this->assertFalse($model->dry());
        $this->assertNull($model->get_last_validation_error());
        $this->assertSame([null, []], $model->get_last_err_data());
    }

    public function test_required_failure_has_required_rule(): void
    {
        $model = new LastErrorModel();
        $model->save();
        $error = $model->get_last_validation_error();
        $this->assertSame('LastErrorModel', $error?->model);
        $this->assertSame('name', $error->field);
        $this->assertSame('REQUIRED', $error->rule);
        $this->assertSame([], $error->vars);
    }
}
