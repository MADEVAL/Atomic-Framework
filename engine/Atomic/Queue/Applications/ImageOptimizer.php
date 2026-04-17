<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Queue\Managers\TelemetryManager;

final class ImageOptimizer
{
    public function optimize(array $params): bool
    {
        $telemetry = new TelemetryManager();
        
        if (!isset($params['source'], $params['destination'])) {
            $telemetry->push_telemetry('ImageOptimizer: Missing source or destination parameters');
            return false;
        }

        $source = (string)$params['source'];
        $destination = (string)$params['destination'];

        if (!is_file($source) || !is_readable($source)) {
            $telemetry->push_telemetry("ImageOptimizer: Source file not found or unreadable: {$source}");
            return false;
        }

        $destDir = dirname($destination);
        if (!is_dir($destDir) && !Filesystem::instance()->make_dir($destDir, 0755, true)) {
            $telemetry->push_telemetry("ImageOptimizer: Cannot create destination directory: {$destDir}");
            return false;
        }

        $imageInfo = @getimagesize($source);
        if ($imageInfo === false) {
            $telemetry->push_telemetry("ImageOptimizer: Cannot determine image type: {$source}");
            return false;
        }

        $mimeType = $imageInfo['mime'] ?? '';
        $telemetry->push_telemetry("ImageOptimizer: Processing {$mimeType} from {$source} to {$destination}");

        $result = match($mimeType) {
            'image/jpeg' => $this->jpeg_optimize($source, $destination, $telemetry),
            'image/png' => $this->png_optimize($source, $destination, $telemetry),
            'image/webp' => $this->webp_optimize($source, $destination, $telemetry),
            'image/avif' => $this->avif_optimize($source, $destination, $telemetry),
            'image/svg+xml' => $this->svg_optimize($source, $destination, $telemetry),
            default => false
        };

        if ($result) {
            $sourceSizeKB = round(filesize($source) / 1024, 2);
            $destSizeKB = round(filesize($destination) / 1024, 2);
            $savedKB = round($sourceSizeKB - $destSizeKB, 2);
            $percent = $sourceSizeKB > 0 ? round(($savedKB / $sourceSizeKB) * 100, 2) : 0;
            $telemetry->push_telemetry("ImageOptimizer: Completed. {$sourceSizeKB}KB → {$destSizeKB}KB (saved {$savedKB}KB, {$percent}%)");
        } else {
            $telemetry->push_telemetry("ImageOptimizer: Optimization failed");
        }

        return $result;
    }

    public function jpeg_optimize(string $source, string $destination, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_JPEG_QUALITY') ? (int)\ATOMIC_JPEG_QUALITY : 85;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality($quality);
                $imagick->stripImage();
                $imagick->setSamplingFactors(['2x2', '1x1', '1x1']);
                $imagick->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
                $imagick->setColorspace(\Imagick::COLORSPACE_SRGB);
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageOptimizer JPEG Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            $image = @imagecreatefromjpeg($source);
            if ($image === false) return false;
            
            imageinterlace($image, true);
            $result = @imagejpeg($image, $destination, $quality);
            imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageOptimizer: No JPEG support (GD or Imagick required)");
        return Filesystem::instance()->copy($source, $destination);
    }

    public function png_optimize(string $source, string $destination, TelemetryManager $telemetry): bool
    {
        $compression = \defined('ATOMIC_PNG_COMPRESSION_LEVEL') ? (int)\ATOMIC_PNG_COMPRESSION_LEVEL : 6;
        $compression = max(0, min(9, $compression));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $imagick->setImageFormat('png');
                $imagick->setCompressionQuality($compression * 10);
                $imagick->stripImage();
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageOptimizer PNG Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            $image = @imagecreatefrompng($source);
            if ($image === false) return false;
            
            imagesavealpha($image, true);
            imagealphablending($image, false);
            $result = @imagepng($image, $destination, $compression);
            imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageOptimizer: No PNG support (GD or Imagick required)");
        return Filesystem::instance()->copy($source, $destination);
    }

    public function webp_optimize(string $source, string $destination, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_WEBP_QUALITY') ? (int)\ATOMIC_WEBP_QUALITY : 85;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $imagick->setImageFormat('webp');
                $imagick->setImageCompressionQuality($quality);
                $imagick->setOption('webp:method', '6');
                $imagick->setOption('webp:lossless', 'false');
                $imagick->stripImage();
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageOptimizer WebP Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $imageInfo = @getimagesize($source);
            $mimeType = $imageInfo['mime'] ?? '';
            
            $image = match($mimeType) {
                'image/jpeg' => @imagecreatefromjpeg($source),
                'image/png' => @imagecreatefrompng($source),
                'image/gif' => @imagecreatefromgif($source),
                'image/webp' => @imagecreatefromwebp($source),
                default => false
            };

            if ($image === false) return false;
            
            imagealphablending($image, true);
            imagesavealpha($image, true);
            $result = @imagewebp($image, $destination, $quality);
            imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageOptimizer: No WebP support (GD or Imagick required)");
        return Filesystem::instance()->copy($source, $destination);
    }

    public function avif_optimize(string $source, string $destination, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_AVIF_QUALITY') ? (int)\ATOMIC_AVIF_QUALITY : 50;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $imagick->setImageFormat('avif');
                $imagick->setImageCompressionQuality($quality);
                $imagick->stripImage();
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageOptimizer AVIF Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd') && function_exists('imageavif')) {
            $imageInfo = @getimagesize($source);
            $mimeType = $imageInfo['mime'] ?? '';
            
            $image = match($mimeType) {
                'image/jpeg' => @imagecreatefromjpeg($source),
                'image/png' => @imagecreatefrompng($source),
                'image/gif' => @imagecreatefromgif($source),
                'image/webp' => @imagecreatefromwebp($source),
                default => false
            };

            if ($image === false) return false;
            
            imagealphablending($image, true);
            imagesavealpha($image, true);
            $result = @imageavif($image, $destination, $quality);
            imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageOptimizer: No AVIF support (GD 8.1+ or Imagick required)");
        return Filesystem::instance()->copy($source, $destination);
    }

    public function svg_optimize(string $source, string $destination, TelemetryManager $telemetry): bool
    {
        $telemetry->push_telemetry("ImageOptimizer: SVG optimization skipped (copying as-is)");
        return Filesystem::instance()->copy($source, $destination);
    }
}