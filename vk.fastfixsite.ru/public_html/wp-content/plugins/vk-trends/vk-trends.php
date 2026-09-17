<?php
/**
 * Plugin Name: VK Trends
 * Description: Приватный дашборд постов, видео и товаров VK: счётчики, динамика, сводка по сообществам, автопостинг с генерацией текста и медиа, тест API.
 * Version: 0.19.2
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Author: FastFixSite
 * License: GPL-2.0-or-later
 * Text Domain: vk-trends
 */

defined( 'ABSPATH' ) || exit;
define( 'VKT_VERSION', '0.19.2' );
define( 'VKT_DIR', plugin_dir_path( __FILE__ ) );
define( 'VKT_URL', plugin_dir_url( __FILE__ ) );

require_once VKT_DIR . 'includes/class-store.php';
require_once VKT_DIR . 'includes/class-posts.php';
require_once VKT_DIR . 'includes/class-links.php';
require_once VKT_DIR . 'includes/class-commerce.php';
require_once VKT_DIR . 'includes/class-product-page.php';
require_once VKT_DIR . 'includes/class-tokens.php';
require_once VKT_DIR . 'includes/class-api.php';
require_once VKT_DIR . 'includes/class-community.php';
require_once VKT_DIR . 'includes/class-vkid.php';
require_once VKT_DIR . 'includes/class-oauth.php';
require_once VKT_DIR . 'includes/class-media.php';
require_once VKT_DIR . 'includes/class-ai.php';
require_once VKT_DIR . 'includes/class-publisher.php';
require_once VKT_DIR . 'includes/class-collector.php';
require_once VKT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'VKT_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VKT_Plugin', 'deactivate' ) );
VKT_Plugin::boot();
