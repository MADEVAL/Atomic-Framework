<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

?><!DOCTYPE html>
<html lang="<?php echo get_locale();?>">
<head>
<title><?php echo get_title(); ?></title>
<meta charset="<?php echo get_encoding(); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php 
    //get_description(); 
    //get_keywords();

    // Favicon link 
    get_favicon(); 
    // Iconset links for various devices and platforms
    get_iconset();
//    get_meta();
//    get_styles();
//    get_scripts('head');
//    get_analytics();
//    get_csrf_token();
//    get_social_meta();
//    get_seo_meta();
//    get_alternate_links();
//    get_rss_feed();
//    get_canonical_link();
    // Manifest link
    get_manifest();
//    get_preload_links();
//    get_prefetch_links();
//    get_dns_prefetch_links();

//***********************************************
// Atomic Framework Assets
//***********************************************
    print_styles();
    print_scripts('header');

//***********************************************

    // Custom head partial inclusion - for additional user meta tags, styles, scripts etc.
    get_custom_head();
?>
<meta name="theme-color" content="<?php echo get_color(); ?>">
</head>
<body>