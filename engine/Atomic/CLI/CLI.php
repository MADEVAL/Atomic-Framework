<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;

class CLI { 
    use DB;
    use Queue;
    use File;
    use Migrations;
    use Seeder;
    use Scheduler;

    protected App $atomic;

    public function __construct() {
        $this->atomic = App::instance();
    }

    public function help(): void {
        echo "Atomic Help\n";
        echo "  help               - View this help\n";
        echo "  migrate            - Create migrations\n";
        echo "  cache clear        - Clear cache\n";
        echo "  version            - View versions F3, PHP and Atomic\n";
        echo "  routes             - View routes list\n";
        echo "  classes            - View classes list\n";
        echo "  custom-hive        - View custom HIVE\n";
        echo "  queue/db           - Create tables for queues\n";
        echo "  db/payments        - Publish payments migration\n";
        echo "  db/tariffs         - Publish tariffs migration\n";
        echo "  queue/worker       - Run queue worker\n";
        echo "  queue/monitor      - Run queue monitor\n";
        echo "  queue/retry [<job_uuid>|<queue_name>] - Retry failed tasks (optional UUID or queue name)\n";
        echo "  queue/delete       - Delete a job by UUID\n";
        echo "  queue/telemetry/db - Create table for queue telemetry\n";
        echo "  schedule/run       - Run all due scheduled tasks\n";
        echo "  schedule/work      - Run scheduler daemon\n";
        echo "  schedule/list      - List all scheduled tasks\n";
        echo "  schedule/test      - Test scheduler configuration\n";
        echo "  schedule/help      - Show scheduler help\n";
        echo "  file/csv2pdf       - Convert CSV to PDF\n";
        echo "  file/xls2pdf       - Convert XLS to PDF\n";
    }

    public function version(): void {
        echo "Fat-Free Framework Version: " . App::atomic()::VERSION . "\n";
        echo "PHP CLI Version: " . phpversion() . "\n";
        echo "Atomic Version: " . ATOMIC_VERSION . "\n";
    }

    public function listRoutes(): void {
        $routes = $this->atomic->get('ROUTES');
        $groups = [
            'WEB/CLI'   => [],
            'WEB ERROR' => [],
            'API'       => []
        ];
        if (is_array($routes)) {
            foreach ($routes as $pattern => $routeList) {
                if (stripos($pattern, '/error/') !== false) {
                    $groups['WEB ERROR'][] = $pattern;
                } elseif (stripos($pattern, '/api/') !== false) {
                    $groups['API'][] = $pattern;
                } else {
                    $groups['WEB/CLI'][] = $pattern;
                }
            }
        }

        foreach ($groups as $groupName => $routesList) {
            echo "[$groupName]\n";
            if (empty($routesList)) {
                echo "  (no routes)\n";
            } else {
                foreach ($routesList as $r) {
                    echo "  " . $r . "\n";
                }
            }
            echo "\n";
        }
    }

    public function classes(): void {
        echo "Declared Classes:\n";
        print_r(get_declared_classes());
    }

    public function hive(): void {
        print_r($this->atomic->hive());
    }    

    public function customHive(): void {
        $keys = [
            'DEBUG', 'BASE', 'LANGUAGE', 'LANG', 'FALLBACK', 'ENCODING', 'TZ',
            'APP_NAME', 'APP_KEY', 'DEBUG_MODE', 'DEBUG_LEVEL', 
            'CACHE', 'AUTOLOAD', 'UI', 'TEMP', 'LOGS', 'LOCALES', 'FONTS', 'FONTS_TEMP',
            'QUEUE_DRIVER', 'QUEUE_NAME'
        ];
        $hive = $this->atomic->hive();
        $filteredHive = array_intersect_key($hive, array_flip($keys));
        echo "Custom Hive:\n";
        print_r($filteredHive);
    }

    public function get_cli_args(): array {
        global $argv;
        return array_slice($argv, 2);
    }
}
