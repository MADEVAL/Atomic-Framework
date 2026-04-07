<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;

trait Init {

    /**
     * php atomic init
     * Scaffold the application: create .env, directories, generate keys.
     */
    public function init(): void
    {
        echo "\n  " . Paint::bold('Atomic Framework -- Project Initialization') . "\n";
        echo "  " . str_repeat('-', 48) . "\n\n";

        $root = ATOMIC_DIR;

        // ── 1. Skeleton directories ──
        $dirs = [
            'app/Event',
            'app/Hook',
            'app/Http/Controllers',
            'app/Http/Middleware',
            'app/Models',
            'bootstrap',
            'config',
            'database/migrations',
            'database/seeds',
            'public/plugins',
            'public/themes/default',
            'public/uploads',
            'resources/views',
            'routes',
            'storage/framework/cache/data',
            'storage/framework/cache/fonts',
            'storage/framework/fonts',
            'storage/logs',
        ];

        $runtimeDirs = array_flip([
            'storage/logs',
            'storage/framework/cache/data',
            'storage/framework/cache/fonts',
            'storage/framework/fonts',
            'public/uploads',
        ]);

        $runtimeNotWritable = [];

        echo "  " . Paint::yellow('[1/4]', true) . " Creating directories...\n";
        $created = 0;
        foreach ($dirs as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            $isRuntime = isset($runtimeDirs[$dir]);
            if (!is_dir($path)) {
                if (mkdir($path, $isRuntime ? 0775 : 0755, true)) {
                    $created++;
                } else {
                    $err = error_get_last()['message'] ?? 'unknown error';
                    echo "        " . Paint::warningLabel() . " could not create {$dir}: {$err}\n";
                    continue;
                }
            }
            if ($isRuntime) {
                @chmod($path, 0775);
                if (!is_writable($path)) {
                    echo "        " . Paint::warningLabel() . " {$dir} is not writable by current web user context\n";
                    $runtimeNotWritable[] = $dir;
                }
            }
        }

        $this->printRuntimePermissionsGuide($runtimeNotWritable);

        echo "        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created\n\n";

        // ── 2. Generate secrets ──
        echo "  " . Paint::yellow('[2/4]', true) . " Generating secrets...\n";
        $uuid   = ID::uuid_v4();
        $appKey = bin2hex(random_bytes(16)); // 32 hex chars

        $encKey = '';
        if (function_exists('sodium_crypto_secretbox_keygen')) {
            $encKey = base64_encode(sodium_crypto_secretbox_keygen());
            echo "        APP_UUID           = {$uuid}\n";
            echo "        APP_KEY            = {$appKey}\n";
            echo "        APP_ENCRYPTION_KEY = (generated, sodium)\n\n";
        } else {
            echo "        APP_UUID           = {$uuid}\n";
            echo "        APP_KEY            = {$appKey}\n";
            echo "        APP_ENCRYPTION_KEY = (skipped, ext-sodium not loaded)\n\n";
        }

        // ── 3. Create .env (if missing) ──
        echo "  " . Paint::yellow('[3/4]', true) . " Environment file...\n";
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';

        if (file_exists($envPath)) {
            echo "        .env already exists -- skipped\n";
            echo "        To regenerate keys, run: php atomic init/key\n\n";
        } else {
            $env = $this->buildEnvTemplate($uuid, $appKey, $encKey);
            file_put_contents($envPath, $env);
            echo "        .env created\n\n";
        }

        // ── 4. Stub files ──
        echo "  " . Paint::yellow('[4/4]', true) . " Stub files...\n";
        $stubs = 0;
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/web.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// Web routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/api.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// API routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/cli.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// Application CLI routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/app/Event/Application.php',
            "<?php\ndeclare(strict_types=1);\nnamespace App\\Event;\n\nclass Application {\n    use \\Engine\\Atomic\\Core\\Traits\\Singleton;\n    public function init(): void {}\n}\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/app/Hook/Application.php',
            "<?php\ndeclare(strict_types=1);\nnamespace App\\Hook;\n\nclass Application {\n    use \\Engine\\Atomic\\Core\\Traits\\Singleton;\n    public function init(): void {}\n}\n"
        );

        echo "        {$stubs} stub file" . ($stubs === 1 ? '' : 's') . " created\n\n";

        echo "  " . str_repeat('=', 48) . "\n";
        echo "  " . Paint::successLabel() . " Next steps:\n\n";
        echo "    1. Review .env and set DB_DATABASE, DOMAIN, etc.\n\n";
        echo "    2. Verify required PHP extensions are loaded:\n";
        echo "       php -m | grep -E 'pdo_mysql|json|mbstring|tokenizer'\n";
        echo "       Optional (recommended): ext-sodium, ext-redis, ext-memcached\n\n";
        echo "    3. Set runtime directory ownership (replace <web-user>):\n";
        echo "       sudo chown -R <web-user>:<web-group> storage public/uploads\n";
        echo "       sudo chmod -R ug+rwX storage public/uploads\n";
        echo "       find storage public/uploads -type d -exec chmod g+s {} \\\;\n";
        echo "       Common web users: www-data, nginx, apache\n\n";
        echo "    4. Verify write access:\n";
        echo "       sudo -u <web-user> test -w storage && echo OK\n";
        echo "       sudo -u <web-user> test -w storage/logs && echo OK\n";
        echo "       sudo -u <web-user> test -w public/uploads && echo OK\n\n";
        echo "    5. Run migrations:\n";
        echo "       php atomic migrations/migrate\n\n";
        echo "    6. Seed initial data:\n";
        echo "       php atomic seed/roles\n\n";
        echo "    7. Verify web server DocumentRoot points to the public/ directory.\n";
        echo "       See DEPLOYMENT_GUIDE.md for Nginx and Apache examples.\n\n";
        echo "    8. Verify deployment:\n";
        echo "       curl -o /dev/null -w '%%{http_code}' http://your-domain/\n";
        echo "       (expect HTTP 200)\n\n";
    }

    /**
     * php atomic init/key
     * Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY in existing .env
     */
    public function initKey(): void
    {
        $envPath = ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envPath)) {
            echo Paint::errorLabel() . " No .env file found. Run 'php atomic init' first.\n";
            return;
        }

        $uuid   = ID::uuid_v4();
        $appKey = bin2hex(random_bytes(16));

        $contents = file_get_contents($envPath);

        $contents = preg_replace('/^APP_UUID=.*$/m',  "APP_UUID={$uuid}",   $contents);
        $contents = preg_replace('/^APP_KEY=.*$/m',   "APP_KEY={$appKey}",  $contents);

        if (function_exists('sodium_crypto_secretbox_keygen')) {
            $encKey = base64_encode(sodium_crypto_secretbox_keygen());
            $contents = preg_replace('/^APP_ENCRYPTION_KEY=.*$/m', "APP_ENCRYPTION_KEY={$encKey}", $contents);
            echo Paint::successLabel() . " APP_ENCRYPTION_KEY regenerated (sodium)\n";
        }

        file_put_contents($envPath, $contents);

        echo "APP_UUID={$uuid}\n";
        echo "APP_KEY={$appKey}\n";
        echo Paint::successLabel() . " Keys written to .env\n";
    }

    /**
     * php atomic logs/rotate
     * Delete php_errors-*.log files beyond the most recent 10.
     */
    public function logsRotate(): void
    {
        $logDir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        $logs   = glob($logDir . DIRECTORY_SEPARATOR . 'php_errors-*.log');

        if (!is_array($logs) || count($logs) <= 10) {
            echo "Nothing to rotate (" . (is_array($logs) ? count($logs) : 0) . " log file(s)).\n";
            return;
        }

        natsort($logs);
        $excess  = array_slice(array_values($logs), 0, count($logs) - 10);
        $deleted = 0;
        foreach ($excess as $old) {
            if (unlink($old)) {
                $deleted++;
            } else {
                $err = error_get_last()['message'] ?? 'unknown error';
                echo Paint::warningLabel() . " could not delete {$old}: {$err}\n";
            }
        }
        echo Paint::successLabel() . " Rotated {$deleted} log file" . ($deleted === 1 ? '' : 's') . ".\n";
    }

    // ── private helpers ──

    private function buildEnvTemplate(string $uuid, string $appKey, string $encKey): string
    {
        return <<<ENV
# Application
APP_NAME=Atomic
APP_KEY={$appKey}
APP_UUID={$uuid}
APP_ENCRYPTION_KEY={$encKey}
DOMAIN=https://example.com/
TZ=UTC
THEME=default

# Locale settings
ENCODING=UTF-8
LANGUAGE=en
FALLBACK=en
I18N_LANGUAGES=en
I18N_DEFAULT=en
I18N_URL_MODE=prefix
I18N_TTL=0
I18N_COOKIE=lang
I18N_SESSION=lang

# Database settings
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=atomic
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# Mail settings
MAIL_DRIVER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=Atomic

# Telegram Bot settings
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_LOG_LEVEL=error

# Cache settings
CACHE_DRIVER=folder
CACHE_PATH=storage/framework/cache/
CACHE_PORT=
CACHE_SERVER=localhost
CACHE_PASSWORD=
CACHE_LOGIN=
CACHE_PREFIX=atomic_

# Session & Cookie settings
SESSION_DRIVER=file
SESSION_LIFETIME=259200
SESSION_COOKIE=Atomic_Session
SESSION_KILL_ON_SUSPECT=true

COOKIE_EXPIRE=259200
COOKIE_PATH=/
COOKIE_DOMAIN=
COOKIE_SECURE=false
COOKIE_HTTPONLY=true
COOKIE_SAMESITE=Lax

# CORS settings
CORS_HEADERS=Content-Type,Authorization
CORS_ORIGIN=*
CORS_CREDENTIALS=false
CORS_EXPOSE=Authorization
CORS_TTL=86400

# Debug settings
DEBUG_MODE=false
DEBUG_LEVEL=error
ATOMIC_HIVE=false
ESCAPE=false

# Paths
UI=public/themes/
TEMP=storage/framework/cache/data/
LOGS=storage/logs/
FONTS=engine/Atomic/Files/fonts
FONTS_TEMP=storage/framework/cache/fonts/
MIGRATIONS=database/migrations/
LOCALES=engine/Atomic/Lang/locales/
USER_PLUGINS=public/plugins/

# Redis settings
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=
REDIS_PORT=6379
REDIS_DB=0

# Memcached settings
MEMCACHED_PORT=11211
MEMCACHED_HOST=127.0.0.1
MEMCACHED_USERNAME=
MEMCACHED_PASSWORD=
MEMCACHED_PREFIX=atomic_

# Rate limiting settings
RATE_LIMIT_REGISTER_IP=10
RATE_LIMIT_REGISTER_CREDENTIAL=3
RATE_LIMIT_REGISTER_IP_TTL=3600
RATE_LIMIT_REGISTER_CREDENTIAL_TTL=86400

RATE_LIMIT_LOGIN_IP=20
RATE_LIMIT_LOGIN_CREDENTIAL=5
RATE_LIMIT_LOGIN_IP_TTL=3600
RATE_LIMIT_LOGIN_CREDENTIAL_TTL=1800

# Mutex settings
MUTEX_DRIVER=file

# Queue settings
QUEUE_DRIVER=database
QUEUE_NAME=default

QUEUE_DATABASE_DEFAULT_WORKER_CNT=5
QUEUE_DATABASE_DEFAULT_BATCH_SIZE=1
QUEUE_DATABASE_DEFAULT_DELAY=0
QUEUE_DATABASE_DEFAULT_PRIORITY=10
QUEUE_DATABASE_DEFAULT_TIMEOUT=20
QUEUE_DATABASE_DEFAULT_MAX_ATTEMPTS=3
QUEUE_DATABASE_DEFAULT_RETRY_DELAY=5

QUEUE_REDIS_DEFAULT_TTL=604800
QUEUE_REDIS_DEFAULT_WORKER_CNT=5
ENV;
    }

    private function writeStubIfMissing(string $path, string $content): int
    {
        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (file_exists($path)) {
            return 0;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        return 1;
    }

    private function printRuntimePermissionsGuide(array $runtimeNotWritable): void
    {
        if ($runtimeNotWritable === []) {
            return;
        }

        echo "\n  Runtime permissions guide:\n";
        echo "    Applications fail with writable/permission errors if these are not writable:\n";
        foreach ($runtimeNotWritable as $dir) {
            echo "      - {$dir}\n";
        }
        echo "\n";
        echo "    Host fix (replace placeholders for your server):\n";
        echo "      sudo chown -R <web-user>:<web-group> storage public/uploads\n";
        echo "      sudo chmod -R ug+rwX storage public/uploads\n";
        echo "      find storage public/uploads -type d -exec chmod g+s {} \\\;\n";
        echo "      sudo -u <web-user> test -w storage && sudo -u <web-user> test -w storage/logs && sudo -u <web-user> test -w public/uploads\n";
        echo "\n";
        echo "    Examples of <web-user>: www-data, apache, nginx\n\n";
    }

}
