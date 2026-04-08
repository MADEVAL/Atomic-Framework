<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Migrations as CoreMigrations;
use Engine\Atomic\CLI\Init\InitInstaller;
use Engine\Atomic\CLI\Init\InitScaffold;

trait Init
{
    use InitInstaller;
    use InitScaffold;

    /**
     * php atomic init
     * Set up the application.
     */
    public function init(): void
    {
        $this->output->writeln();
        $this->output->writeln("  " . Style::bold('Atomic Framework -- Project Initialization'));
        $this->output->writeln("  " . str_repeat('-', 48));
        $this->output->writeln();

        $root = ATOMIC_DIR;

        $this->output->writeln("  " . Style::yellow('[1/4]', true) . " Creating directories...");
        $created = $this->createSkeletonDirectories($root);
        $this->output->writeln("        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created");
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[2/4]', true) . " Preparing settings...");
        $configSource = $this->chooseConfigSource();
        $this->initializeConfigSource($root, $configSource);

        // Generate keys once - read existing or create new
        $appUuid = $this->readConfigValue('APP_UUID', ID::uuid_v4());
        $appKey = $this->readConfigValue('APP_KEY', bin2hex(random_bytes(16)));
        $encryptionKey = $this->readConfigValue('APP_ENCRYPTION_KEY', $this->generateEncryptionKey());

        // Write keys to the selected config source
        $this->setConfigValue('APP_UUID', $appUuid);
        $this->setConfigValue('APP_KEY', $appKey);
        $this->setConfigValue('APP_ENCRYPTION_KEY', $encryptionKey);
        $this->configureBasicEnv('');

        if ($this->configMode() === 'env') {
            $this->output->writeln("        .env ready");
        } else {
            $this->output->writeln("        PHP config files ready");
        }
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[3/4]', true) . " Stub files...");
        $stubs = $this->createAppStubs($root);
        $this->output->writeln("        {$stubs} stub file" . ($stubs === 1 ? '' : 's') . " created");
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[4/4]', true) . " Database and backend setup...");
        $dbConfig = $this->configureDatabase('');
        if ($dbConfig !== null) {
            $this->bootDatabase($dbConfig);
            if ($this->initializeMigrationDatabase()) {
                (new CoreMigrations($this->output))->migrate();
                $this->output->writeln();

                $driver = $this->chooseMainDriver();
                $this->output->writeln();

                if ($driver === 'redis') {
                    $this->setConfigValue('SESSION_DRIVER', 'redis');
                    $this->setConfigValue('MUTEX_DRIVER',   'redis');
                    $this->setConfigValue('QUEUE_DRIVER',   'redis');
                    $this->output->writeln('  ' . Style::successLabel() . " Redis selected for queue/mutex/session backends.");
                } else {
                    $this->setConfigValue('SESSION_DRIVER', 'db');
                    $this->setConfigValue('MUTEX_DRIVER',   'database');
                    $this->setConfigValue('QUEUE_DRIVER',   'database');
                    $this->setupDatabaseBackendsMigrations();
                }
            }
        } else {
            $this->output->writeln("  Database setup skipped.");
        }

        $this->output->writeln();
        $this->output->writeln("  " . str_repeat('=', 48));
        $this->output->writeln("  " . Style::successLabel() . " Done.");
        $this->output->writeln();
        $this->output->writeln("  Next:");
        if ($this->configMode() === 'env') {
            $this->output->writeln("    1. Open .env if you want to change anything.");
        } else {
            $this->output->writeln("    1. Review config/*.php if you want to change anything.");
        }
        $this->output->writeln("    2. Point your server to public/.");
        $this->output->writeln("    3. Open the site.");
        $this->output->writeln();
    }

    /**
     * php atomic init/key
     * Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY in existing config
     */
    public function initKey(): void
    {
        $root = ATOMIC_DIR;

        // Detect config mode from const.php
        $configMode = $this->detectConfigMode($root);
        if ($configMode === null) {
            $this->output->writeln(Style::errorLabel() . " Could not detect config mode. Run 'php atomic init' first.");
            return;
        }

        $this->initializeConfigSource($root, $configMode);

        // Generate new keys once
        $newUuid = ID::uuid_v4();
        $newKey = bin2hex(random_bytes(16));
        $newEncryptionKey = $this->generateEncryptionKey();

        // Write to the configured source
        $this->setConfigValue('APP_UUID', $newUuid);
        $this->setConfigValue('APP_KEY', $newKey);
        $this->setConfigValue('APP_ENCRYPTION_KEY', $newEncryptionKey);

        $source = $configMode === 'env' ? '.env' : 'PHP config files';
        $this->output->writeln(Style::successLabel() . " Keys regenerated in {$source}");
    }

    public function initGuide(): void
    {
        $o   = $this->output;
        $sep = str_repeat('─', 60);

        $o->writeln();
        $o->writeln('  ' . Style::bold('Atomic Framework — Manual Setup Guide'));
        $o->writeln('  ' . Style::bold('A step-by-step replacement for: php atomic init'));
        $o->writeln('  ' . $sep);
        $o->writeln('  Follow every section in order. All paths are relative to');
        $o->writeln('  your project root (where the public/ directory lives).');
        $o->writeln();

        // ── STEP 1 ────────────────────────────────────────────────────────
        $o->writeln('  ' . Style::yellow('[STEP 1 / 4]', true) . ' Create the directory structure');
        $o->writeln('  ' . $sep);
        $o->writeln();
        $o->writeln('  Application directories — permission 0755, owned by your deploy user:');
        $o->writeln();

        $o->writeln('  Runtime directories — permission ' . Style::yellow('0775', true) . ', must be writable by the web server:');
        $o->writeln();

        $runtimeDirs = [
            'storage/logs',
            'storage/framework/cache/data',
            'storage/framework/cache/fonts',
            'storage/framework/fonts',
            'public/uploads',
        ];
        foreach ($runtimeDirs as $dir) {
            $o->writeln("    mkdir -p {$dir} && chmod 0775 {$dir}");
        }

        $o->writeln();
        $o->writeln('  Grant the web-server user write access (replace placeholders):');
        $o->writeln();
        $o->writeln('    sudo chown -R <web-user>:<web-group> storage public/uploads');
        $o->writeln('    sudo chmod -R ug+rwX storage public/uploads');
        $o->writeln('    find storage public/uploads -type d -exec chmod g+s {} \\;');
        $o->writeln();
        $o->writeln('  Common values for <web-user>: www-data, apache, nginx');
        $o->writeln();
        $o->writeln('  Verify write access with:');
        $o->writeln('    sudo -u <web-user> test -w storage/logs       && echo "storage/logs — OK"');
        $o->writeln('    sudo -u <web-user> test -w public/uploads     && echo "public/uploads — OK"');
        $o->writeln();

        // ── STEP 2 ────────────────────────────────────────────────────────
        $o->writeln('  ' . Style::yellow('[STEP 2 / 4]', true) . ' Configuration');
        $o->writeln('  ' . $sep);
        $o->writeln();
        $o->writeln('  Choose ONE config source and follow only that sub-section.');
        $o->writeln();

        // 2-A env
        $o->writeln('  ' . Style::cyan('── Option A: .env  (recommended)', true));
        $o->writeln();
        $o->writeln('  1. Copy the example file:');
        $o->writeln('       cp .env.example .env');
        $o->writeln();
        $o->writeln('  2. Open .env and fill in the values below.');
        $o->writeln('     Keys that must be ' . Style::yellow('generated', true) . ':');
        $o->writeln();
        $o->writeln('     APP_UUID');
        $o->writeln('       A UUID v4. Generate one with:');
        $o->writeln('         php -r "echo sprintf(\'%s-%s-%04x-%04x-%s\',');
        $o->writeln('           bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),');
        $o->writeln('           (hexdec(bin2hex(random_bytes(2))) & 0x0fff) | 0x4000,');
        $o->writeln('           (hexdec(bin2hex(random_bytes(2))) & 0x3fff) | 0x8000,');
        $o->writeln('           bin2hex(random_bytes(6)));"');
        $o->writeln('       Or any online UUID v4 generator.');
        $o->writeln();
        $o->writeln('     APP_KEY');
        $o->writeln('       A random 32-char hex string:');
        $o->writeln('         php -r "echo bin2hex(random_bytes(16));"');
        $o->writeln();
        $o->writeln('     APP_ENCRYPTION_KEY');
        $o->writeln('       A libsodium secretbox key, base64-encoded. Requires ext-sodium:');
        $o->writeln('         php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"');
        $o->writeln();
        $o->writeln('     Other values to set in .env:');
        $o->writeln('       APP_NAME=YourApp          # Human-readable application name');
        $o->writeln('       MAIL_FROM_NAME=YourApp    # Usually the same as APP_NAME');
        $o->writeln();
        $o->writeln('  ' . Style::warningLabel() . ' Never commit .env to version control. Add it to .gitignore.');
        $o->writeln();

        // 2-B php
        $o->writeln('  ' . Style::cyan('── Option B: PHP config files  (alternative)', true));
        $o->writeln();
        $o->writeln('  Edit config/app.php — set these keys inside the returned array:');
        $o->writeln();
        $o->writeln("    'uuid' => '<uuid_v4>',          // generate: see APP_UUID above");
        $o->writeln("    'name' => 'YourApp',");
        $o->writeln("    'key'  => '<32 hex chars>',     // generate: see APP_KEY above");
        $o->writeln();
        $o->writeln('  ' . Style::warningLabel() . ' APP_ENCRYPTION_KEY has no PHP config equivalent.');
        $o->writeln('  Store the sodium key in a secrets manager or a file outside the repo,');
        $o->writeln('  and load it in bootstrap/ before the framework boots.');
        $o->writeln();

        // ── STEP 3 ────────────────────────────────────────────────────────
        $o->writeln('  ' . Style::yellow('[STEP 3 / 4]', true) . ' Configure the database and run migrations');
        $o->writeln('  ' . $sep);
        $o->writeln();
        $o->writeln('  Atomic uses MySQL / MariaDB over TCP.');
        $o->writeln();
        $o->writeln('  1. Create the database (if it does not yet exist):');
        $o->writeln('       mysql -u root -p -e "CREATE DATABASE atomic');
        $o->writeln('         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"');
        $o->writeln();
        $o->writeln('  2. Set credentials in .env:');
        $o->writeln();
        $o->writeln('       DB_DRIVER=mysql');
        $o->writeln('       DB_HOST=127.0.0.1        # Use 127.0.0.1 (not "localhost") to force TCP');
        $o->writeln('       DB_PORT=3306             # Default MySQL port');
        $o->writeln('       DB_DATABASE=atomic       # The database you created above');
        $o->writeln('       DB_USERNAME=root         # MySQL user');
        $o->writeln('       DB_PASSWORD=secret       # MySQL password');
        $o->writeln('       DB_CHARSET=utf8mb4       # Recommended — optional');
        $o->writeln('       DB_COLLATION=utf8mb4_general_ci  # Optional');
        $o->writeln();
        $o->writeln('     PHP config equivalent (config/database.php):');
        $o->writeln("       ['connections']['mysql']['driver']   = 'mysql'");
        $o->writeln("       ['connections']['mysql']['host']     = '127.0.0.1'");
        $o->writeln("       ['connections']['mysql']['port']     = '3306'");
        $o->writeln("       ['connections']['mysql']['database'] = 'atomic'");
        $o->writeln("       ['connections']['mysql']['username'] = 'root'");
        $o->writeln("       ['connections']['mysql']['password'] = 'secret'");
        $o->writeln();
        $o->writeln('  3. Verify the connection manually:');
        $o->writeln('       mysql -h 127.0.0.1 -P 3306 -u root -p atomic');
        $o->writeln();
        $o->writeln('  4. Initialize the migration tracking table (required):');
        $o->writeln('       php atomic migrations/init');
        $o->writeln();
        $o->writeln('  5. Run core framework migrations:');
        $o->writeln('       php atomic migrations/migrate');
        $o->writeln();

        // ── STEP 4 ────────────────────────────────────────────────────────
        $o->writeln('  ' . Style::yellow('[STEP 4 / 4]', true) . ' Choose and configure backend drivers');
        $o->writeln('  ' . $sep);
        $o->writeln();
        $o->writeln('  Choose ONE backend driver and follow only that sub-section.');
        $o->writeln();

        // 4-A database
        $o->writeln('  ' . Style::cyan('── Option A: Database backend  (no extra dependencies)', true));
        $o->writeln();
        $o->writeln('  Use this when Redis is not available or not desired.');
        $o->writeln();
        $o->writeln('  In .env:');
        $o->writeln('    SESSION_DRIVER=db');
        $o->writeln('    MUTEX_DRIVER=database');
        $o->writeln('    QUEUE_DRIVER=database');
        $o->writeln();
        $o->writeln('  PHP config equivalents:');
        $o->writeln("    config/session.php   → ['driver'] = 'db'");
        $o->writeln("    config/database.php  → ['mutex']['driver'] = 'database'");
        $o->writeln("    config/queue.php     → ['driver'] = 'database'");
        $o->writeln();
        $o->writeln('  Run the three backend migrations (run each command, confirm when prompted):');
        $o->writeln('    php atomic db/sessions   # creates the sessions table');
        $o->writeln('    php atomic db/mutex      # creates the mutex table');
        $o->writeln('    php atomic queue/db      # creates the queue tables');
        $o->writeln();
        $o->writeln('  Then apply all pending migrations:');
        $o->writeln('    php atomic migrations/migrate');
        $o->writeln();

        // 4-B redis
        $o->writeln('  ' . Style::cyan('── Option B: Redis backend  (recommended for production)', true));
        $o->writeln();
        $o->writeln('  ' . Style::warningLabel() . ' Requirements:');
        $o->writeln('    1. A running Redis server (version 5+ recommended)');
        $o->writeln('    2. PHP ext-redis loaded in your PHP installation');
        $o->writeln();
        $o->writeln('  Install ext-redis:');
        $o->writeln('    Debian / Ubuntu:  sudo apt install php-redis');
        $o->writeln('    RHEL / CentOS:    sudo dnf install php-redis');
        $o->writeln('    Via PECL:         pecl install redis');
        $o->writeln('    Then add to php.ini / conf.d:  extension=redis');
        $o->writeln();
        $o->writeln('  Verify the extension is active:');
        $o->writeln('    php -m | grep -i redis       # must print "redis"');
        $o->writeln('    php -r "new Redis();"        # must produce no fatal error');
        $o->writeln();
        $o->writeln('  In .env, set the Redis connection:');
        $o->writeln('    REDIS_CLIENT=phpredis');
        $o->writeln('    REDIS_HOST=127.0.0.1         # Redis server hostname or IP');
        $o->writeln('    REDIS_PORT=6379              # Default Redis port');
        $o->writeln('    REDIS_PASSWORD=null          # Set to your Redis password, or leave null');
        $o->writeln('    REDIS_DB=0                   # Redis logical database index (0–15)');
        $o->writeln();
        $o->writeln('  In .env, set the backend drivers:');
        $o->writeln('    SESSION_DRIVER=redis');
        $o->writeln('    MUTEX_DRIVER=redis');
        $o->writeln('    QUEUE_DRIVER=redis');
        $o->writeln();
        $o->writeln('  PHP config equivalents:');
        $o->writeln("    config/session.php   → ['driver'] = 'redis'");
        $o->writeln("    config/database.php  → ['mutex']['driver'] = 'redis'");
        $o->writeln("    config/queue.php     → ['driver'] = 'redis'");
        $o->writeln();
        $o->writeln('  No extra migrations are required for the Redis backend.');
        $o->writeln();

        // ── optional: users migration ─────────────────────────────────────
        $o->writeln('  ' . Style::cyan('── Optional: Users migration', true));
        $o->writeln();
        $o->writeln('  If your application needs the users table, run:');
        $o->writeln('    php atomic db/users');
        $o->writeln('    php atomic migrations/migrate');
        $o->writeln();

        // ── DONE ──────────────────────────────────────────────────────────
        $o->writeln('  ' . str_repeat('=', 60));
        $o->writeln('  ' . Style::successLabel() . '  Setup complete — final checklist:');
        $o->writeln();
        $o->writeln('  [ ] 1. All directories created; runtime dirs are writable by the web user');
        $o->writeln('  [ ] 2. .env (or config/*.php) contains APP_UUID, APP_KEY, APP_ENCRYPTION_KEY,');
        $o->writeln('         APP_NAME, MAIL_FROM_NAME');
        $o->writeln('  [ ] 3. DB credentials set; database exists; php atomic migrations/init run');
        $o->writeln('         before php atomic migrations/migrate');
        $o->writeln('  [ ] 4. Backend driver chosen (database or redis) and configured');
        $o->writeln('  [ ] 5. For Redis: ext-redis loaded and Redis server reachable');
        $o->writeln('  [ ] 6. Web server document root pointed to public/');
        $o->writeln();
        $o->writeln('  To regenerate APP_UUID / APP_KEY / APP_ENCRYPTION_KEY at any time:');
        $o->writeln('    php atomic init/key');
        $o->writeln();
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
            $this->output->writeln("Nothing to rotate (" . (is_array($logs) ? count($logs) : 0) . " log file(s)).");
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
                $this->output->err(Style::warningLabel() . " could not delete {$old}: {$err}");
            }
        }

        $this->output->writeln(Style::successLabel() . " Rotated {$deleted} log file" . ($deleted === 1 ? '' : 's') . ".");
    }
}
