<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;
?>
<!DOCTYPE html>
<html lang="<?php echo get_locale(); ?>">
<head>
<title><?php echo get_title(); ?></title>
<meta charset="<?php echo get_encoding(); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">

<?php
get_favicon();
get_iconset();
get_manifest();
get_canonical_link();

add_preconnect('https://fonts.googleapis.com');
add_preconnect('https://fonts.gstatic.com', true);
get_preconnect();

print_styles();
print_scripts('header');

get_opengraph([
    'title'       => 'Atomic Example Showcase',
    'description' => 'Every theme feature & global helper, live.',
    'url'         => get_public_uri() . 'example',
    'image'       => get_public_uri() . 'assets/img/apple-touch-icon.png',
    'type'        => 'website',
    'site_name'   => 'Atomic Framework',
    'locale'      => get_locale(),
]);

get_twitter_card([
    'card'        => 'summary',
    'title'       => 'Atomic Example Showcase',
    'description' => 'Reference theme for Atomic Framework.',
]);

get_schema('Organization', [
    'name'        => 'Atomic Framework',
    'url'         => 'https://atomic-framework.github.io',
    'logo'        => get_public_uri() . 'assets/img/apple-touch-icon.png',
]);

get_analytics('gtag', 'UA-EXAMPLE-1');

echo hreflang_links('/example');

get_custom_head();
?>
<meta name="theme-color" content="<?php echo get_color(); ?>">
</head>
<body>
