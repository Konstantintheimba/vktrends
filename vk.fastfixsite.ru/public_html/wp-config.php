<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'kostyan13_wp_1' );

/** Database username */
define( 'DB_USER', 'kostyan13_wp_1' );

/** Database password */
define( 'DB_PASSWORD', 'i8q02*Nj&' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

define( 'VKT_ACCESS_TOKEN', '8283f8ee8283f8ee8283f8ee8981c07905882838283f8eee81f2850ba815f2649910801' );
/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '}f*k6pS.f:^=;~WND!zwzfT-1F|:>R*]zn-%EJyrSO$xg+M_asg@NX3-Ng>*[y]f' );
define( 'SECURE_AUTH_KEY',  'AK(CG:*HD#ggDEPEu7HN)!&mQ?kR5`3C|$R}l~Sg_M#6.XENN|D~m0#6Dwa88bh{' );
define( 'LOGGED_IN_KEY',    'wv`mx?wfnfT&(`K WBe:MGX,Ts(p.D&ph$A2Xm/M#ebhcEla))D_)+!J>:$`~2`d' );
define( 'NONCE_KEY',        '*=a#lV{YXsOiZ)y)WQK mwoUb^`>&uYnBiA@E Bqg8&<v%Xyg?=V>!cUTgUZ,lVd' );
define( 'AUTH_SALT',        'fbM Hlh(i!SpS.OJ_{:@?ls/rqY]%3S7K9?t:,Q@e:8rFW!vQOGlGkXU9@ 1r-As' );
define( 'SECURE_AUTH_SALT', '/kw.u%qhPK0NjF>Gfq=XuHA_7~%q~5_4%ox<O :~_gUd2.zkWp#ovLm~cASg+=~/' );
define( 'LOGGED_IN_SALT',   'C9h)-gV3rqT> Xr;qp?X*AFyf#JFz`h2Uot;yV;29;u,Jv&`+ue!7qw.&Rg&t.M+' );
define( 'NONCE_SALT',       'ZV4T87fV{V$R?DOWVTK@VIa?X^Hg`:vb}]eq7&2B%:Bzzzx .`h_mcNyM$w l9&,' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );


/* Add any custom values between this line and the "stop editing" line. */

define('FORCE_SSL_ADMIN', false); # To enable admin access over HTTP

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
