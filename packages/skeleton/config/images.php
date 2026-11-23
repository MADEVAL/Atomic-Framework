<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

// Image queue applications (ImageOptimizer, ImageThumbnail). Read as CONFIG.images.
// Per-call params override these; the legacy ATOMIC_* constants are only used for keys missing here.
return [
    // Longest side in px after optimization; smaller images are never enlarged. 0 disables.
    'max_size'          => 1600,
    'jpeg_quality'      => 85,
    'png_compression'   => 6,
    'webp_quality'      => 85,
    'avif_quality'      => 50,

    // ImageThumbnail
    'thumbnail_size'    => 150,
    'thumbnail_crop'    => true,
    'thumbnail_quality' => 85,
    'size_small'        => 300,
    'size_medium'       => 600,
    'size_large'        => 1200,
];
