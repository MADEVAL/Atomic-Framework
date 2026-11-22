<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Models\Meta;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Filesystem as FS;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Theme\Theme as AT;

class Upload
{
    use Singleton;

    protected ?App $atomic = null;
    protected FS $fs;
    protected PublicUrlGuard $url_guard;
    protected string $uploadPath;
    protected string $uploadSystem;
    protected string $uploadUser;

    /** Limits for download_image_from_url(); subclasses may change them. */
    protected int $download_max_bytes = 10 * 1024 * 1024;
    protected int $download_connect_timeout = 5;
    protected int $download_timeout = 20;
    protected int $download_max_redirects = 3;

    private const META_ACCESS_TOKEN_PREFIX = 'file_access_token:';
    private const META_USER_TOKEN_PREFIX = 'upload_user_token:';
    private const META_PROJECT_TOKEN_PREFIX = 'upload_project_token:';

    protected function __construct(?FS $fs = null, ?PublicUrlGuard $url_guard = null)
    {
        $this->fs = $fs ?? FS::instance();
        $this->url_guard = $url_guard ?? new PublicUrlGuard();
        $this->atomic = App::instance();
        $this->uploadPath = $this->atomic->get('UPLOADS');
        $this->uploadSystem = $this->uploadPath . 'system' . DIRECTORY_SEPARATOR;
        $this->uploadUser = $this->uploadPath . 'user' . DIRECTORY_SEPARATOR;
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

        if (!is_dir($userDir) && !$this->fs->make_dir($userDir)) {
            return ['ok' => false, 'error' => 'Failed to create user directory'];
        }

        if (!is_dir($projectDir) && !$this->fs->make_dir($projectDir)) {
            return ['ok' => false, 'error' => 'Failed to create project directory'];
        }

        if (!is_dir($srcDir) && !$this->fs->make_dir($srcDir)) {
            return ['ok' => false, 'error' => 'Failed to create src directory'];
        }

        if (!is_dir($distDir) && !$this->fs->make_dir($distDir)) {
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
        $random = substr(bin2hex(random_bytes(4)), 0, 8);
        $finalName = $slug . '-' . $random . $extension;

        if (file_exists($dir . $finalName)) {
            $counter = 1;
            while (file_exists($dir . $slug . '-' . $counter . $extension)) {
                $counter++;
            }
            return $slug . '-' . $counter . $extension;
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
            $this->fs->remove_dir($projectDir . 'src', true);
            $this->fs->remove_dir($projectDir . 'dist', true);
            $this->fs->remove_dir($projectDir, false);
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
            $this->fs->remove_dir($userDir, true);
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

    protected function is_url_allowed(string $url): bool
    {
        return $this->url_guard->is_allowed($url);
    }

    /**
     * Validated addresses for one download hop; the connection is pinned to the first one.
     *
     * @return list<string>
     */
    protected function resolve_download_addresses(string $url): array
    {
        return $this->url_guard->resolve($url);
    }

    /**
     * Runs one request. The body and headers reach the caller through the
     * CURLOPT_WRITEFUNCTION and CURLOPT_HEADERFUNCTION callbacks in $options.
     *
     * @return array{bool, int, int} Transfer success, HTTP status and curl error number.
     */
    protected function execute_image_request(string $url, array $options): array
    {
        $handle = curl_init($url);
        if ($handle === false || !curl_setopt_array($handle, $options)) {
            return [false, 0, 0];
        }

        $ok = curl_exec($handle) !== false;
        return [$ok, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), curl_errno($handle)];
    }

    public function download_image_from_url(string $url, string $destination_dir, string $default_filename = 'image.jpg'): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_');
        if ($tmpFile === false) {
            return ['ok' => false, 'error' => 'Failed to create temp file'];
        }

        try {
            $stream = fopen($tmpFile, 'w+b');
            if ($stream === false) {
                return ['ok' => false, 'error' => 'Failed to write to temp file'];
            }
            try {
                $error = $this->fetch_image_to_stream($url, $stream);
            } finally {
                fclose($stream);
            }
            if ($error !== null) {
                return ['ok' => false, 'error' => $error];
            }

            $extension = $this->detect_image_extension($tmpFile);
            if ($extension === null) {
                return ['ok' => false, 'error' => 'Downloaded file is not a supported image (JPEG, PNG, GIF, WebP)'];
            }

            // The name comes from the URL, the extension only from the detected type.
            $name = pathinfo(basename(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_FILENAME);
            if ($name === '') {
                $name = pathinfo($default_filename, PATHINFO_FILENAME);
            }
            if ($name === '') {
                $name = 'image';
            }

            if (!is_dir($destination_dir) && !$this->fs->make_dir($destination_dir)) {
                return ['ok' => false, 'error' => 'Failed to create destination directory'];
            }

            $slugName = $this->generate_unique_slug_name($destination_dir, $name . '.' . $extension);
            $file_path = $destination_dir . $slugName;

            if (!$this->fs->copy($tmpFile, $file_path)) {
                return ['ok' => false, 'error' => 'Failed to save file'];
            }
        } finally {
            $this->fs->delete($tmpFile);
        }

        $fileUrl = $this->build_public_url($file_path);

        if ($fileUrl === false) {
            $this->fs->delete($file_path);
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

    /**
     * Downloads into $stream, validating and pinning every hop. Returns an error message or null.
     *
     * @param resource $stream
     */
    private function fetch_image_to_stream(string $url, $stream): ?string
    {
        $deadline = microtime(true) + $this->download_timeout;
        $limit = $this->download_max_bytes;

        for ($hop = 0; ; $hop++) {
            // Keep subclass policy checks, then require validated addresses for the connection.
            if (!$this->is_url_allowed($url) || ($addresses = $this->resolve_download_addresses($url)) === []) {
                return 'URL not allowed: only public http/https URLs are permitted';
            }
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) {
                return 'Failed to download file from URL';
            }

            $address = $addresses[0];
            if (str_contains($address, ':')) {
                $address = '[' . $address . ']';
            }

            ftruncate($stream, 0);
            rewind($stream);
            $received = 0;
            $exceeded = false;
            $location = null;

            $response = $this->execute_image_request($url, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => $this->download_connect_timeout,
                CURLOPT_TIMEOUT_MS => $remaining,
                CURLOPT_MAXFILESIZE => $limit,
                // Pin the socket address while preserving the URL port and hostname.
                CURLOPT_CONNECT_TO => ["::{$address}:"],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                    if (preg_match('/\A(?:HTTP\/|location:\s*(.*?)\s*\z)/i', $line, $match) === 1) {
                        // A status line starts a new header block and clears any earlier Location.
                        $location = isset($match[1]) && $match[1] !== '' ? $match[1] : null;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, $limit, &$received, &$exceeded): int {
                    $received += strlen($chunk);
                    if ($received > $limit) {
                        $exceeded = true;
                        return 0;
                    }
                    return fwrite($stream, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
                },
                // Content-Length can be missing or lie, so also check announced and received sizes.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function ($handle, $total, $now) use ($limit, &$exceeded): int {
                    if ($total > $limit || $now > $limit) {
                        $exceeded = true;
                        return 1;
                    }
                    return 0;
                },
            ]);

            [$ok, $status] = $response;
            if ($exceeded || ($response[2] ?? 0) === CURLE_FILESIZE_EXCEEDED) {
                return 'Downloaded file exceeds the size limit';
            }
            if (!$ok) {
                return 'Failed to download file from URL';
            }
            if ($status >= 200 && $status < 300) {
                return $received > 0 ? null : 'Failed to download file from URL';
            }
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === null) {
                return 'Failed to download file from URL';
            }
            if ($hop >= $this->download_max_redirects) {
                return 'Too many redirects';
            }
            $next = $this->resolve_redirect_url($url, $location);
            if ($next === null) {
                return 'URL not allowed: only public http/https URLs are permitted';
            }
            $url = $next;
        }
    }

    private function detect_image_extension(string $file): ?string
    {
        $types = [
            IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
            IMAGETYPE_PNG => ['png', 'image/png'],
            IMAGETYPE_GIF => ['gif', 'image/gif'],
            IMAGETYPE_WEBP => ['webp', 'image/webp'],
        ];
        $info = @getimagesize($file);
        if ($info === false || !isset($types[$info[2]])) {
            return null;
        }
        [$extension, $mime] = $types[$info[2]];

        // The image parser and libmagic must agree on the type.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo === false ? false : finfo_file($finfo, $file);
        return $detected === $mime ? $extension : null;
    }

    /** Resolves a Location header against the current URL (RFC 3986 section 5.2). */
    private function resolve_redirect_url(string $base, string $reference): ?string
    {
        $reference = (string) preg_replace('/#.*\z/s', '', $reference);
        if (preg_match('/\A[a-z][a-z0-9+.\-]*:/i', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (str_starts_with($reference, '//')) {
            return $parts['scheme'] . ':' . $reference;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $basePath = $parts['path'] ?? '/';
        if ($reference === '' || str_starts_with($reference, '?')) {
            $query = $reference !== '' ? $reference : (isset($parts['query']) ? '?' . $parts['query'] : '');
            return $origin . $basePath . $query;
        }

        $query = '';
        if (($position = strpos($reference, '?')) !== false) {
            $query = substr($reference, $position);
            $reference = substr($reference, 0, $position);
        }
        $path = str_starts_with($reference, '/')
            ? $reference
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $reference;

        $input = explode('/', $path);
        $segments = [];
        foreach ($input as $index => $segment) {
            $last = $index === count($input) - 1;
            if ($segment === '..' || $segment === '.') {
                if ($segment === '..' && count($segments) > 1) {
                    array_pop($segments);
                }
                if ($last) {
                    $segments[] = '';
                }
                continue;
            }
            $segments[] = $segment;
        }

        return $origin . implode('/', $segments) . $query;
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
