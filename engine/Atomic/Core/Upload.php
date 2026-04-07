<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Models\Meta;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Filesystem as FS;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Theme\Theme as AT;

class Upload
{
    protected ?App $atomic = null;
    protected string $uploadPath;
    protected string $uploadSystem;
    protected string $uploadUser;
    private static ?self $instance = null;

    private const META_ACCESS_TOKEN_PREFIX = 'file_access_token:';
    private const META_USER_TOKEN_PREFIX = 'upload_user_token:';
    private const META_PROJECT_TOKEN_PREFIX = 'upload_project_token:';

    protected function __construct()
    {
        $this->atomic = App::instance();
        $this->uploadPath = $this->atomic->get('UPLOADS');
        $this->uploadSystem = $this->uploadPath . 'system' . DIRECTORY_SEPARATOR;
        $this->uploadUser = $this->uploadPath . 'user' . DIRECTORY_SEPARATOR;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function getUploadPath(): string
    {
        return $this->uploadPath;
    }

    public function getSystemUploadPath(): string
    {
        return $this->uploadSystem;
    }

    public function getUserUploadPath(): string
    {
        return $this->uploadUser;
    }

    public function generateAccessToken(int|string $id, string $uuid, int $length = 6): string
    {
        return ID::generate_access_token($id, $uuid, $length);
    }

    public function verifyAccessToken(string $token, string $uuid): bool
    {
        return ID::verify_access_token($token, $uuid);
    }

    public function storeAccessToken(string $uuid, string $filename, string $token): bool
    {
        $key = self::META_ACCESS_TOKEN_PREFIX . $filename;
        return Meta::set_meta($uuid, $key, $token);
    }

    public function getStoredAccessToken(string $uuid, string $filename): string|false
    {
        $key = self::META_ACCESS_TOKEN_PREFIX . $filename;
        return Meta::get_meta($uuid, $key);
    }

    public function createAndStoreAccessToken(int|string $id, string $uuid, string $filename, int $length = 6): string
    {
        $token = $this->generateAccessToken($id, $uuid, $length);
        $this->storeAccessToken($uuid, $filename, $token);
        return $token;
    }

    public function getOrCreateUserToken(int|string $userId, string $userUuid, int $length = 12): string
    {
        $metaKey = self::META_USER_TOKEN_PREFIX . $userId;
        $existingToken = Meta::get_meta($userUuid, $metaKey);
        
        if ($existingToken && is_string($existingToken)) {
            return $existingToken;
        }
        
        $token = ID::generate_access_token($userId, $userUuid, $length);
        Meta::set_meta($userUuid, $metaKey, $token);
        
        return $token;
    }

    public function getOrCreateProjectToken(int|string $projectId, string $projectUuid, int $length = 12): string
    {
        $metaKey = self::META_PROJECT_TOKEN_PREFIX . $projectId;
        $existingToken = Meta::get_meta($projectUuid, $metaKey);
        
        if ($existingToken && is_string($existingToken)) {
            return $existingToken;
        }
        
        $token = ID::generate_access_token($projectId, $projectUuid, $length);
        Meta::set_meta($projectUuid, $metaKey, $token);
        
        return $token;
    }

    public function uploadUserFile(string $userId, string $userUuid, string $projectId, string $projectUuid, array $file): array
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        $projectToken = $this->getOrCreateProjectToken($projectId, $projectUuid);
        
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        $projectDir = $userDir . $projectToken . DIRECTORY_SEPARATOR;
        $srcDir = $projectDir . 'src' . DIRECTORY_SEPARATOR;
        $distDir = $projectDir . 'dist' . DIRECTORY_SEPARATOR;

        if (!is_dir($userDir) && !FS::instance()->makeDir($userDir)) {
            return ['ok' => false, 'error' => 'Failed to create user directory'];
        }

        if (!is_dir($projectDir) && !FS::instance()->makeDir($projectDir)) {
            return ['ok' => false, 'error' => 'Failed to create project directory'];
        }

        if (!is_dir($srcDir) && !FS::instance()->makeDir($srcDir)) {
            return ['ok' => false, 'error' => 'Failed to create src directory'];
        }

        if (!is_dir($distDir) && !FS::instance()->makeDir($distDir)) {
            return ['ok' => false, 'error' => 'Failed to create dist directory'];
        }

        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid uploaded file'];
        }

        $originalName = basename($file['name']);
        $slugName = $this->generateUniqueSlugName($srcDir, $originalName);
        $srcPath = $srcDir . $slugName;

        if (is_uploaded_file($file['tmp_name'])) {
            if (!move_uploaded_file($file['tmp_name'], $srcPath)) {
                return ['ok' => false, 'error' => 'Failed to move uploaded file'];
            }
        } else {
            if (!copy($file['tmp_name'], $srcPath)) {
                return ['ok' => false, 'error' => 'Failed to copy file'];
            }
        }

        $srcUrl = $this->buildPublicUrl($srcPath);
        $distUrl = $this->buildPublicUrl($distDir . $slugName);

        if ($srcUrl === false || $distUrl === false) {
            return ['ok' => false, 'error' => 'Failed to generate public URLs'];
        }

        // TODO: Save $slugName to database 
        
        return [
            'ok' => true,
            'filename' => $slugName,
            'src_path' => $srcPath,
            'src_url' => $srcUrl,
            'dist_path' => $distDir . $slugName,
            'dist_url' => $distUrl,
            'size' => filesize($srcPath),
            'mime' => $this->getMimeType($srcPath)
        ];
    }

    protected function generateUniqueSlugName(string $dir, string $filename): string
    {
        $pathInfo = pathinfo($filename);
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $basename = $pathInfo['filename'];
        
        $slug = \Web::instance()->slug($basename);
        $finalName = $slug . $extension;
        $counter = 1;

        while (file_exists($dir . $finalName)) {
            $finalName = $slug . '-' . $counter . $extension;
            $counter++;
        }

        return $finalName;
    }

    public function deleteFile(string $userId, string $userUuid, string $projectId, string $projectUuid, string $filename): bool
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        $projectToken = $this->getOrCreateProjectToken($projectId, $projectUuid);
        
        $projectDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR . $projectToken . DIRECTORY_SEPARATOR;
        $srcFile = $projectDir . 'src' . DIRECTORY_SEPARATOR . $filename;
        
        $distDir = $projectDir . 'dist' . DIRECTORY_SEPARATOR;
        $variants = ['optimized', 'thumbnails'];
        
        $srcDeleted = !file_exists($srcFile) || unlink($srcFile);
        $allDistDeleted = true;
        
        foreach ($variants as $variant) {
            $distFile = $distDir . $variant . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($distFile) && !unlink($distFile)) {
                $allDistDeleted = false;
            }
        }

        return $srcDeleted && $allDistDeleted;
    }

    public function deleteProjectFiles(string $userId, string $userUuid, string $projectId, string $projectUuid): bool
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        $projectToken = $this->getOrCreateProjectToken($projectId, $projectUuid);
        
        $projectDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR . $projectToken . DIRECTORY_SEPARATOR;
        
        if (!is_dir($projectDir)) {
            return true;
        }

        try {
            FS::instance()->removeDir($projectDir . 'src', true);
            FS::instance()->removeDir($projectDir . 'dist', true);
            FS::instance()->removeDir($projectDir, false);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to delete project files: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteUserFiles(string $userId, string $userUuid): bool
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        
        if (!is_dir($userDir)) {
            return true;
        }

        try {
            FS::instance()->removeDir($userDir, true);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to delete user files: ' . $e->getMessage());
            return false;
        }
    }

    protected function buildPublicUrl(string $filePath): string|false
    {
        $base = rtrim(str_replace('\\', '/', $this->uploadPath), '/');
        $file = str_replace('\\', '/', $filePath);

        if (!str_starts_with($file, $base . '/')) {
            return false;
        }

        $relative = substr($file, strlen($base) + 1);
        return rtrim(AT::instance()->getPublicUrl(), '/') . '/uploads/' . $relative;
    }

    public function getFileInfo(string $userId, string $userUuid, string $projectId, string $projectUuid, string $filename, string $variant = 'optimized'): array|false
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        $projectToken = $this->getOrCreateProjectToken($projectId, $projectUuid);
        
        $projectDir = realpath($this->uploadUser . $userToken . DIRECTORY_SEPARATOR . $projectToken) . DIRECTORY_SEPARATOR;
        $srcFile = $projectDir . 'src' . DIRECTORY_SEPARATOR . $filename;
        $distFile = $projectDir . 'dist' . DIRECTORY_SEPARATOR . $variant . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($srcFile)) {
            return false;
        }

        $srcUrl = $this->buildPublicUrl($srcFile);
        $dist_exists = file_exists($distFile);
        $distUrl = $dist_exists ? $this->buildPublicUrl($distFile) : null;

        if ($srcUrl === false) {
            return false;
        }

        return [
            'filename' => $filename,
            'variant' => $variant,
            'src_path' => $srcFile,
            'src_url' => $srcUrl,
            'dist_path' => $distFile,
            'dist_url' => $distUrl,
            'dist_exists' => $dist_exists,
            'size' => filesize($srcFile),
            'mime' => $this->getMimeType($srcFile)
        ];
    }

    protected function getMimeType(string $file): string
    {
        return \Web::instance()->mime($file, true);
    }

    public function downloadImageFromUrl(string $url, string $destinationDir, string $defaultFilename = 'image.jpg'): array
    {
        $imageData = @file_get_contents($url);
        if ($imageData === false) {
            return ['ok' => false, 'error' => 'Failed to download file from URL'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_');
        if ($tmpFile === false) {
            return ['ok' => false, 'error' => 'Failed to create temp file'];
        }

        if (@file_put_contents($tmpFile, $imageData) === false) {
            @unlink($tmpFile);
            return ['ok' => false, 'error' => 'Failed to write to temp file'];
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        $originalName = basename($urlPath);
        
        if (empty($originalName)) {
            $originalName = $defaultFilename;
        }

        if (!is_dir($destinationDir) && !FS::instance()->makeDir($destinationDir)) {
            @unlink($tmpFile);
            return ['ok' => false, 'error' => 'Failed to create destination directory'];
        }

        $slugName = $this->generateUniqueSlugName($destinationDir, $originalName);
        $filePath = $destinationDir . $slugName;

        if (!copy($tmpFile, $filePath)) {
            @unlink($tmpFile);
            return ['ok' => false, 'error' => 'Failed to save file'];
        }

        @unlink($tmpFile);

        $fileUrl = $this->buildPublicUrl($filePath);

        if ($fileUrl === false) {
            @unlink($filePath);
            return ['ok' => false, 'error' => 'Failed to generate public URL'];
        }

        return [
            'ok' => true,
            'filename' => $slugName,
            'src_path' => $filePath,
            'url' => $fileUrl,
            'size' => filesize($filePath),
            'mime' => $this->getMimeType($filePath)
        ];
    }

    public function downloadUserImage(string $userId, string $userUuid, string $projectId, string $projectUuid, string $url, string $defaultFilename = 'image.jpg'): array
    {
        $userToken = $this->getOrCreateUserToken($userId, $userUuid);
        $projectToken = $this->getOrCreateProjectToken($projectId, $projectUuid);
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        $projectDir = $userDir . $projectToken . DIRECTORY_SEPARATOR;
        $srcDir = $projectDir . 'src' . DIRECTORY_SEPARATOR;

        $result = $this->downloadImageFromUrl($url, $srcDir, $defaultFilename);
        
        return $result;
    }

    public function downloadSystemFile(string $filename): array
    {
        $systemFile = $this->uploadSystem . $filename;
        
        if (!file_exists($systemFile)) {
            return [
                'ok' => false,
                'error' => 'File not found'
            ];
        }

        if (!is_readable($systemFile)) {
            return [
                'ok' => false,
                'error' => 'File is not readable'
            ];
        }

        $realPath = realpath($systemFile);
        $realSystemPath = realpath($this->uploadSystem);

        if ($realPath === false || $realSystemPath === false) {
            return [
                'ok' => false,
                'error' => 'Invalid file path'
            ];
        }

        if (strpos($realPath, $realSystemPath) !== 0) {
            return [
                'ok' => false,
                'error' => 'Access denied - file must be in system directory'
            ];
        }

        return [
            'ok' => true,
            'path' => $realPath,
            'filename' => basename($systemFile),
            'mime' => $this->getMimeType($realPath),
            'size' => filesize($realPath)
        ];
    }
}