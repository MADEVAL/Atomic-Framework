<?php
declare(strict_types=1);

/**
 * Subprocess harness for invoking skeleton controllers.
 *
 * Controllers call Core\Response::send_json_* which terminates via exit(),
 * so they cannot be exercised in-process. This script runs in a separate
 * PHP process, executes a controller action and writes the JSON body to
 * stdout. The exit code is 0 when the controller terminated normally and
 * non-zero when it threw.
 *
 * Usage: php tests/Support/ControllerRunner.php <scenario> <email> [extra...]
 */

require __DIR__ . '/../bootstrap.php';

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;

// DB\SQL\Schema is a class_alias created when cortex-atomic's schema builder
// file is loaded; in a fresh process nothing references it before App models.
class_exists('DB\Cortex\Schema\Schema');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$scenario = $argv[1] ?? '';
$email    = $argv[2] ?? '';

$atomic = \Base::instance();
$db     = $atomic->get('DB');

if (!$db instanceof PDO && !is_object($db)) {
    fwrite(STDERR, "NO_DB\n");
    exit(3);
}

$prefix = (string)($atomic->get('DB_CONFIG.prefix') ?? '');
$db->exec(
    "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `uuid` VARCHAR(128) NOT NULL,
        `name` VARCHAR(256) NULL,
        `email` VARCHAR(256) NOT NULL,
        `password` VARCHAR(256) NOT NULL,
        `role` VARCHAR(64) NULL DEFAULT 'user',
        `email_verified_at` TIMESTAMP NULL,
        `remember_token` VARCHAR(128) NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$seedUser = static function (string $email) use ($db, $prefix): void {
    $db->exec(
        "INSERT IGNORE INTO `{$prefix}users` (`uuid`, `name`, `email`, `password`, `role`)
         VALUES (?, ?, ?, ?, ?)",
        [bin2hex(random_bytes(16)), 'Seed User', $email, password_hash('StrongPass123', PASSWORD_DEFAULT), 'user']
    );
};

$atomic->set('POST.name', 'Test User');
$atomic->set('POST.email', $email);
$atomic->set('POST.password', 'StrongPass123');
$atomic->set('POST.password_confirm', 'StrongPass123');

switch ($scenario) {
    case 'register_existing':
        $seedUser($email);
        (new RegisterController())->register($atomic);
        break;

    case 'register_new':
        (new RegisterController())->register($atomic);
        break;

    case 'reset_send':
        (new PasswordResetController())->sendResetLink($atomic);
        break;

    default:
        fwrite(STDERR, "UNKNOWN_SCENARIO\n");
        exit(2);
}

fwrite(STDOUT, "NO_TERMINATION");
