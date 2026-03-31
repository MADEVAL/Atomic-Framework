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
- uses `TelemetryManager` to emit progress and failure messages
- reads quality-related constants such as `ATOMIC_JPEG_QUALITY`, `ATOMIC_WEBP_QUALITY`, `ATOMIC_AVIF_QUALITY`, and `ATOMIC_PNG_COMPRESSION_LEVEL`

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

Supported modes:

- `thumbnail`
- `small`
- `medium`
- `large`

Behavior:

- requires `source` and `destination`
- `thumbnail` uses `ATOMIC_THUMBNAIL_SIZE` and optionally square-crops via `ATOMIC_THUMBNAIL_CROP`
- `small`, `medium`, and `large` resize proportionally using `ATOMIC_IMAGE_SIZE_SMALL`, `ATOMIC_IMAGE_SIZE_MEDIUM`, and `ATOMIC_IMAGE_SIZE_LARGE`
- writes telemetry messages through `TelemetryManager`

### Other application classes

The remaining classes are present and can be queued as handlers, but their methods are currently placeholders without framework-level implementation details in this package:

- `MailChecker::check(array $params)`
- `MailSender::send(array $params)`
- `MailSender::sendTest(array $params)`
- `PageCache::generate(array $params)`
- `PageCache::purge(array $params)`
- `PageCache::preload(array $params)`
- `PluginSync::sync(array $params)`
- `PluginSync::syncTest(array $params)`
- `PluginSync::fetchUpdates(array $params)`
- `PluginSync::applyUpdates(array $params)`
- `PluginSync::notify(array $params)`
- `SystemLogs::archive(array $params)`
- `SystemLogs::delete(array $params)`
- `SystemLogs::export(array $params)`
