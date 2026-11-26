<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

/**
 * Resize helpers for the image queue applications. GD helpers consume their input image.
 */
final class ImageGeometry
{
    /** @return array{0:int,1:int} size whose longer side fits $max, never larger than the source */
    public static function fit_within(int $width, int $height, int $max): array
    {
        $long = max($width, $height);
        if ($max <= 0 || $long <= $max) {
            return [$width, $height];
        }
        return self::scale($width, $height, $max / $long);
    }

    public static function imagick_scale_down(\Imagick $imagick, int $max): void
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        [$targetWidth, $targetHeight] = self::fit_within($width, $height, $max);
        if ($targetWidth !== $width || $targetHeight !== $height) {
            $imagick->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
        }
    }

    public static function gd_scale_down(\GdImage $image, int $max): \GdImage
    {
        [$width, $height] = self::fit_within(imagesx($image), imagesy($image), $max);
        return self::gd_resample($image, $width, $height);
    }

    /** Shrinks to $width keeping the aspect ratio; narrower images are left as they are. */
    public static function gd_fit_width(\GdImage $image, int $width): \GdImage
    {
        $sourceWidth = imagesx($image);
        if ($sourceWidth <= $width) {
            return $image;
        }
        [$targetWidth, $targetHeight] = self::scale($sourceWidth, imagesy($image), $width / $sourceWidth);
        return self::gd_resample($image, $targetWidth, $targetHeight);
    }

    /** Resizes so the image fits a $size square, keeping the aspect ratio. */
    public static function gd_fit_box(\GdImage $image, int $size): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        [$targetWidth, $targetHeight] = self::scale($width, $height, $size / max($width, $height));
        return self::gd_resample($image, $targetWidth, $targetHeight);
    }

    /** Center-crops to a square and resizes it to $size. */
    public static function gd_crop_square(\GdImage $image, int $size): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        return self::gd_resample($image, $size, $size, intdiv($width - $side, 2), intdiv($height - $side, 2), $side, $side);
    }

    /** @return array{0:int,1:int} */
    private static function scale(int $width, int $height, float $ratio): array
    {
        return [max(1, (int)round($width * $ratio)), max(1, (int)round($height * $ratio))];
    }

    /** Copies a source region onto a transparent canvas of the target size. */
    private static function gd_resample(
        \GdImage $image,
        int $width,
        int $height,
        int $sourceX = 0,
        int $sourceY = 0,
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
    ): \GdImage {
        $sourceWidth ??= imagesx($image);
        $sourceHeight ??= imagesy($image);
        if ($sourceX === 0 && $sourceY === 0 && $width === $sourceWidth && $height === $sourceHeight
            && $sourceWidth === imagesx($image) && $sourceHeight === imagesy($image)) {
            return $image;
        }

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return $image;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, $sourceX, $sourceY, $width, $height, $sourceWidth, $sourceHeight);
        return $canvas;
    }
}
