<?php
defined('ABSPATH') || exit;

class WPSeoBoss_Detector {

    public static function detect_seo_plugin(): string {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'rankmath';
        }
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\Plugin\AIOSEO')) {
            return 'aioseo';
        }
        return 'none';
    }

    public static function detect_page_builder(): string {
        if (defined('ELEMENTOR_VERSION') || class_exists('Elementor\Plugin')) {
            return 'elementor';
        }
        if (defined('ET_BUILDER_VERSION') || class_exists('ET_Builder_Element')) {
            return 'divi';
        }
        // Gutenberg is always available in WP 5+
        return 'gutenberg';
    }

    public static function get_site_info(): array {
        return [
            'site_url'         => get_site_url(),
            'site_name'        => get_bloginfo('name'),
            'wp_version'       => get_bloginfo('version'),
            'plugin_version'   => WPSEOBOSS_VERSION,
            'seo_plugin'       => self::detect_seo_plugin(),
            'page_builder'     => self::detect_page_builder(),
        ];
    }
}
