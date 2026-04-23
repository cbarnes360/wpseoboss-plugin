<?php
/**
 * Plugin Name: WPSeoBoss Connector
 * Plugin URI:  https://wpseoboss.com
 * Description: Connects your WordPress site to WPSeoBoss for AI-powered SEO fix write-back.
 * Version:     1.0.0
 * Author:      WPSeoBoss
 * Author URI:  https://wpseoboss.com
 * License:     GPL-2.0-or-later
 * Text Domain: wpseoboss
 */

defined('ABSPATH') || exit;

define('WPSEOBOSS_VERSION', '1.0.0');
define('WPSEOBOSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSEOBOSS_OPTION_KEY', 'wpseoboss_api_key');
define('WPSEOBOSS_APP_URL', 'https://app.wpseoboss.com');

require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-detector.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-writer.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-api.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, 'wpseoboss_activate');
add_action('rest_api_init', ['WPSeoBoss_API', 'register_routes']);
add_action('admin_menu', ['WPSeoBoss_Admin', 'add_menu']);
add_action('admin_init', ['WPSeoBoss_Admin', 'register_settings']);

function wpseoboss_activate() {
    // Generate a unique API key for this site on activation
    if (!get_option(WPSEOBOSS_OPTION_KEY)) {
        update_option(WPSEOBOSS_OPTION_KEY, wp_generate_password(32, false));
    }
}
