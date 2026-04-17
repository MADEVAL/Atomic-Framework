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

        $this->output->writeln("  " . Style::yellow('[1/6]', true) . " Synchronizing application keys...");
        $keySyncResult = $this->synchronize_application_keys($root);
        if ($keySyncResult === false) {
            return;
        }
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[2/6]', true) . " Configuration setup...");
        $configSource = $this->choose_config_source();
        $this->initialize_config_source($root, $configSource);
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[3/6]', true) . " Creating directories and files...");
        $created = $this->create_skeleton_directories($root);
        $stubs = $this->create_app_stubs($root);
        $this->output->writeln("        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created");
        $this->output->writeln("        {$stubs} stub file" . ($stubs === 1 ? '' : 's') . " created");
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[4/6]', true) . " Preparing settings...");
        $this->configure_basic_env('');

        if ($this->config_mode() === 'env') {
            $this->output->writeln("        .env ready");
        } else {
            $this->output->writeln("        PHP config files ready");
        }
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[5/6]', true) . " Database and backend setup...");
        $dbConfig = $this->configure_database('');
        if ($dbConfig !== null) {
            $this->boot_database($dbConfig);
            if ($this->initialize_migration_database()) {
                (new CoreMigrations($this->output))->migrate();
                $this->output->writeln();

                $driver = $this->choose_main_driver();
                $this->output->writeln();

                if ($driver === 'redis') {
                    $this->set_config_value('SESSION_DRIVER', 'redis');
                    $this->set_config_value('MUTEX_DRIVER',   'redis');
                    $this->set_config_value('QUEUE_DRIVER',   'redis');
                    $this->output->writeln('  ' . Style::success_label() . " Redis selected for queue/mutex/session backends.");
                } else {
                    $this->set_config_value('SESSION_DRIVER', 'db');
                    $this->set_config_value('MUTEX_DRIVER',   'database');
                    $this->set_config_value('QUEUE_DRIVER',   'database');
                    $this->setup_database_backends_migrations();
                }
            }
        } else {
            $this->output->writeln("  Database setup skipped.");
        }

        $this->output->writeln();
        $this->output->writeln("  " . str_repeat('=', 48));
        $this->output->writeln("  " . Style::success_label() . " Done.");
        $this->output->writeln();
        $this->output->writeln("  Next:");
        if ($this->config_mode() === 'env') {
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
     * Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY in BOTH config sources
     */
    public function init_key(): void
    {
        $root = ATOMIC_DIR;

        $this->output->writeln();
        $this->output->writeln("  " . Style::bold('Regenerating Application Keys'));
        $this->output->writeln("  " . str_repeat('-', 48));
        $this->output->writeln();

        // Show warning about data loss
        $this->output->writeln('  ' . Style::warning_label() . ' ' . Style::bold('WARNING: This will regenerate ALL application keys!'));
        $this->output->writeln();
        $this->output->writeln('  This action will:');
        $this->output->writeln('    - Invalidate all existing user sessions');
        $this->output->writeln('    - Make previously encrypted data unreadable');
        $this->output->writeln('    - Break existing access tokens');
        $this->output->writeln();
        $this->output->writeln('  The framework internally relies on these keys for security-critical');
        $this->output->writeln('  operations. Only proceed if you understand the implications.');
        $this->output->writeln();

        if ($this->input->is_interactive()) {
            if (!$this->confirm('Are you sure you want to regenerate all keys?', false)) {
                $this->output->writeln();
                $this->output->writeln('  ' . Style::warning_label() . ' Key regeneration cancelled.');
                return;
            }
        }

        $this->output->writeln();
        $this->output->writeln('  Generating new keys...');

        // Generate new keys
        $newKeys = [
            'APP_UUID' => ID::uuid_v4(),
            'APP_KEY' => bin2hex(random_bytes(16)),
            'APP_ENCRYPTION_KEY' => $this->generate_encryption_key(),
        ];

        // Write to BOTH .env and PHP config
        $this->write_keys_to_env($root, $newKeys);
        $this->write_keys_to_php_config($root, $newKeys);

        $this->output->writeln();
        $this->output->writeln('  ' . Style::success_label() . ' New keys generated:');
        foreach (array_keys($newKeys) as $keyName) {
            $this->output->writeln("    - {$keyName}");
        }
        $this->output->writeln();
        $this->output->writeln('  Written to:');
        $this->output->writeln('    - .env');
        $this->output->writeln('    - config/app.php');
        $this->output->writeln();
    }

    private function generate_and_set_application_keys(bool $force_new = false): array
    {
        if ($force_new) {
            $appUuid = ID::uuid_v4();
            $appKey = bin2hex(random_bytes(16));
            $encryptionKey = $this->generate_encryption_key();
        } else {
            $appUuid = $this->read_config_value('APP_UUID', ID::uuid_v4());
            $appKey = $this->read_config_value('APP_KEY', bin2hex(random_bytes(16)));
            $encryptionKey = $this->read_config_value('APP_ENCRYPTION_KEY', $this->generate_encryption_key());
        }

        $this->set_config_value('APP_UUID', $appUuid);
        $this->set_config_value('APP_KEY', $appKey);
        $this->set_config_value('APP_ENCRYPTION_KEY', $encryptionKey);

        return [
            'APP_UUID' => $appUuid,
            'APP_KEY' => $appKey,
            'APP_ENCRYPTION_KEY' => $encryptionKey,
        ];
    }

    private function display_generated_keys(array $keys): void
    {
        $config_mode = $this->config_mode() === 'env' ? '.env' : 'config/*.php';

        $this->output->writeln("        Generated keys:");
        foreach (array_keys($keys) as $key) {
            $this->output->writeln("          - {$key}");
        }
        $this->output->writeln("        Written to: {$config_mode}");
    }

    private function synchronize_application_keys(string $root): bool
    {
        // Read keys from both sources
        $env_keys = $this->read_keys_from_env($root);
        $php_keys = $this->read_keys_from_php_config($root);
        
        // Check if keys exist and are valid (not empty, not default)
        $envValid = $this->are_keys_valid($env_keys);
        $phpValid = $this->are_keys_valid($php_keys);
        
        // Case 1: Both have valid keys - check if they match
        if ($envValid && $phpValid) {
            $mismatch = $this->find_key_mismatches($env_keys, $php_keys);
            if (!empty($mismatch)) {
                return $this->handle_key_mismatch($mismatch, $env_keys, $php_keys);
            }
            $this->output->writeln("        " . Style::success_label() . " Keys already synchronized between .env and PHP config.");
            return true;
        }
        
        // Case 2: One has valid keys, the other doesn't - sync from valid to invalid
        if ($envValid && !$phpValid) {
            $this->output->writeln("        Syncing keys from .env to PHP config...");
            $this->write_keys_to_php_config($root, $env_keys);
            $this->output->writeln("        " . Style::success_label() . " Keys written to both .env and config/app.php");
            return true;
        }
        
        if ($phpValid && !$envValid) {
            $this->output->writeln("        Syncing keys from PHP config to .env...");
            $this->write_keys_to_env($root, $php_keys);
            $this->output->writeln("        " . Style::success_label() . " Keys written to both .env and config/app.php");
            return true;
        }
        
        // Case 3: Neither has valid keys - generate new ones for both
        $this->output->writeln("        Generating new application keys...");
        $newKeys = [
            'APP_UUID' => ID::uuid_v4(),
            'APP_KEY' => bin2hex(random_bytes(16)),
            'APP_ENCRYPTION_KEY' => $this->generate_encryption_key(),
        ];
        
        $this->write_keys_to_env($root, $newKeys);
        $this->write_keys_to_php_config($root, $newKeys);
        
        $this->output->writeln("        " . Style::success_label() . " New keys generated and written to both .env and config/app.php:");
        foreach (array_keys($newKeys) as $keyName) {
            $this->output->writeln("          - {$keyName}");
        }
        
        return true;
    }
    
    private function read_keys_from_env(string $root): array
    {
        $env_path = $root . DIRECTORY_SEPARATOR . '.env';
        $keys = ['APP_UUID' => '', 'APP_KEY' => '', 'APP_ENCRYPTION_KEY' => ''];
        
        if (!file_exists($env_path)) {
            return $keys;
        }
        
        $contents = (string)file_get_contents($env_path);
        
        foreach (array_keys($keys) as $keyName) {
            if (preg_match('/^' . preg_quote($keyName, '/') . '=(.*)$/m', $contents, $matches)) {
                $keys[$keyName] = trim($matches[1]);
            }
        }
        
        return $keys;
    }
    
    private function read_keys_from_php_config(string $root): array
    {
        $configPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $keys = ['APP_UUID' => '', 'APP_KEY' => '', 'APP_ENCRYPTION_KEY' => ''];
        
        if (!file_exists($configPath)) {
            return $keys;
        }
        
        $config = require $configPath;
        if (!is_array($config)) {
            return $keys;
        }
        
        $keys['APP_UUID'] = (string)($config['uuid'] ?? '');
        $keys['APP_KEY'] = (string)($config['key'] ?? '');
        $keys['APP_ENCRYPTION_KEY'] = (string)($config['encryption_key'] ?? '');
        
        return $keys;
    }
    
    private function are_keys_valid(array $keys): bool
    {
        if (empty($keys['APP_UUID']) || $keys['APP_UUID'] === 'your-uuid-here') {
            return false;
        }
        if (empty($keys['APP_KEY']) || $keys['APP_KEY'] === 'default-key' || $keys['APP_KEY'] === 'your-key-here') {
            return false;
        }
        if (empty($keys['APP_ENCRYPTION_KEY']) || $keys['APP_ENCRYPTION_KEY'] === 'your-encryption-key-here') {
            return false;
        }
        return true;
    }
    
    private function find_key_mismatches(array $env_keys, array $php_keys): array
    {
        $mismatches = [];
        foreach (['APP_UUID', 'APP_KEY', 'APP_ENCRYPTION_KEY'] as $keyName) {
            if ($env_keys[$keyName] !== $php_keys[$keyName]) {
                $mismatches[$keyName] = [
                    'env' => $env_keys[$keyName],
                    'php' => $php_keys[$keyName],
                ];
            }
        }
        return $mismatches;
    }
    
    /**
     * Handle key mismatch between .env and PHP config
     * @return bool True to continue, false to abort
     */
    private function handle_key_mismatch(array $mismatches, array $env_keys, array $php_keys): bool
    {
        $this->output->writeln();
        $this->output->writeln('  ' . Style::warning_label() . ' ' . Style::bold('Key mismatch detected!'));
        $this->output->writeln();
        $this->output->writeln('  The following application keys differ between .env and config/app.php:');
        $this->output->writeln();
        
        foreach ($mismatches as $keyName => $values) {
            $this->output->writeln("    {$keyName}:");
            $this->output->writeln("      .env:           " . $this->truncate_key($values['env']));
            $this->output->writeln("      config/app.php: " . $this->truncate_key($values['php']));
            $this->output->writeln();
        }
        
        $this->output->writeln('  ' . Style::warning_label() . ' ' . Style::bold('WARNING: Changing keys can break existing functionality!'));
        $this->output->writeln();
        $this->output->writeln('  The framework internally relies on these keys for:');
        $this->output->writeln('    - Session encryption and validation');
        $this->output->writeln('    - Access token generation and verification');
        $this->output->writeln('    - Encrypted data storage');
        $this->output->writeln();
        $this->output->writeln('  If you regenerate keys, existing sessions will be invalidated,');
        $this->output->writeln('  encrypted data may become unreadable, and access tokens will fail.');
        $this->output->writeln();
        
        if (!$this->input->is_interactive()) {
            $this->output->writeln('  ' . Style::error_label() . ' Non-interactive mode: cannot resolve key mismatch.');
            $this->output->writeln('  Run: php atomic init/key --force to regenerate all keys, or manually sync them.');
            return false;
        }
        
        $this->output->prompt('  Choose action [env/php/abort]: ');
        $this->output->writeln();
        $this->output->writeln('    env   - Use keys from .env (overwrite config/app.php)');
        $this->output->writeln('    php   - Use keys from config/app.php (overwrite .env)');
        $this->output->writeln('    abort - Cancel and manually resolve');
        $this->output->writeln();
        $this->output->prompt('  Your choice: ');
        
        $choice = strtolower(trim($this->input->read_line()));
        
        if ($choice === '' || $choice === 'env') {
            $this->write_keys_to_php_config(ATOMIC_DIR, $env_keys);
            $this->output->writeln();
            $this->output->writeln('  ' . Style::success_label() . ' Keys synchronized: .env → config/app.php');
            return true;
        }
        
        if ($choice === 'php') {
            $this->write_keys_to_env(ATOMIC_DIR, $php_keys);
            $this->output->writeln();
            $this->output->writeln('  ' . Style::success_label() . ' Keys synchronized: config/app.php → .env');
            return true;
        }
        
        $this->output->writeln();
        $this->output->writeln('  Initialization aborted. To regenerate all keys, run:');
        $this->output->writeln('    php atomic init/key');
        $this->output->writeln();
        $this->output->writeln('  ' . Style::warning_label() . ' Remember: regenerating keys will invalidate existing');
        $this->output->writeln('  sessions, encrypted data, and access tokens!');
        return false;
    }
    
    private function truncate_key(string $key): string
    {
        if (strlen($key) <= 16) {
            return $key;
        }
        return substr($key, 0, 8) . '...' . substr($key, -4);
    }
    
    private function write_keys_to_env(string $root, array $keys): void
    {
        $env_path = $root . DIRECTORY_SEPARATOR . '.env';
        
        if (!file_exists($env_path)) {
            $examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';
            if (!file_exists($examplePath)) {
                $this->output->err('  ' . Style::error_label() . ' Cannot write keys: no .env file and no .env.example found.');
                $this->output->err('  Create .env.example first or manually create .env file.');
                return;
            }
            @copy($examplePath, $env_path);
        }
        
        $contents = (string)file_get_contents($env_path);
        
        foreach ($keys as $keyName => $keyValue) {
            $pattern = '/^' . preg_quote($keyName, '/') . '=.*$/m';
            $line = "{$keyName}={$keyValue}";
            
            if (preg_match($pattern, $contents) === 1) {
                $contents = (string)preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
            }
        }
        
        file_put_contents($env_path, $contents);
    }
    
    private function write_keys_to_php_config(string $root, array $keys): void
    {
        $configPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        
        if (!file_exists($configPath)) {
            $this->output->writeln('  ' . Style::warning_label() . ' Skipping PHP config: config/app.php does not exist.');
            return;
        }
        
        $content = (string)file_get_contents($configPath);
        
        $keyMap = [
            'APP_UUID' => 'uuid',
            'APP_KEY' => 'key',
            'APP_ENCRYPTION_KEY' => 'encryption_key',
        ];
        
        $updated = false;
        foreach ($keys as $keyName => $keyValue) {
            $configKey = $keyMap[$keyName] ?? null;
            if ($configKey === null) {
                continue;
            }
            
            $pattern = "/(['\"]){$configKey}\\1\\s*=>\\s*['\"][^'\"]*['\"]/";
            
            if (preg_match($pattern, $content)) {
                $replacement = "'{$configKey}' => '{$keyValue}'";
                $content = preg_replace($pattern, $replacement, $content, 1);
                $updated = true;
            } else {
                $this->output->err('  ' . Style::error_label() . " Cannot update {$keyName}: key '{$configKey}' not found in config/app.php");
                $this->output->err("  Add '{$configKey}' to the config array manually.");
            }
        }
        
        if ($updated) {
            file_put_contents($configPath, $content);
        }
    }

    public function init_guide(): void
    {
        $o   = $this->output;
        $sep = str_repeat('─', 60);

        $o->writeln();
        $o->writeln('  ' . Style::bold('Atomic Framework - Manual Setup Guide'));
        $o->writeln('  ' . Style::bold('A step-by-step replacement for: php atomic init'));
        $o->writeln('  ' . $sep);
        $o->writeln('  Follow every section in order. All paths are relative to');
        $o->writeln('  your project root (where the public/ directory lives).');
        $o->writeln();

        // ── STEP 1 ────────────────────────────────────────────────────────
        $o->writeln('  ' . Style::yellow('[STEP 1 / 4]', true) . ' Create the directory structure');
        $o->writeln('  ' . $sep);
        $o->writeln();
        $o->writeln('  Application directories - permission 0755, owned by your deploy user:');
        $o->writeln();

        $o->writeln('  Runtime directories - permission ' . Style::yellow('0775', true) . ', must be writable by the web server:');
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
        $o->writeln('    sudo -u <web-user> test -w storage/logs       && echo "storage/logs - OK"');
        $o->writeln('    sudo -u <web-user> test -w public/uploads     && echo "public/uploads - OK"');
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
        $o->writeln('  ' . Style::warning_label() . ' Never commit .env to version control. Add it to .gitignore.');
        $o->writeln();

        // 2-B php
        $o->writeln('  ' . Style::cyan('── Option B: PHP config files  (alternative)', true));
        $o->writeln();
        $o->writeln('  Edit config/app.php - set these keys inside the returned array:');
        $o->writeln();
        $o->writeln("    'uuid' => '<uuid_v4>',          // generate: see APP_UUID above");
        $o->writeln("    'name' => 'YourApp',");
        $o->writeln("    'key'  => '<32 hex chars>',     // generate: see APP_KEY above");
        $o->writeln();
        $o->writeln('  ' . Style::warning_label() . ' APP_ENCRYPTION_KEY is mirrored in config/app.php as "encryption_key".');
        $o->writeln('  Keep the sodium key secret and avoid committing it to the repository.');
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
        $o->writeln('       DB_CHARSET=utf8mb4       # Recommended - optional');
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
        $o->writeln('  ' . Style::warning_label() . ' Requirements:');
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
        $o->writeln('  ' . Style::success_label() . '  Setup complete - final checklist:');
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
    public function logs_rotate(): void
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
                $this->output->err(Style::warning_label() . " could not delete {$old}: {$err}");
            }
        }

        $this->output->writeln(Style::success_label() . " Rotated {$deleted} log file" . ($deleted === 1 ? '' : 's') . ".");
    }
}
