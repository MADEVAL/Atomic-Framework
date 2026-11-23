<?php

declare(strict_types=1);
namespace Tests\Engine\Queue\Applications;

use Engine\Atomic\Core\App;
use Engine\Atomic\Queue\Applications\ImageSettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ImageSettingsTest extends TestCase
{
    public function test_defaults_apply_when_nothing_is_set(): void
    {
        $settings = new ImageSettings([], []);

        $this->assertSame(1600, $settings->int('max_size'));
        $this->assertSame(85, $settings->quality('jpeg_quality'));
        $this->assertSame(6, $settings->int('png_compression'));
        $this->assertSame(85, $settings->quality('webp_quality'));
        $this->assertSame(50, $settings->quality('avif_quality'));
        $this->assertSame(150, $settings->int('thumbnail_size'));
        $this->assertTrue($settings->bool('thumbnail_crop'));
        $this->assertSame(85, $settings->quality('thumbnail_quality'));
        $this->assertSame(300, $settings->int('size_small'));
        $this->assertSame(600, $settings->int('size_medium'));
        $this->assertSame(1200, $settings->int('size_large'));
    }

    public function test_config_overrides_default(): void
    {
        $settings = new ImageSettings([], ['max_size' => 900, 'thumbnail_crop' => 'false', 'jpeg_quality' => 70]);

        $this->assertSame(900, $settings->int('max_size'));
        $this->assertFalse($settings->bool('thumbnail_crop'));
        $this->assertSame(70, $settings->quality('jpeg_quality'));
    }

    public function test_config_is_read_from_app_hive_by_default(): void
    {
        $app = App::instance();
        $previous = $app->get('CONFIG.images');
        $app->set('CONFIG.images', ['max_size' => 400]);
        try {
            $this->assertSame(400, (new ImageSettings())->int('max_size'));
            $this->assertSame(250, ImageSettings::from_params(['source' => 'x', 'max_size' => 250])->int('max_size'));
        } finally {
            $app->set('CONFIG.images', $previous);
        }
    }

    public function test_per_call_option_overrides_config(): void
    {
        $settings = new ImageSettings(['max_size' => 800, 'thumbnail_crop' => false], ['max_size' => 900, 'thumbnail_crop' => true]);

        $this->assertSame(800, $settings->int('max_size'));
        $this->assertFalse($settings->bool('thumbnail_crop'));
    }

    public function test_per_call_quality_overrides_every_format_key(): void
    {
        $settings = new ImageSettings(['quality' => 60], ['jpeg_quality' => 70, 'webp_quality' => 75]);

        $this->assertSame(60, $settings->quality('jpeg_quality'));
        $this->assertSame(60, $settings->quality('webp_quality'));
        $this->assertSame(60, $settings->quality('thumbnail_quality'));
    }

    public function test_values_are_clamped(): void
    {
        $settings = new ImageSettings(['jpeg_quality' => 300, 'png_compression' => 42, 'max_size' => -5, 'thumbnail_size' => 0], []);

        $this->assertSame(100, $settings->quality('jpeg_quality'));
        $this->assertSame(9, $settings->int('png_compression'));
        $this->assertSame(0, $settings->int('max_size'));
        $this->assertSame(1, $settings->int('thumbnail_size'));
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImageSettings([], []))->int('nope');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_legacy_constant_sits_between_config_and_default(): void
    {
        \define('ATOMIC_IMAGE_MAX_SIZE', 500);
        \define('ATOMIC_THUMBNAIL_CROP', false);

        $this->assertSame(500, (new ImageSettings([], []))->int('max_size'));
        $this->assertFalse((new ImageSettings([], []))->bool('thumbnail_crop'));
        $this->assertSame(700, (new ImageSettings([], ['max_size' => 700]))->int('max_size'));
        $this->assertSame(300, (new ImageSettings(['max_size' => 300], ['max_size' => 700]))->int('max_size'));
    }
}
