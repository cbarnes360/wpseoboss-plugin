<?php
/**
 * Plugin Name:       WPSeoBoss Connector
 * Plugin URI:        https://wpseoboss.com
 * Description:       Connects your WordPress site to WPSeoBoss for AI-powered SEO fix write-back.
 * Version:           1.3.0
 * Author:            WPSeoBoss
 * Author URI:        https://wpseoboss.com
 * License:           GPL-2.0-or-later
 * Text Domain:       wpseoboss
 * GitHub Plugin URI: https://github.com/cbarnes360/wpseoboss-plugin
 * Primary Branch:    main
 */

defined('ABSPATH') || exit;

define('WPSEOBOSS_VERSION', '1.3.0');
define('WPSEOBOSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSEOBOSS_OPTION_KEY', 'wpseoboss_api_key');
define('WPSEOBOSS_APP_URL', 'https://app.wpseoboss.com');

require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-detector.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-writer.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-api.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-admin.php';
require_once WPSEOBOSS_PLUGIN_DIR . 'includes/class-tasks.php';

register_activation_hook(__FILE__, 'wpseoboss_activate');
register_deactivation_hook(__FILE__, 'wpseoboss_deactivate');
add_action('rest_api_init', ['WPSeoBoss_API', 'register_routes']);
add_action('init', 'wpseoboss_handle_direct_request');
add_action('wp_ajax_nopriv_wpseoboss_status', 'wpseoboss_ajax_status');
add_action('wp_ajax_wpseoboss_status', 'wpseoboss_ajax_status');
add_action('admin_menu', ['WPSeoBoss_Admin', 'add_menu']);
add_action('admin_init', ['WPSeoBoss_Admin', 'register_settings']);
add_action('init', 'wpseoboss_register_updater');

// Push registration: fires on every admin page load (catches the settings page visit)
add_action('admin_init', function() {
    if ( get_option( WPSEOBOSS_OPTION_KEY ) ) {
        WPSeoBoss_Tasks::register();
    }
});

// Cron: register + poll every minute
add_filter('cron_schedules', function( $schedules ) {
    $schedules['wpseoboss_every_minute'] = [ 'interval' => 60, 'display' => 'Every Minute (WPSeoBoss)' ];
    return $schedules;
});
add_action('wpseoboss_task_cron', ['WPSeoBoss_Tasks', 'run_cron']);

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

// admin-ajax.php fallback — bypasses WAFs blocking /wp-json/ and direct query params.
function wpseoboss_ajax_status() {
    $provided = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
    $stored   = (string) get_option( WPSEOBOSS_OPTION_KEY, '' );
    if ( ! $provided || ! hash_equals( $stored, $provided ) ) {
        wp_send_json( [ 'error' => 'Invalid API key' ], 401 );
    }
    wp_send_json( [ 'connected' => true, 'info' => WPSeoBoss_Detector::get_site_info() ] );
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
    if ( ! get_option( WPSEOBOSS_OPTION_KEY ) ) {
        update_option( WPSEOBOSS_OPTION_KEY, wp_generate_password( 32, false ) );
    }
    WPSeoBoss_Tasks::schedule_cron();
    // Register immediately on activation (non-blocking)
    WPSeoBoss_Tasks::register();
}

function wpseoboss_deactivate() {
    WPSeoBoss_Tasks::clear_cron();
}
