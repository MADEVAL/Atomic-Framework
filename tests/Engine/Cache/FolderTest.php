<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Drivers\Folder;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Helpers\Payload;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TempPath;
use Tests\Support\Wait;

class FolderTest extends TestCase
{
    use CacheStoreContractTrait;

    private string $path;
    private string $namespace;

    protected function setUp(): void
    {
        $this->path = TempPath::make_dir('atomic_folder_cache_');
        $this->namespace = 'atomic_test_folder_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->path);
    }

    public function test_set_and_get_scalar_and_array_values(): void
    {
        $cache = new Folder($this->path, $this->namespace);

        $this->assertTrue($cache->set('scalar', 'value', 60));
        $this->assertSame('value', $cache->get('scalar'));

        $payload = ['a' => 1, 'b' => ['nested' => true]];
        $this->assertTrue($cache->set('array', $payload, 60));
        $this->assertSame($payload, $cache->get('array'));
    }

    public function test_missing_key_returns_false(): void
    {
        $this->assertFalse((new Folder($this->path, $this->namespace))->get('missing'));
    }

    public function test_empty_path_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Folder cache path cannot be empty.');

        new Folder('', $this->namespace);
    }

    public function test_exists_fills_value_and_returns_metadata(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('exists', ['ok' => true], 60);

        $meta = $cache->exists('exists', $value);

        $this->assertIsArray($meta);
        $this->assertSame(['ok' => true], $value);
        $this->assertIsFloat($meta[0]);
        $this->assertGreaterThan(0, $meta[1]);
    }

    public function test_set_writes_cache_file_under_two_character_shard_directory(): void
    {
        $cache = new Folder($this->path, $this->namespace);

        $this->assertTrue($cache->set('sharded', 'value', 60));

        $file = $this->keyFile($cache, 'sharded');
        $this->assertFileExists($file);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{2}$/', basename(dirname($file)));
        $this->assertSame(basename(dirname($file)), substr(basename($file), 0, 2));
        $this->assertFileDoesNotExist(dirname(dirname($file)) . DIRECTORY_SEPARATOR . basename($file));
    }

    public function test_folder_driver_is_prunable(): void
    {
        $this->assertInstanceOf(PrunableCacheStoreInterface::class, new Folder($this->path, $this->namespace));
    }

    public function test_folder_driver_is_purgeable(): void
    {
        $this->assertInstanceOf(PurgeableCacheStoreInterface::class, new Folder($this->path, $this->namespace));
    }

    public function test_ttl_expiry_deletes_on_read(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('ttl', 'gone', 1);
        $file = $this->keyFile($cache, 'ttl');

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));
        $this->assertFileDoesNotExist($file);
    }

    public function test_overwrite_after_expiry_replaces_same_key_file(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('ttl', 'short', 1);
        $file = $this->keyFile($cache, 'ttl');

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));

        $this->assertTrue($cache->set('ttl', 'fresh', 60));
        $this->assertSame('fresh', $cache->get('ttl'));
        $this->assertFileExists($file);
    }

    public function test_clear_removes_current_generation_key_only(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('one', '1', 60);
        $cache->set('two', '2', 60);

        $this->assertTrue($cache->clear('one'));
        $this->assertFalse($cache->get('one'));
        $this->assertSame('2', $cache->get('two'));
        $this->assertFalse($cache->clear('one'));
    }

    public function test_leading_dot_keys_resolve_to_same_sharded_file(): void
    {
        $cache = new Folder($this->path, $this->namespace);

        $this->assertSame($this->keyFile($cache, '.same'), $this->keyFile($cache, 'same'));
        $this->assertTrue($cache->set('.same', 'value', 60));
        $this->assertSame('value', $cache->get('same'));
        $this->assertTrue($cache->clear('same'));
        $this->assertFalse($cache->get('.same'));
    }

    public function test_legacy_flat_cache_files_are_ignored_by_current_operations(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $sharded_file = $this->keyFile($cache, 'legacy');
        $legacy_file = dirname(dirname($sharded_file)) . DIRECTORY_SEPARATOR . basename($sharded_file);
        $value = 'unchanged';

        $this->writeCacheFile($legacy_file, Payload::pack('legacy-value', 60));

        $this->assertFalse($cache->get('legacy'));
        $this->assertFalse($cache->exists('legacy', $value));
        $this->assertSame('unchanged', $value);
        $this->assertFalse($cache->clear('legacy'));
        $this->assertFileExists($legacy_file);
    }

    public function test_reset_advances_generation_and_makes_old_files_invisible(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('one', '1', 60);
        $old_file = $this->keyFile($cache, 'one');

        $this->assertTrue($cache->reset());

        $this->assertSame(2, $cache->get_generation());
        $this->assertFalse($cache->get('one'));
        $this->assertFileExists($old_file);
    }

    public function test_purge_deletes_cache_files_and_keeps_metadata_files(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('one', '1', 60);
        $cache->set('two', '2', 60);
        $root = $this->cacheRoot($cache);

        $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'namespace.meta');
        $this->assertSame(2, $cache->purge());
        $this->assertCount(0, glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'namespace.meta');
        $this->assertFalse($cache->get('one'));
        $this->assertFalse($cache->get('two'));
    }

    public function test_concurrent_resets_each_advance_generation_once(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for concurrent folder cache reset coverage.');
        }

        $workers = 6;
        $cache = new Folder($this->path, $this->namespace);
        $this->assertSame(1, $cache->get_generation());

        $start_file = $this->path . DIRECTORY_SEPARATOR . 'start-resets';
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork child process for concurrent reset test.');
            }

            if ($pid === 0) {
                if (!Wait::until(fn (): bool => is_file($start_file), 4, 1_000)) {
                    exit(1);
                }

                $child_cache = new Folder($this->path, $this->namespace);
                exit($child_cache->reset() ? 0 : 1);
            }

            $pids[] = $pid;
        }

        touch($start_file);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status), "Child {$pid} did not exit normally.");
            $this->assertSame(0, pcntl_wexitstatus($status), "Child {$pid} failed to reset cache generation.");
        }

        $fresh_cache = new Folder($this->path, $this->namespace);
        $this->assertSame(1 + $workers, $fresh_cache->get_generation());
    }

    public function test_corrupt_payload_returns_false_and_deletes_file(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $cache->set('bad', 'value', 60);
        $file = $this->keyFile($cache, 'bad');
        file_put_contents($file, 'not serialized payload');

        $this->assertFalse($cache->get('bad'));
        $this->assertFileDoesNotExist($file);
    }

    public function test_path_traversal_key_does_not_escape_cache_directory(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $escape = dirname($this->path) . DIRECTORY_SEPARATOR . 'escape';

        $this->assertTrue($cache->set('../escape', 'safe', 60));

        $this->assertFileDoesNotExist($escape);
        $root = $this->cacheRoot($cache);
        $this->assertCount(1, glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.cache') ?: []);
    }

    public function test_colon_is_not_treated_as_a_separator(): void
    {
        $cache = new Folder($this->path, $this->namespace);

        $this->assertNotSame($this->keyFile($cache, ':same'), $this->keyFile($cache, 'same'));
        $this->assertTrue($cache->set(':same', 'colon', 60));
        $this->assertTrue($cache->set('same', 'plain', 60));

        $this->assertSame('colon', $cache->get(':same'));
        $this->assertSame('plain', $cache->get('same'));
    }

    public function test_prune_deletes_expired_and_corrupt_sharded_files_only(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $root = $this->cacheRoot($cache);
        $valid = $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . '00valid.cache';
        $expired = $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . '00expired.cache';
        $corrupt = $root . DIRECTORY_SEPARATOR . '01' . DIRECTORY_SEPARATOR . '01corrupt.cache';
        $legacy = $root . DIRECTORY_SEPARATOR . 'legacy.cache';

        $this->writeCacheFile($valid, Payload::pack('valid', 60));
        $this->writeCacheFile($expired, $this->expiredPayload('expired'));
        $this->writeCacheFile($corrupt, 'not serialized payload');
        $this->writeCacheFile($legacy, $this->expiredPayload('legacy'));

        ReflectionHelper::invoke($cache, 'prune_expired');

        $this->assertFileExists($valid);
        $this->assertFileDoesNotExist($expired);
        $this->assertFileDoesNotExist($corrupt);
        $this->assertFileExists($legacy);
    }

    public function test_prune_deletes_no_more_than_delete_limit(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $root = $this->cacheRoot($cache);

        for ($i = 0; $i < 25; $i++) {
            $this->writeCacheFile(
                $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . sprintf('00expired%02d.cache', $i),
                $this->expiredPayload('expired')
            );
        }

        ReflectionHelper::invoke($cache, 'prune_expired');

        $this->assertCount(5, glob($root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . '*.cache') ?: []);
    }

    public function test_prune_scans_no_more_than_scan_limit(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $root = $this->cacheRoot($cache);
        $late_corrupt = $root . DIRECTORY_SEPARATOR . 'ff' . DIRECTORY_SEPARATOR . 'ffcorrupt.cache';

        for ($i = 0; $i < 100; $i++) {
            $this->writeCacheFile(
                $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . sprintf('00valid%03d.cache', $i),
                Payload::pack('valid', 60)
            );
        }

        $this->writeCacheFile($late_corrupt, 'not serialized payload');

        ReflectionHelper::invoke($cache, 'prune_expired');

        $this->assertFileExists($late_corrupt);
    }

    public function test_public_prune_scans_all_shards_without_bounded_gc_limits(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $root = $this->cacheRoot($cache);
        $late_corrupt = $root . DIRECTORY_SEPARATOR . 'ff' . DIRECTORY_SEPARATOR . 'ffcorrupt.cache';

        for ($i = 0; $i < 100; $i++) {
            $this->writeCacheFile(
                $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . sprintf('00valid%03d.cache', $i),
                Payload::pack('valid', 60)
            );
        }

        $this->writeCacheFile($late_corrupt, 'not serialized payload');

        $this->assertTrue($cache->prune());

        $this->assertFileDoesNotExist($late_corrupt);
    }

    public function test_bounded_prune_rotates_starting_shard(): void
    {
        $cache = new Folder($this->path, $this->namespace);
        $root = $this->cacheRoot($cache);
        $early_corrupt = $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . '00corrupt.cache';
        $late_corrupt = $root . DIRECTORY_SEPARATOR . '01' . DIRECTORY_SEPARATOR . '01corrupt.cache';

        for ($i = 0; $i < 100; $i++) {
            $this->writeCacheFile(
                $root . DIRECTORY_SEPARATOR . '00' . DIRECTORY_SEPARATOR . sprintf('00valid%03d.cache', $i),
                Payload::pack('valid', 60)
            );
        }

        $this->writeCacheFile($early_corrupt, 'not serialized payload');
        $this->writeCacheFile($late_corrupt, 'not serialized payload');

        ReflectionHelper::invoke($cache, 'prune_expired');

        $this->assertFileExists($late_corrupt);

        ReflectionHelper::invoke($cache, 'prune_expired');

        $this->assertFileDoesNotExist($late_corrupt);
    }

    private function keyFile(Folder $cache, string $key): string
    {
        return ReflectionHelper::invoke($cache, 'key_file', [$key]);
    }

    private function cacheRoot(Folder $cache): string
    {
        return ReflectionHelper::get($cache, 'path');
    }

    private function writeCacheFile(string $file, string $contents): void
    {
        $this->assertTrue(is_dir(dirname($file)) || mkdir(dirname($file), 0775, true));
        file_put_contents($file, $contents);
    }

    private function expiredPayload(mixed $value): string
    {
        $raw = json_decode(Payload::pack($value, 1), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_decode($raw['data'], true, flags: JSON_THROW_ON_ERROR);
        $payload['time'] = microtime(true) - 10;

        $data = json_encode($payload, JSON_THROW_ON_ERROR);
        $signing_key = new \ReflectionMethod(Payload::class, 'signing_key');

        $raw['data'] = $data;
        $raw['hmac'] = hash_hmac('sha256', $data, $signing_key->invoke(null));

        return json_encode($raw, JSON_THROW_ON_ERROR);
    }

    protected function newCacheStore(string $namespace): CacheStoreInterface
    {
        return new Folder($this->path, $namespace);
    }
}
