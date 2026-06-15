<?php
/**
 * Plugin Name:       WPSeoBoss Connector
 * Plugin URI:        https://wpseoboss.com
 * Description:       Connects your WordPress site to WPSeoBoss for AI-powered SEO fix write-back.
 * Version:           1.2.4
 * Author:            WPSeoBoss
 * Author URI:        https://wpseoboss.com
 * License:           GPL-2.0-or-later
 * Text Domain:       wpseoboss
 * GitHub Plugin URI: https://github.com/cbarnes360/wpseoboss-plugin
 * Primary Branch:    main
 */

defined('ABSPATH') || exit;

define('WPSEOBOSS_VERSION', '1.2.4');
define('WPSEOBOSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSEOBOSS_OPTION_KEY', 'wpseoboss_api_key');
define('WPSEOBOSS_APP_URL', 'https://app.wpseoboss.com');

require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-detector.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-writer.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-api.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, 'wpseoboss_activate');
add_action('rest_api_init', ['WPSeoBoss_API', 'register_routes']);
add_action('init', 'wpseoboss_handle_direct_request');
add_action('admin_menu', ['WPSeoBoss_Admin', 'add_menu']);
add_action('admin_init', ['WPSeoBoss_Admin', 'register_settings']);
add_action('init', 'wpseoboss_register_updater');

// Allow wpseoboss/v1 requests through security plugins that block unauthenticated REST API access.
// Our endpoints do their own key-based authentication via verify_api_key().
add_filter('rest_authentication_errors', 'wpseoboss_allow_rest_access', 999);

function wpseoboss_allow_rest_access( $result ) {
    if ( ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
        $route = $GLOBALS['wp']->query_vars['rest_route'];
    } else {
        $route = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    }
    if ( strpos( $route, '/wpseoboss/v1/' ) !== false || strpos( $route, '/wpseoboss/v1' ) !== false ) {
        return null; // Clear any authentication error — our permission callback handles auth
    }
    return $result;
}

function wpseoboss_register_updater() {
    if ( ! is_admin() ) return;
    $checker = WPSEOBOSS_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $checker ) ) return;
    require_once $checker;
    $updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/cbarnes360/wpseoboss-plugin/',
        __FILE__,
        'wpseoboss-connector'
    );
    $updater->setBranch('main');
}

// Direct non-REST endpoint — bypasses WAFs that block /wp-json/ entirely.
// Triggered by /?wpseoboss_action=status&key=xxx on any page URL.
function wpseoboss_handle_direct_request() {
    if ( empty( $_GET['wpseoboss_action'] ) ) return;

    $provided = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
    $stored   = (string) get_option( WPSEOBOSS_OPTION_KEY, '' );

    if ( ! $provided || ! hash_equals( $stored, $provided ) ) {
        wp_send_json( [ 'error' => 'Invalid API key' ], 401 );
    }

    $action = sanitize_text_field( $_GET['wpseoboss_action'] );

    if ( $action === 'status' ) {
        wp_send_json( [ 'connected' => true, 'info' => WPSeoBoss_Detector::get_site_info() ] );
    }

    wp_send_json( [ 'error' => 'Unknown action' ], 400 );
}

function wpseoboss_activate() {
    // Generate a unique API key for this site on activation
    if (!get_option(WPSEOBOSS_OPTION_KEY)) {
        update_option(WPSEOBOSS_OPTION_KEY, wp_generate_password(32, false));
    }
}
