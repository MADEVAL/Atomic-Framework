<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Applications;

if (!defined('ATOMIC_START')) exit;

/**
 * Image types the queue applications handle, with everything needed to decode and encode them.
 */
enum ImageFormat: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Avif = 'image/avif';
    case Svg = 'image/svg+xml';

    public static function detect(string $path): ?self
    {
        $info = @getimagesize($path);
        $mime = is_array($info) ? $info['mime'] : @mime_content_type($path);
        return is_string($mime) ? self::tryFrom($mime) : null;
    }

    public function is_raster(): bool
    {
        return $this->spec() !== null;
    }

    /** True when Imagick or GD can re-encode this format. */
    public function can_encode(): bool
    {
        return $this->is_raster() && (extension_loaded('imagick') || $this->gd_available());
    }

    /** Encoder level from settings: zlib compression (0-9) for PNG, quality (0-100) otherwise. */
    public function level(ImageSettings $settings): int
    {
        $key = $this->spec()['setting'];
        return $this === self::Png ? $settings->int($key) : $settings->quality($key);
    }

    /** Sets output format, quality and options, then strips metadata. */
    public function imagick_encode(\Imagick $imagick, int $level): void
    {
        $spec = $this->spec();
        $imagick->setImageFormat($spec['imagick']);
        // Imagick reads the PNG zlib level from the tens digit of the quality.
        $imagick->setImageCompressionQuality($this === self::Png ? $level * 10 : $level);
        foreach ($spec['options'] as $key => $value) {
            $imagick->setOption($key, $value);
        }
        if ($spec['progressive']) {
            $imagick->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
        }
        $imagick->stripImage();
    }

    public function gd_available(): bool
    {
        $spec = $this->spec();
        return $spec !== null && function_exists($spec['decode']) && function_exists($spec['encode']);
    }

    public function gd_load(string $path): \GdImage|false
    {
        return @($this->spec()['decode'])($path);
    }

    public function gd_save(\GdImage $image, string $path, int $level): bool
    {
        $spec = $this->spec();
        imagealphablending($image, false);
        imagesavealpha($image, true);
        if ($spec['progressive']) {
            imageinterlace($image, true);
        }
        return @($spec['encode'])($image, $path, $level);
    }

    /** @return array{imagick:string,setting:string,decode:string,encode:string,progressive:bool,options:array<string,string>}|null */
    private function spec(): ?array
    {
        return match ($this) {
            self::Jpeg => self::raster('jpeg', ImageSettings::JPEG_QUALITY, true, ['jpeg:sampling-factor' => '4:2:0']),
            self::Png => self::raster('png', ImageSettings::PNG_COMPRESSION),
            self::Webp => self::raster('webp', ImageSettings::WEBP_QUALITY, false, ['webp:method' => '6']),
            self::Avif => self::raster('avif', ImageSettings::AVIF_QUALITY),
            self::Svg => null,
        };
    }

    private static function raster(string $name, string $setting, bool $progressive = false, array $options = []): array
    {
        return [
            'imagick' => $name,
            'setting' => $setting,
            'decode' => "imagecreatefrom{$name}",
            'encode' => "image{$name}",
            'progressive' => $progressive,
            'options' => $options,
        ];
    }
}
