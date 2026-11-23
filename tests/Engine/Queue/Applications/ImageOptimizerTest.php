<?php

declare(strict_types=1);
namespace Tests\Engine\Queue\Applications;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Applications\ImageOptimizer;
use Engine\Atomic\Queue\Applications\ImageThumbnail;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class ImageOptimizerTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="4000" height="3000"><rect width="4000" height="3000"/></svg>';

    private string $dir;
    private mixed $previousConfig;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_img_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->previousConfig = App::instance()->get('CONFIG.images');
        App::instance()->set('CONFIG.images', []);
    }

    protected function tearDown(): void
    {
        App::instance()->set('CONFIG.images', $this->previousConfig);
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_large_jpeg_is_downscaled_to_default_long_side(): void
    {
        $this->assertSame([1600, 1200], $this->optimized($this->jpeg(2400, 1800), 'out.jpg'));
    }

    public function test_tall_jpeg_is_limited_by_its_height(): void
    {
        $this->assertSame([800, 1600], $this->optimized($this->jpeg(900, 1800), 'out.jpg'));
    }

    public function test_small_image_is_not_upscaled(): void
    {
        $this->assertSame([300, 200], $this->optimized($this->jpeg(300, 200), 'out.jpg'));
    }

    public function test_jpeg_output_is_progressive(): void
    {
        $this->optimized($this->jpeg(400, 300), 'out.jpg');
        // SOF2 marks a progressive JPEG.
        $this->assertStringContainsString("\xFF\xC2", (string)file_get_contents($this->path('out.jpg')));
    }

    public function test_png_is_downscaled_and_keeps_alpha(): void
    {
        $source = $this->path('in.png');
        imagepng($this->transparent_canvas(2000, 1000), $source);

        $this->assertSame([1600, 800], $this->optimized($source, 'out.png'));
        $this->assert_alpha_kept(imagecreatefrompng($this->path('out.png')));
    }

    public function test_webp_is_downscaled_and_keeps_alpha(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD without WebP support');
        }
        $source = $this->path('in.webp');
        imagewebp($this->transparent_canvas(2000, 1000), $source, 100);

        $this->assertSame([1600, 800], $this->optimized($source, 'out.webp'));
        $this->assert_alpha_kept(imagecreatefromwebp($this->path('out.webp')));
    }

    public function test_avif_is_downscaled(): void
    {
        if (!function_exists('imageavif') || !function_exists('imagecreatefromavif')) {
            $this->markTestSkipped('GD without AVIF support');
        }
        $source = $this->path('in.avif');
        imageavif($this->transparent_canvas(1800, 900), $source, 50, 10);

        $this->assertSame([1600, 800], $this->optimized($source, 'out.avif'));
    }

    public function test_svg_is_copied_untouched(): void
    {
        $dest = $this->path('out.svg');

        $this->assertTrue((new ImageOptimizer())->optimize(['source' => $this->svg(), 'destination' => $dest], $this->telemetry()));
        $this->assertSame(self::SVG, file_get_contents($dest));
    }

    public function test_thumbnail_copies_svg_untouched(): void
    {
        $dest = $this->path('thumb.svg');

        $this->assertTrue((new ImageThumbnail())->generate(['source' => $this->svg(), 'destination' => $dest], $this->telemetry()));
        $this->assertSame(self::SVG, file_get_contents($dest));
    }

    public function test_unsupported_type_is_rejected(): void
    {
        $source = $this->path('in.gif');
        imagegif(imagecreatetruecolor(10, 10), $source);

        $this->assertFalse((new ImageOptimizer())->optimize(['source' => $source, 'destination' => $this->path('out.gif')], $this->telemetry(false)));
        $this->assertFileDoesNotExist($this->path('out.gif'));
    }

    public function test_missing_destination_is_rejected(): void
    {
        $this->assertFalse((new ImageOptimizer())->optimize(['source' => $this->jpeg(10, 10)], $this->telemetry(false)));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_custom_max_size_constant_is_respected(): void
    {
        \define('ATOMIC_IMAGE_MAX_SIZE', 500);
        $this->assertSame([500, 400], $this->optimized($this->jpeg(1000, 800), 'out.jpg'));
    }

    public function test_per_call_max_size_overrides_config(): void
    {
        App::instance()->set('CONFIG.images', ['max_size' => 400]);
        $this->assertSame([250, 200], $this->optimized($this->jpeg(1000, 800), 'out.jpg', ['max_size' => 250]));
    }

    public function test_config_max_size_from_app_hive_is_used(): void
    {
        App::instance()->set('CONFIG.images', ['max_size' => 400]);
        $this->assertSame([400, 320], $this->optimized($this->jpeg(1000, 800), 'out.jpg'));
    }

    public function test_size_modes_do_not_enlarge_narrow_images(): void
    {
        $source = $this->jpeg(250, 100);
        foreach (['small', 'medium', 'large'] as $mode) {
            $this->assertSame([250, 100], $this->thumbnail($source, "{$mode}.jpg", ['mode' => $mode]), $mode);
        }
    }

    public function test_size_modes_still_shrink_wide_images_and_keep_alpha(): void
    {
        $source = $this->path('wide.png');
        imagepng($this->transparent_canvas(2000, 1000), $source);

        $this->assertSame([1200, 600], $this->thumbnail($source, 'large.png', ['mode' => 'large']));
        $this->assert_alpha_kept(imagecreatefrompng($this->path('large.png')));
    }

    public function test_thumbnail_mode_crops_to_a_square(): void
    {
        $this->assertSame([150, 150], $this->thumbnail($this->jpeg(400, 200), 'thumb.jpg'));
    }

    public function test_thumbnail_mode_without_crop_fits_the_box(): void
    {
        $this->assertSame([150, 75], $this->thumbnail($this->jpeg(400, 200), 'thumb.jpg', ['thumbnail_crop' => false]));
    }

    /** @return array{0:int,1:int} */
    private function optimized(string $source, string $name, array $options = []): array
    {
        $dest = $this->path($name);
        $params = ['source' => $source, 'destination' => $dest] + $options;
        $this->assertTrue((new ImageOptimizer())->optimize($params, $this->telemetry()));
        return $this->dimensions($dest);
    }

    /** @return array{0:int,1:int} */
    private function thumbnail(string $source, string $name, array $options = []): array
    {
        $dest = $this->path($name);
        $params = ['source' => $source, 'destination' => $dest] + $options;
        $this->assertTrue((new ImageThumbnail())->generate($params, $this->telemetry()));
        return $this->dimensions($dest);
    }

    /** With $mustSucceed, fails the test when a step reports a failure, e.g. Imagick silently falling back to GD. */
    private function telemetry(bool $mustSucceed = true): TelemetryManager
    {
        $telemetry = $this->createMock(TelemetryManager::class);
        $telemetry->method('push_telemetry')->willReturnCallback(function (string $message) use ($mustSucceed): bool {
            if ($mustSucceed) {
                $this->assertStringNotContainsString('failed', $message);
            }
            return true;
        });
        return $telemetry;
    }

    private function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    /** @return array{0:int,1:int} */
    private function dimensions(string $file): array
    {
        $info = getimagesize($file);
        $this->assertIsArray($info);
        if ($info[0] === 0 && ($info['mime'] ?? '') === 'image/avif') {
            // PHP 8.1 getimagesize() detects AVIF but reports 0x0; decode to measure.
            $image = imagecreatefromavif($file);
            $this->assertInstanceOf(\GdImage::class, $image);
            return [imagesx($image), imagesy($image)];
        }
        return [$info[0], $info[1]];
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, 0x3366CC);
        $file = $this->path("in_{$width}x{$height}.jpg");
        imagejpeg($image, $file, 90);
        return $file;
    }

    private function svg(): string
    {
        $file = $this->path('in.svg');
        file_put_contents($file, self::SVG);
        return $file;
    }

    private function transparent_canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, (int)($width / 4), (int)($height / 4), (int)($width * 3 / 4), (int)($height * 3 / 4), imagecolorallocatealpha($image, 255, 0, 0, 0));
        return $image;
    }

    private function assert_alpha_kept(\GdImage $image): void
    {
        $corner = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        $center = imagecolorsforindex($image, imagecolorat($image, (int)(imagesx($image) / 2), (int)(imagesy($image) / 2)));
        $this->assertSame(127, $corner['alpha']);
        $this->assertSame(0, $center['alpha']);
    }
}
