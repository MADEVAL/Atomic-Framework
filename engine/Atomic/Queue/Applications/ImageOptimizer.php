<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Queue\Managers\TelemetryManager;

final class ImageOptimizer
{
    private const NAME = 'ImageOptimizer';

    public function optimize(array $params, ?TelemetryManager $telemetry = null): bool
    {
        $processor = new ImageProcessor(self::NAME, $telemetry ?? new TelemetryManager());
        $job = $processor->open($params);
        if ($job === null) return false;
        [$source, $destination, $format] = $job;

        $settings = ImageSettings::from_params($params);
        $max = $settings->int(ImageSettings::MAX_SIZE);
        $processor->log("Processing {$format->value} from {$source} to {$destination}");

        $result = $format->can_encode()
            ? $processor->encode(
                $source,
                $destination,
                $format,
                $format->level($settings),
                fn (\Imagick $image) => ImageGeometry::imagick_scale_down($image, $max),
                fn (\GdImage $image) => ImageGeometry::gd_scale_down($image, $max),
            )
            : $processor->copy($source, $destination, $format);

        if (!$result) {
            $processor->log('Optimization failed');
            return false;
        }

        clearstatcache(true, $destination);
        $sourceKB = round(filesize($source) / 1024, 2);
        $destinationKB = round(filesize($destination) / 1024, 2);
        $savedKB = round($sourceKB - $destinationKB, 2);
        $percent = $sourceKB > 0 ? round($savedKB / $sourceKB * 100, 2) : 0;
        $processor->log("Completed. {$sourceKB}KB → {$destinationKB}KB (saved {$savedKB}KB, {$percent}%)");
        return true;
    }
}
