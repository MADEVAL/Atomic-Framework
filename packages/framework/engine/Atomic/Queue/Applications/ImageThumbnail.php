<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Queue\Managers\TelemetryManager;

final class ImageThumbnail
{
    public function generate(array $params): bool
    {
        $telemetry = new TelemetryManager();
        
        if (!isset($params['source'], $params['destination'])) {
            $telemetry->push_telemetry('ImageThumbnail: Missing source or destination parameters');
            return false;
        }

        $source = (string)$params['source'];
        $destination = (string)$params['destination'];
        $mode = (string)($params['mode'] ?? 'thumbnail'); // thumbnail, small, medium, large

        if (!is_file($source) || !is_readable($source)) {
            $telemetry->push_telemetry("ImageThumbnail: Source file not found: {$source}");
            return false;
        }

        $destDir = dirname($destination);
        if (!is_dir($destDir) && !Filesystem::instance()->make_dir($destDir, 0755, true)) {
            $telemetry->push_telemetry("ImageThumbnail: Cannot create destination directory: {$destDir}");
            return false;
        }

        $imageInfo = @getimagesize($source);
        if ($imageInfo === false) {
            $telemetry->push_telemetry("ImageThumbnail: Cannot determine image type: {$source}");
            return false;
        }

        $mimeType = $imageInfo['mime'] ?? '';
        $telemetry->push_telemetry("ImageThumbnail: Processing {$mode} {$mimeType} from {$source}");

        $result = match($mimeType) {
            'image/jpeg' => $this->jpeg_thumbnail($source, $destination, $mode, $telemetry),
            'image/png' => $this->png_thumbnail($source, $destination, $mode, $telemetry),
            'image/webp' => $this->webp_thumbnail($source, $destination, $mode, $telemetry),
            'image/avif' => $this->avif_thumbnail($source, $destination, $mode, $telemetry),
            'image/svg+xml' => $this->svg_thumbnail($source, $destination, $mode, $telemetry),
            default => false
        };

        if ($result) {
            $telemetry->push_telemetry("ImageThumbnail: Completed successfully");
        } else {
            $telemetry->push_telemetry("ImageThumbnail: Generation failed");
        }

        return $result;
    }

    public function jpeg_thumbnail(string $source, string $destination, string $mode, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_THUMBNAIL_QUALITY') ? (int)\ATOMIC_THUMBNAIL_QUALITY : 85;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $this->process_image_magick($imagick, $mode, $quality, 'jpeg');
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageThumbnail JPEG Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            $image = @imagecreatefromjpeg($source);
            if ($image === false) return false;
            
            $processed = $this->process_gd($image, $mode);
            if ($processed === false) {
                imagedestroy($image);
                return false;
            }
            
            imageinterlace($processed, true);
            $result = @imagejpeg($processed, $destination, $quality);
            imagedestroy($processed);
            if ($processed !== $image) imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageThumbnail: No JPEG support");
        return false;
    }

    public function png_thumbnail(string $source, string $destination, string $mode, TelemetryManager $telemetry): bool
    {
        $compression = \defined('ATOMIC_PNG_COMPRESSION_LEVEL') ? (int)\ATOMIC_PNG_COMPRESSION_LEVEL : 6;
        $compression = max(0, min(9, $compression));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $this->process_image_magick($imagick, $mode, $compression * 10, 'png');
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageThumbnail PNG Imagick failed: " . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            $image = @imagecreatefrompng($source);
            if ($image === false) return false;
            
            $processed = $this->process_gd($image, $mode);
            if ($processed === false) {
                imagedestroy($image);
                return false;
            }
            
            imagesavealpha($processed, true);
            imagealphablending($processed, false);
            $result = @imagepng($processed, $destination, $compression);
            imagedestroy($processed);
            if ($processed !== $image) imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageThumbnail: No PNG support");
        return false;
    }

    public function webp_thumbnail(string $source, string $destination, string $mode, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_WEBP_QUALITY') ? (int)\ATOMIC_WEBP_QUALITY : 85;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $this->process_image_magick($imagick, $mode, $quality, 'webp');
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageThumbnail WebP Imagick failed: " . $e->getMessage());
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
            
            $processed = $this->process_gd($image, $mode);
            if ($processed === false) {
                imagedestroy($image);
                return false;
            }
            
            imagealphablending($processed, true);
            imagesavealpha($processed, true);
            $result = @imagewebp($processed, $destination, $quality);
            imagedestroy($processed);
            if ($processed !== $image) imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageThumbnail: No WebP support");
        return false;
    }

    public function avif_thumbnail(string $source, string $destination, string $mode, TelemetryManager $telemetry): bool
    {
        $quality = \defined('ATOMIC_AVIF_QUALITY') ? (int)\ATOMIC_AVIF_QUALITY : 50;
        $quality = max(0, min(100, $quality));

        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($source);
                $this->process_image_magick($imagick, $mode, $quality, 'avif');
                $imagick->writeImage($destination);
                $imagick->destroy();
                return true;
            } catch (\Throwable $e) {
                $telemetry->push_telemetry("ImageThumbnail AVIF Imagick failed: " . $e->getMessage());
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
            
            $processed = $this->process_gd($image, $mode);
            if ($processed === false) {
                imagedestroy($image);
                return false;
            }
            
            imagealphablending($processed, true);
            imagesavealpha($processed, true);
            $result = @imageavif($processed, $destination, $quality);
            imagedestroy($processed);
            if ($processed !== $image) imagedestroy($image);
            return $result;
        }

        $telemetry->push_telemetry("ImageThumbnail: No AVIF support");
        return false;
    }

    public function svg_thumbnail(string $source, string $destination, string $mode, TelemetryManager $telemetry): bool
    {
        $telemetry->push_telemetry("ImageThumbnail: SVG copying as-is");
        return Filesystem::instance()->copy($source, $destination);
    }

    private function process_image_magick(\Imagick $imagick, string $mode, int $quality, string $format): void
    {
        $imagick->setImageFormat($format);
        $imagick->setImageCompressionQuality($quality);
        $imagick->stripImage();

        if ($mode === 'thumbnail') {
            $size = \defined('ATOMIC_THUMBNAIL_SIZE') ? (int)\ATOMIC_THUMBNAIL_SIZE : 150;
            $crop = \defined('ATOMIC_THUMBNAIL_CROP') ? (bool)\ATOMIC_THUMBNAIL_CROP : true;
            
            if ($crop) {
                $imagick->cropThumbnailImage($size, $size);
            } else {
                $imagick->thumbnailImage($size, $size, true);
            }
        } else {
            $width = $this->get_size_for_mode($mode);
            $imagick->thumbnailImage($width, 0);
        }
    }

    private function process_gd($image, string $mode)
    {
        $src_width = imagesx($image);
        $src_height = imagesy($image);

        if ($mode === 'thumbnail') {
            $size = \defined('ATOMIC_THUMBNAIL_SIZE') ? (int)\ATOMIC_THUMBNAIL_SIZE : 150;
            $crop = \defined('ATOMIC_THUMBNAIL_CROP') ? (bool)\ATOMIC_THUMBNAIL_CROP : true;
            
            if ($crop) {
                return $this->crop_thumbnail($image, $src_width, $src_height, $size);
            } else {
                return $this->resize_proportional($image, $src_width, $src_height, $size, $size);
            }
        } else {
            $targetWidth = $this->get_size_for_mode($mode);
            return $this->resize_proportional($image, $src_width, $src_height, $targetWidth, 0);
        }
    }

    private function crop_thumbnail($image, int $src_width, int $src_height, int $size)
    {
        $thumbnail = imagecreatetruecolor($size, $size);
        if ($thumbnail === false) return false;

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        imagefilledrectangle($thumbnail, 0, 0, $size, $size, $transparent);

        $srcRatio = $src_width / $src_height;
        $targetRatio = 1;

        if ($srcRatio > $targetRatio) {
            $cropWidth = (int)($src_height * $targetRatio);
            $cropHeight = $src_height;
            $srcX = (int)(($src_width - $cropWidth) / 2);
            $srcY = 0;
        } else {
            $cropWidth = $src_width;
            $cropHeight = (int)($src_width / $targetRatio);
            $srcX = 0;
            $srcY = (int)(($src_height - $cropHeight) / 2);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, $srcX, $srcY, $size, $size, $cropWidth, $cropHeight);
        return $thumbnail;
    }

    private function resize_proportional($image, int $src_width, int $src_height, int $max_width, int $max_height)
    {
        if ($max_height === 0) {
            $ratio = $max_width / $src_width;
            $destWidth = $max_width;
            $destHeight = (int)($src_height * $ratio);
        } elseif ($max_width === 0) {
            $ratio = $max_height / $src_height;
            $destWidth = (int)($src_width * $ratio);
            $destHeight = $max_height;
        } else {
            $ratio = min($max_width / $src_width, $max_height / $src_height);
            $destWidth = (int)($src_width * $ratio);
            $destHeight = (int)($src_height * $ratio);
        }

        $resized = imagecreatetruecolor($destWidth, $destHeight);
        if ($resized === false) return false;

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefilledrectangle($resized, 0, 0, $destWidth, $destHeight, $transparent);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $destWidth, $destHeight, $src_width, $src_height);
        return $resized;
    }

    private function get_size_for_mode(string $mode): int
    {
        return match($mode) {
            'small' => \defined('ATOMIC_IMAGE_SIZE_SMALL') ? (int)\ATOMIC_IMAGE_SIZE_SMALL : 300,
            'medium' => \defined('ATOMIC_IMAGE_SIZE_MEDIUM') ? (int)\ATOMIC_IMAGE_SIZE_MEDIUM : 600,
            'large' => \defined('ATOMIC_IMAGE_SIZE_LARGE') ? (int)\ATOMIC_IMAGE_SIZE_LARGE : 1200,
            default => 150
        };
    }
}