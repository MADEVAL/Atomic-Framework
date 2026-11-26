<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Queue\Managers\TelemetryManager;

/**
 * Shared job flow for the image applications: validate params, then encode
 * with Imagick and fall back to GD, reporting through telemetry.
 */
final class ImageProcessor
{
    public const SOURCE = 'source';
    public const DESTINATION = 'destination';

    public function __construct(
        private readonly string $name,
        private readonly TelemetryManager $telemetry,
    ) {}

    /** @return array{0:string,1:string,2:ImageFormat}|null source, destination and format, or null when the job cannot run */
    public function open(array $params): ?array
    {
        if (!isset($params[self::SOURCE], $params[self::DESTINATION])) {
            $this->log('Missing source or destination parameters');
            return null;
        }

        $source = (string)$params[self::SOURCE];
        $destination = (string)$params[self::DESTINATION];

        if (!is_file($source) || !is_readable($source)) {
            $this->log("Source file not found or unreadable: {$source}");
            return null;
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !Filesystem::instance()->make_dir($directory, 0755, true)) {
            $this->log("Cannot create destination directory: {$directory}");
            return null;
        }

        $format = ImageFormat::detect($source);
        if ($format === null) {
            $this->log("Unsupported image type: {$source}");
            return null;
        }
        return [$source, $destination, $format];
    }

    /**
     * @param \Closure(\Imagick):void $imagick resize step for Imagick, edits in place
     * @param \Closure(\GdImage):\GdImage $gd resize step for GD, consumes its input
     */
    public function encode(string $source, string $destination, ImageFormat $format, int $level, \Closure $imagick, \Closure $gd): bool
    {
        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick($source);
                $imagick($image);
                $format->imagick_encode($image, $level);
                $image->writeImage($destination);
                $image->clear();
                return true;
            } catch (\Throwable $e) {
                $this->log("{$format->name} Imagick failed: {$e->getMessage()}");
            }
        }

        if (!$format->gd_available()) {
            $this->log("No {$format->name} support (GD or Imagick required)");
            return false;
        }

        $image = $format->gd_load($source);
        if ($image === false) {
            $this->log("{$format->name} GD failed to decode {$source}");
            return false;
        }
        $image = $gd($image);
        return $format->gd_save($image, $destination, $level);
    }

    public function copy(string $source, string $destination, ImageFormat $format): bool
    {
        $this->log("{$format->name} copied as-is");
        return Filesystem::instance()->copy($source, $destination);
    }

    public function log(string $message): void
    {
        $this->telemetry->push_telemetry("{$this->name}: {$message}");
    }
}
