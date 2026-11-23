## Applications ##

`Engine\Atomic\Queue\Applications` currently contains these queue-oriented application classes:

- `ImageOptimizer`
- `ImageThumbnail`
- `MailChecker`
- `MailSender`
- `PageCache`
- `PluginSync`
- `SystemLogs`

### Image optimizer

```php
use Engine\Atomic\Queue\Applications\ImageOptimizer;

$optimizer = new ImageOptimizer();

$ok = $optimizer->optimize([
    'source' => '/path/to/image.jpg',
    'destination' => '/path/to/optimized.jpg',
    // optional per-call overrides (see "Image settings")
    'max_size' => 800,
    'quality' => 80,
]);
```

Supported source types:

- `image/jpeg`
- `image/png`
- `image/webp`
- `image/avif`
- `image/svg+xml`

Behavior:

- requires `source` and `destination`
- prefers Imagick, falls back to GD when available
- uses `TelemetryManager` to emit progress and failure messages (pass one as the second argument to reuse it)
- other types, such as GIF, are rejected and `false` is returned
- reads `max_size`, `jpeg_quality`, `png_compression`, `webp_quality`, and `avif_quality` (see "Image settings")
- downscales JPEG, PNG, WebP, and AVIF so the longer side is at most `max_size` px (default `1600`, `0` disables), keeping the aspect ratio and transparency; smaller images are never upscaled and SVG is copied as-is

### Image thumbnails

```php
use Engine\Atomic\Queue\Applications\ImageThumbnail;

$thumbnail = new ImageThumbnail();

$thumbnail->generate([
    'source' => '/path/to/image.jpg',
    'destination' => '/path/to/thumb.jpg',
    'mode' => 'thumbnail',
]);

$thumbnail->generate([
    'source' => '/path/to/image.jpg',
    'destination' => '/path/to/medium.jpg',
    'mode' => 'medium',
]);
```

Supported modes (unknown modes fall back to `thumbnail`):

- `thumbnail`
- `small`
- `medium`
- `large`

Behavior:

- requires `source` and `destination`
- `thumbnail` uses `thumbnail_size` and optionally square-crops via `thumbnail_crop`
- `small`, `medium`, and `large` resize proportionally to `size_small`, `size_medium`, and `size_large`; images already narrower than the target are kept at their size, never enlarged
- JPEG output uses `thumbnail_quality`; PNG, WebP, and AVIF use `png_compression`, `webp_quality`, and `avif_quality`
- SVG is copied as-is
- writes telemetry messages through `TelemetryManager`

### Image settings

`ImageOptimizer` and `ImageThumbnail` resolve every setting through `ImageSettings`, highest priority first:

1. per-call option in the params array passed to `optimize()` / `generate()`
2. the `images` config section, read from `CONFIG.images`: `config/images.php` in PHP config mode, or `CONFIG_IMAGES_<KEY>` in `.env` mode (see [config.md](config.md#custom-application-config))
3. the legacy constant (deprecated, still honoured)
4. the built-in default

| Key | Default | Deprecated constant | Used by |
|---|---|---|---|
| `max_size` | `1600` (px, longer side; `0` disables) | `ATOMIC_IMAGE_MAX_SIZE` | optimizer |
| `jpeg_quality` | `85` | `ATOMIC_JPEG_QUALITY` | optimizer |
| `png_compression` | `6` (0-9) | `ATOMIC_PNG_COMPRESSION_LEVEL` | both |
| `webp_quality` | `85` | `ATOMIC_WEBP_QUALITY` | both |
| `avif_quality` | `50` | `ATOMIC_AVIF_QUALITY` | both |
| `thumbnail_size` | `150` | `ATOMIC_THUMBNAIL_SIZE` | thumbnails |
| `thumbnail_crop` | `true` | `ATOMIC_THUMBNAIL_CROP` | thumbnails |
| `thumbnail_quality` | `85` | `ATOMIC_THUMBNAIL_QUALITY` | thumbnails (JPEG) |
| `size_small` | `300` | `ATOMIC_IMAGE_SIZE_SMALL` | thumbnails |
| `size_medium` | `600` | `ATOMIC_IMAGE_SIZE_MEDIUM` | thumbnails |
| `size_large` | `1200` | `ATOMIC_IMAGE_SIZE_LARGE` | thumbnails |

Per-call params also accept `quality`, which overrides the format-specific quality key for that call. Values are clamped to their valid range.

The `ATOMIC_*` image constants are **deprecated**: move them to `config/images.php` (or `CONFIG_IMAGES_*`). They are kept for backward compatibility and only apply to keys the config does not set.

### Other application classes

The remaining classes are present and can be queued as handlers, but their methods are currently placeholders without framework-level implementation details in this package:

- `MailChecker::check(array $params)`
- `MailSender::send(array $params)`
- `MailSender::send_test(array $params)`
- `PageCache::generate(array $params)`
- `PageCache::purge(array $params)`
- `PageCache::preload(array $params)`
- `PluginSync::sync(array $params)`
- `PluginSync::sync_test(array $params)`
- `PluginSync::fetch_updates(array $params)`
- `PluginSync::apply_updates(array $params)`
- `PluginSync::notify(array $params)`
- `SystemLogs::archive(array $params)`
- `SystemLogs::delete(array $params)`
- `SystemLogs::export(array $params)`
