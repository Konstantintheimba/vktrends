<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <?php wp_head(); ?>
</head>
<body class="vkt-standalone">
<?php wp_body_open(); ?>
<?php require VKT_DIR . 'templates/app.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
