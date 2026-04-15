<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;
 
if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;

class Filesystem
{
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function copy(string $source, string $destination): bool
    {
       return $this->atomic->copy($source, $destination);
    }

    public function move(string $source, string $destination): bool
    {
        return $this->atomic->move($source, $destination);
    }

    public function delete(string $file): bool
    {
        if (!file_exists($file)) return false;
        return unlink($file);
    }

    public function read(string $file): string|false
    {
        return $this->atomic->read($file);
    }

    public function created_time(string $file): int|false
    {
        return filectime($file);
    }

    public function modified_time(string $file): int|false
    {
        return filemtime($file);
    }

    public function filesize(string $file): int|false
    {
        return filesize($file);
    }

    public function count_lines(string $file, int $chunk_size = 65536): int|false
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) return false;

        $count = 0;
        $remainder = '';

        while (!feof($fh)) {
            $chunk = fread($fh, $chunk_size);
            if ($chunk === false) break;

            $data = $remainder . $chunk;
            $lines = explode("\n", $data);
            $remainder = array_pop($lines);

            foreach ($lines as $line) {
                if (rtrim($line, "\r") !== '') {
                    $count++;
                }
            }
        }

        if (rtrim($remainder, "\r") !== '') {
            $count++;
        }

        fclose($fh);
        return $count;
    }

    public function read_lines_from_end(string $file, int $offset, int $limit, int $chunk_size = 8192): array|false
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) return false;

        fseek($fh, 0, SEEK_END);
        $pos = ftell($fh);
        $remainder = '';
        $result = [];
        $seen = 0;
        $collected = 0;

        while ($pos > 0 && $collected < $limit) {
            $read_size = min($chunk_size, $pos);
            $pos -= $read_size;
            fseek($fh, $pos);
            $chunk = fread($fh, $read_size);

            if ($seen < $offset && $remainder === '') {
                $nl_count = substr_count($chunk, "\n");
                if ($nl_count > 0 && $chunk[-1] === "\n") $nl_count--;
                if ($seen + $nl_count <= $offset) {
                    $seen += $nl_count;
                    $nl_pos = strpos($chunk, "\n");
                    $remainder = $nl_pos !== false ? substr($chunk, 0, $nl_pos) : $chunk;
                    continue;
                }
            }

            $chunk .= $remainder;
            $parts = explode("\n", $chunk);
            $remainder = $parts[0];

            for ($i = count($parts) - 1; $i >= 1; $i--) {
                $line = rtrim($parts[$i], "\r");
                if ($line === '') continue;

                if ($seen >= $offset) {
                    $result[] = $line;
                    if (++$collected >= $limit) break 2;
                }
                $seen++;
            }
        }

        if ($collected < $limit && $remainder !== '') {
            $line = rtrim($remainder, "\r");
            if ($line !== '' && $seen >= $offset) {
                $result[] = $line;
            }
        }

        fclose($fh);
        return $result;
    }

    public function write(string $file, mixed $data, bool $append): int|false
    {
        return $this->atomic->write($file, $data, $append);
    }

    public function exists(string $file): bool
    {
        return file_exists($file);
    }

    public function is_file(string $file): bool
    {
        return is_file($file);
    }

    public function rename(string $oldName, string $newName): bool
    {
        if (!file_exists($oldName)) return false;
        return rename($oldName, $newName);
    }

    public function makeDir(string $path, int $permissions = 0755, bool $recursive = true): bool
    {
        return mkdir($path, $permissions, $recursive);
    }

    public function removeDir(string $path, bool $recursive = false): bool
    {
        if ($recursive) {
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                $fullPath = $path . DIRECTORY_SEPARATOR . $file;
                if (is_dir($fullPath)) {
                    $this->removeDir($fullPath, true);
                } else {
                    unlink($fullPath);
                }
            }
        }
        return rmdir($path);
    }

    public function listFiles( string $folder = '', int $levels = 100, array $exclusions = [], bool $include_hidden = false ): array|false
    {
        if ( empty( $folder ) ) {
            return false;
        }
        if ( ! $levels ) {
            return false;
        }
        $files = array();
        $dir = @opendir( $folder );
        if ( $dir ) {
            while ( ( $file = readdir( $dir ) ) !== false ) {
                if ( in_array( $file, array( '.', '..' ), true ) ) {
                    continue;
                }
                if ( ( ! $include_hidden && '.' === $file[0] ) || in_array( $file, $exclusions, true ) ) {
                    continue;
                }
                if ( is_dir( $folder . $file ) ) {
                    $files2 = $this->listFiles( $folder . $file . DIRECTORY_SEPARATOR, $levels - 1, array(), $include_hidden );
                    if ( $files2 ) {
                        $files = array_merge( $files, $files2 );
                    } else {
                        $files[] = $folder . $file . '/';
                    }
                } else {
                    $files[] = $folder . $file;
                }
            }

            closedir( $dir );
        }
        return $files;
    }

    public function copy_dir(string $source, string $destination): bool
    {
        $source = rtrim($source, '/\\') . DIRECTORY_SEPARATOR;
        $destination = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($source)) {
            Log::error("Source directory does not exist: " . $source);
            return false;
        }

        if (!is_dir($destination) && !$this->makeDir($destination)) {
            Log::error("Failed to create destination directory: " . $destination);
            return false;
        }

        $items = array_diff(scandir($source), ['.', '..']);
        foreach ($items as $item) {
            $srcPath = $source . $item;
            $destPath = $destination . $item;

            if (is_dir($srcPath)) {
                if (!$this->copy_dir($srcPath, $destPath)) {
                    return false;
                }
            } else {
                if (!copy($srcPath, $destPath)) {
                    Log::error("Failed to copy file: " . $srcPath . " to " . $destPath);
                    return false;
                }
            }
        }
        return true;
    }

    public function get_temp_dir(): string
    {
        return $this->atomic->get('TEMP');
    }

    public function unzip_file(string $zipFile, string $extractTo): bool
    {
        if (!class_exists('ZipArchive')) {
            Log::error("ZipArchive class is not available.");
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) === true) {
            if (!is_dir($extractTo) && !$this->makeDir($extractTo, 0755, true)) {
                Log::error("Failed to create extraction directory: " . $extractTo);
                $zip->close();
                return false;
            }
            $zip->extractTo($extractTo);
            $zip->close();
            return true;
        } else {
            Log::error("Failed to open zip file: " . $zipFile);
            return false;
        }
    }

    public function zip_files(array $files, string $zipFile, ?string $baseDir = null): bool
    {
        if (!class_exists('ZipArchive')) {
            Log::error("ZipArchive class is not available.");
            return false;
        }

        if (empty($files)) {
            Log::error("Files array is empty.");
            return false;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            Log::error("Failed to create zip file: " . $zipFile . " (Error code: " . $result . ")");
            return false;
        }

        $addedCount = 0;
        
        foreach ($files as $file) {
            $file = $this->normalizePath($file);
            
            if (!file_exists($file)) {
                Log::warning("File does not exist, skipping: " . $file);
                continue;
            }

            if ($baseDir !== null) {
                $baseDir = $this->normalizePath($baseDir);
                if (str_starts_with($file, $baseDir)) {
                    $localName = ltrim(substr($file, strlen($baseDir)), DIRECTORY_SEPARATOR);
                } else {
                    $localName = basename($file);
                }
            } else {
                $localName = basename($file);
            }

            if (is_file($file)) {
                if ($zip->addFile($file, $localName)) {
                    $addedCount++;
                } else {
                    Log::warning("Failed to add file to archive: " . $file);
                }
            } elseif (is_dir($file)) {
                if (!$this->addDirectoryToZip($zip, $file, $localName)) {
                    Log::warning("Failed to add directory to archive: " . $file);
                } else {
                    $addedCount++;
                }
            }
        }

        $zip->close();

        if ($addedCount === 0) {
            Log::error("No files were added to the archive.");
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
            return false;
        }

        Log::info("Successfully created zip archive with " . $addedCount . " items: " . $zipFile);
        return true;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $localPath): bool
    {
        $dirPath = rtrim($this->normalizePath($dirPath), DIRECTORY_SEPARATOR);
        $localPath = trim($localPath, '/\\');
        
        if (!is_dir($dirPath)) {
            return false;
        }

        $zip->addEmptyDir($localPath);
        $items = array_diff(scandir($dirPath), ['.', '..']);
        
        foreach ($items as $item) {
            $itemPath = $dirPath . DIRECTORY_SEPARATOR . $item;
            $itemLocalPath = $localPath . '/' . $item;

            if (is_file($itemPath)) {
                if (!$zip->addFile($itemPath, $itemLocalPath)) {
                    Log::warning("Failed to add file to zip: " . $itemPath);
                    return false;
                }
            } elseif (is_dir($itemPath)) {
                if (!$this->addDirectoryToZip($zip, $itemPath, $itemLocalPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function isAbsolutePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        // Extract root prefix explicitly so it is never reconstructed incorrectly.
        // Supported roots: UNC (//server), Windows drive (C:/), Unix (/).
        $root = '';
        if (preg_match('#^([A-Za-z]:/)#', $path, $m)) {
            $root = $m[1];          // e.g. "C:/"
            $path = substr($path, 3);
        } elseif (str_starts_with($path, '//')) {
            $root = '//';           // UNC or WSL UNC
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '/')) {
            $root = '/';
            $path = substr($path, 1);
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); }
            else                { $parts[] = $part; }
        }

        return $root . implode('/', $parts);
    }

    public function joinPaths(string ...$paths): string
    {
        $filteredPaths = array_filter($paths, fn($p) => $p !== '');
        return $this->normalizePath(implode(DIRECTORY_SEPARATOR, $filteredPaths));
    }
}