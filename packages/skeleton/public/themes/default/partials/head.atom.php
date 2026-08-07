<?php

declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

?><!DOCTYPE html>
<html lang="<?php echo get_locale(); ?>">
<head>
<title><?php echo get_title(); ?></title>
<meta charset="<?php echo get_encoding(); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
    get_favicon();
    get_iconset();
    get_canonical_link();
    get_manifest();
    print_styles();
    print_scripts('header');
    get_custom_head();
?>
<meta name="theme-color" content="<?php echo get_color(); ?>">
</head>
<body>