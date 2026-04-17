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

    public function get_upload_path(): string
    {
        return $this->uploadPath;
    }

    public function get_system_upload_path(): string
    {
        return $this->uploadSystem;
    }

    public function get_user_upload_path(): string
    {
        return $this->uploadUser;
    }

    public function generate_access_token(int|string $id, string $uuid, int $length = 6): string
    {
        return ID::generate_access_token($id, $uuid, $length);
    }

    public function verify_access_token(string $token, string $uuid): bool
    {
        return ID::verify_access_token($token, $uuid);
    }

    public function store_access_token(string $uuid, string $filename, string $token): bool
    {
        $key = self::META_ACCESS_TOKEN_PREFIX . $filename;
        return Meta::set_meta($uuid, $key, $token);
    }

    public function get_stored_access_token(string $uuid, string $filename): string|false
    {
        $key = self::META_ACCESS_TOKEN_PREFIX . $filename;
        return Meta::get_meta($uuid, $key);
    }

    public function create_and_store_access_token(int|string $id, string $uuid, string $filename, int $length = 6): string
    {
        $token = $this->generate_access_token($id, $uuid, $length);
        $this->store_access_token($uuid, $filename, $token);
        return $token;
    }

    public function get_or_create_user_token(int|string $user_id, string $user_uuid, int $length = 12): string
    {
        $metaKey = self::META_USER_TOKEN_PREFIX . $user_id;
        $existingToken = Meta::get_meta($user_uuid, $metaKey);
        
        if ($existingToken && is_string($existingToken)) {
            return $existingToken;
        }
        
        $token = ID::generate_access_token($user_id, $user_uuid, $length);
        Meta::set_meta($user_uuid, $metaKey, $token);
        
        return $token;
    }

    public function get_or_create_project_token(int|string $project_id, string $project_uuid, int $length = 12): string
    {
        $metaKey = self::META_PROJECT_TOKEN_PREFIX . $project_id;
        $existingToken = Meta::get_meta($project_uuid, $metaKey);
        
        if ($existingToken && is_string($existingToken)) {
            return $existingToken;
        }
        
        $token = ID::generate_access_token($project_id, $project_uuid, $length);
        Meta::set_meta($project_uuid, $metaKey, $token);
        
        return $token;
    }

    public function upload_user_file(string $user_id, string $user_uuid, string $project_id, string $project_uuid, array $file): array
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        $projectToken = $this->get_or_create_project_token($project_id, $project_uuid);
        
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        $projectDir = $userDir . $projectToken . DIRECTORY_SEPARATOR;
        $srcDir = $projectDir . 'src' . DIRECTORY_SEPARATOR;
        $distDir = $projectDir . 'dist' . DIRECTORY_SEPARATOR;

        if (!is_dir($userDir) && !FS::instance()->make_dir($userDir)) {
            return ['ok' => false, 'error' => 'Failed to create user directory'];
        }

        if (!is_dir($projectDir) && !FS::instance()->make_dir($projectDir)) {
            return ['ok' => false, 'error' => 'Failed to create project directory'];
        }

        if (!is_dir($srcDir) && !FS::instance()->make_dir($srcDir)) {
            return ['ok' => false, 'error' => 'Failed to create src directory'];
        }

        if (!is_dir($distDir) && !FS::instance()->make_dir($distDir)) {
            return ['ok' => false, 'error' => 'Failed to create dist directory'];
        }

        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Invalid uploaded file'];
        }

        $originalName = basename($file['name']);
        $slugName = $this->generate_unique_slug_name($srcDir, $originalName);
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

        $srcUrl = $this->build_public_url($srcPath);
        $distUrl = $this->build_public_url($distDir . $slugName);

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
            'mime' => $this->get_mime_type($srcPath)
        ];
    }

    protected function generate_unique_slug_name(string $dir, string $filename): string
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

    public function delete_file(string $user_id, string $user_uuid, string $project_id, string $project_uuid, string $filename): bool
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        $projectToken = $this->get_or_create_project_token($project_id, $project_uuid);
        
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

    public function delete_project_files(string $user_id, string $user_uuid, string $project_id, string $project_uuid): bool
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        $projectToken = $this->get_or_create_project_token($project_id, $project_uuid);
        
        $projectDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR . $projectToken . DIRECTORY_SEPARATOR;
        
        if (!is_dir($projectDir)) {
            return true;
        }

        try {
            FS::instance()->remove_dir($projectDir . 'src', true);
            FS::instance()->remove_dir($projectDir . 'dist', true);
            FS::instance()->remove_dir($projectDir, false);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to delete project files: ' . $e->getMessage());
            return false;
        }
    }

    public function delete_user_files(string $user_id, string $user_uuid): bool
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        
        if (!is_dir($userDir)) {
            return true;
        }

        try {
            FS::instance()->remove_dir($userDir, true);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to delete user files: ' . $e->getMessage());
            return false;
        }
    }

    protected function build_public_url(string $file_path): string|false
    {
        $base = rtrim(str_replace('\\', '/', $this->uploadPath), '/');
        $file = str_replace('\\', '/', $file_path);

        if (!str_starts_with($file, $base . '/')) {
            return false;
        }

        $relative = substr($file, strlen($base) + 1);
        return rtrim(AT::instance()->get_public_url(), '/') . '/uploads/' . $relative;
    }

    public function get_file_info(string $user_id, string $user_uuid, string $project_id, string $project_uuid, string $filename, string $variant = 'optimized'): array|false
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        $projectToken = $this->get_or_create_project_token($project_id, $project_uuid);
        
        $projectDir = realpath($this->uploadUser . $userToken . DIRECTORY_SEPARATOR . $projectToken) . DIRECTORY_SEPARATOR;
        $srcFile = $projectDir . 'src' . DIRECTORY_SEPARATOR . $filename;
        $distFile = $projectDir . 'dist' . DIRECTORY_SEPARATOR . $variant . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($srcFile)) {
            return false;
        }

        $srcUrl = $this->build_public_url($srcFile);
        $dist_exists = file_exists($distFile);
        $distUrl = $dist_exists ? $this->build_public_url($distFile) : null;

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
            'mime' => $this->get_mime_type($srcFile)
        ];
    }

    protected function get_mime_type(string $file): string
    {
        return \Web::instance()->mime($file, true);
    }

    public function download_image_from_url(string $url, string $destination_dir, string $default_filename = 'image.jpg'): array
    {
        $imageData = @file_get_contents($url);
        if ($imageData === false) {
            return ['ok' => false, 'error' => 'Failed to download file from URL'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'img_');
        if ($tmpFile === false) {
            return ['ok' => false, 'error' => 'Failed to create temp file'];
        }

        if (FS::instance()->write($tmpFile, $imageData, false) === false) {
            FS::instance()->delete($tmpFile);
            return ['ok' => false, 'error' => 'Failed to write to temp file'];
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        $originalName = basename($urlPath);
        
        if (empty($originalName)) {
            $originalName = $default_filename;
        }

        if (!is_dir($destination_dir) && !FS::instance()->make_dir($destination_dir)) {
            FS::instance()->delete($tmpFile);
            return ['ok' => false, 'error' => 'Failed to create destination directory'];
        }

        $slugName = $this->generate_unique_slug_name($destination_dir, $originalName);
        $file_path = $destination_dir . $slugName;

        if (!FS::instance()->copy($tmpFile, $file_path)) {
            FS::instance()->delete($tmpFile);
            return ['ok' => false, 'error' => 'Failed to save file'];
        }

        FS::instance()->delete($tmpFile);

        $fileUrl = $this->build_public_url($file_path);

        if ($fileUrl === false) {
            FS::instance()->delete($file_path);
            return ['ok' => false, 'error' => 'Failed to generate public URL'];
        }

        return [
            'ok' => true,
            'filename' => $slugName,
            'src_path' => $file_path,
            'url' => $fileUrl,
            'size' => filesize($file_path),
            'mime' => $this->get_mime_type($file_path)
        ];
    }

    public function download_user_image(string $user_id, string $user_uuid, string $project_id, string $project_uuid, string $url, string $default_filename = 'image.jpg'): array
    {
        $userToken = $this->get_or_create_user_token($user_id, $user_uuid);
        $projectToken = $this->get_or_create_project_token($project_id, $project_uuid);
        $userDir = $this->uploadUser . $userToken . DIRECTORY_SEPARATOR;
        $projectDir = $userDir . $projectToken . DIRECTORY_SEPARATOR;
        $srcDir = $projectDir . 'src' . DIRECTORY_SEPARATOR;

        $result = $this->download_image_from_url($url, $srcDir, $default_filename);
        
        return $result;
    }

    public function download_system_file(string $filename): array
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
            'mime' => $this->get_mime_type($realPath),
            'size' => filesize($realPath)
        ];
    }
}