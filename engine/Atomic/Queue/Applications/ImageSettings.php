<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

/**
 * Resolves image settings in one place, highest priority first:
 * per-call option → `CONFIG.images` (config/images.php or CONFIG_IMAGES_* env)
 * → legacy ATOMIC_* constant (deprecated) → built-in default.
 */
final class ImageSettings
{
    public const MAX_SIZE = 'max_size';
    public const JPEG_QUALITY = 'jpeg_quality';
    public const PNG_COMPRESSION = 'png_compression';
    public const WEBP_QUALITY = 'webp_quality';
    public const AVIF_QUALITY = 'avif_quality';
    public const THUMBNAIL_SIZE = 'thumbnail_size';
    public const THUMBNAIL_CROP = 'thumbnail_crop';
    public const THUMBNAIL_QUALITY = 'thumbnail_quality';
    public const SIZE_SMALL = 'size_small';
    public const SIZE_MEDIUM = 'size_medium';
    public const SIZE_LARGE = 'size_large';

    /** key => [legacy constant, default, min, max] */
    private const KEYS = [
        self::MAX_SIZE          => ['ATOMIC_IMAGE_MAX_SIZE', 1600, 0, null],
        self::JPEG_QUALITY      => ['ATOMIC_JPEG_QUALITY', 85, 0, 100],
        self::PNG_COMPRESSION   => ['ATOMIC_PNG_COMPRESSION_LEVEL', 6, 0, 9],
        self::WEBP_QUALITY      => ['ATOMIC_WEBP_QUALITY', 85, 0, 100],
        self::AVIF_QUALITY      => ['ATOMIC_AVIF_QUALITY', 50, 0, 100],
        self::THUMBNAIL_SIZE    => ['ATOMIC_THUMBNAIL_SIZE', 150, 1, null],
        self::THUMBNAIL_CROP    => ['ATOMIC_THUMBNAIL_CROP', true, null, null],
        self::THUMBNAIL_QUALITY => ['ATOMIC_THUMBNAIL_QUALITY', 85, 0, 100],
        self::SIZE_SMALL        => ['ATOMIC_IMAGE_SIZE_SMALL', 300, 1, null],
        self::SIZE_MEDIUM       => ['ATOMIC_IMAGE_SIZE_MEDIUM', 600, 1, null],
        self::SIZE_LARGE        => ['ATOMIC_IMAGE_SIZE_LARGE', 1200, 1, null],
    ];

    /** Per-call option that overrides the format-specific quality key. */
    private const QUALITY_OPTION = 'quality';

    private array $options;
    private array $config;

    public function __construct(array $options = [], ?array $config = null)
    {
        $this->options = $options;
        $this->config = $config ?? self::app_config();
    }

    /** Builds settings from a queue job's params array (unknown keys are ignored). */
    public static function from_params(array $params): self
    {
        return new self($params);
    }

    public function int(string $key): int
    {
        [, , $min, $max] = self::definition($key);
        $value = (int)$this->raw($key);
        if ($min !== null) $value = max($min, $value);
        if ($max !== null) $value = min($max, $value);
        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->raw($key);
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (bool)$value;
    }

    /** Quality for an output format; a per-call `quality` option wins over the format key. */
    public function quality(string $key): int
    {
        if (isset($this->options[self::QUALITY_OPTION]) && is_numeric($this->options[self::QUALITY_OPTION])) {
            return max(0, min(100, (int)$this->options[self::QUALITY_OPTION]));
        }
        return $this->int($key);
    }

    private function raw(string $key): mixed
    {
        [$constant, $default] = self::definition($key);
        if (array_key_exists($key, $this->options) && $this->options[$key] !== null) {
            return $this->options[$key];
        }
        if (array_key_exists($key, $this->config) && $this->config[$key] !== null) {
            return $this->config[$key];
        }
        if (\defined($constant)) {
            return \constant($constant);
        }
        return $default;
    }

    /** @return array{0:string,1:int|bool,2:?int,3:?int} */
    private static function definition(string $key): array
    {
        if (!isset(self::KEYS[$key])) {
            throw new \InvalidArgumentException("Unknown image setting: {$key}");
        }
        return self::KEYS[$key];
    }

    private static function app_config(): array
    {
        try {
            $config = App::instance()->get('CONFIG.images');
        } catch (\Throwable) {
            return [];
        }
        return is_array($config) ? $config : [];
    }
}
