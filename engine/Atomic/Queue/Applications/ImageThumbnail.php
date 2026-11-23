<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Queue\Managers\TelemetryManager;

final class ImageThumbnail
{
    private const NAME = 'ImageThumbnail';

    public const MODE = 'mode';
    public const MODE_THUMBNAIL = 'thumbnail';
    /** Width modes => setting that holds their target width. */
    private const MODE_WIDTHS = [
        'small' => ImageSettings::SIZE_SMALL,
        'medium' => ImageSettings::SIZE_MEDIUM,
        'large' => ImageSettings::SIZE_LARGE,
    ];

    public function generate(array $params, ?TelemetryManager $telemetry = null): bool
    {
        $processor = new ImageProcessor(self::NAME, $telemetry ?? new TelemetryManager());
        $job = $processor->open($params);
        if ($job === null) return false;
        [$source, $destination, $format] = $job;

        $mode = (string)($params[self::MODE] ?? self::MODE_THUMBNAIL);
        $settings = ImageSettings::from_params($params);
        $processor->log("Processing {$mode} {$format->value} from {$source}");

        if ($format->is_raster()) {
            $level = $format === ImageFormat::Jpeg
                ? $settings->quality(ImageSettings::THUMBNAIL_QUALITY)
                : $format->level($settings);
            [$imagick, $gd] = $this->resize_steps($mode, $settings);
            $result = $processor->encode($source, $destination, $format, $level, $imagick, $gd);
        } else {
            $result = $processor->copy($source, $destination, $format);
        }

        $processor->log($result ? 'Completed successfully' : 'Generation failed');
        return $result;
    }

    /** @return array{0:\Closure(\Imagick):void,1:\Closure(\GdImage):\GdImage} */
    private function resize_steps(string $mode, ImageSettings $settings): array
    {
        if (isset(self::MODE_WIDTHS[$mode])) {
            $width = $settings->int(self::MODE_WIDTHS[$mode]);
            return [
                // Never enlarge an image that is already narrower than the target.
                function (\Imagick $image) use ($width): void {
                    if ($image->getImageWidth() > $width) $image->thumbnailImage($width, 0);
                },
                fn (\GdImage $image) => ImageGeometry::gd_fit_width($image, $width),
            ];
        }

        $size = $settings->int(ImageSettings::THUMBNAIL_SIZE);
        if ($settings->bool(ImageSettings::THUMBNAIL_CROP)) {
            return [
                function (\Imagick $image) use ($size): void { $image->cropThumbnailImage($size, $size); },
                fn (\GdImage $image) => ImageGeometry::gd_crop_square($image, $size),
            ];
        }
        return [
            function (\Imagick $image) use ($size): void { $image->thumbnailImage($size, $size, true); },
            fn (\GdImage $image) => ImageGeometry::gd_fit_box($image, $size),
        ];
    }
}
