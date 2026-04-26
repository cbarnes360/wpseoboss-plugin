<?php
defined('ABSPATH') || exit;

class WPSeoBoss_Writer {

    // ── SEO Meta Write-back ──────────────────────────────────────────────────

    public static function write_seo_meta(int $post_id, string $title, string $description, string $seo_plugin, string $focus_keyword = ''): bool {
        switch ($seo_plugin) {
            case 'yoast':
                return self::write_yoast($post_id, $title, $description, $focus_keyword);
            case 'rankmath':
                return self::write_rankmath($post_id, $title, $description, $focus_keyword);
            case 'aioseo':
                return self::write_aioseo($post_id, $title, $description, $focus_keyword);
            default:
                wp_update_post(['ID' => $post_id, 'post_title' => $title]);
                return true;
        }
    }

    private static function write_yoast(int $post_id, string $title, string $description, string $focus_keyword): bool {
        update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($title));
        update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($description));
        if ($focus_keyword) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focus_keyword));
        }
        return true;
    }

    private static function write_rankmath(int $post_id, string $title, string $description, string $focus_keyword): bool {
        update_post_meta($post_id, 'rank_math_title', sanitize_text_field($title));
        update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($description));
        if ($focus_keyword) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($focus_keyword));
        }
        return true;
    }

    private static function write_aioseo(int $post_id, string $title, string $description, string $focus_keyword): bool {
        update_post_meta($post_id, '_aioseo_title', sanitize_text_field($title));
        update_post_meta($post_id, '_aioseo_description', sanitize_textarea_field($description));
        if ($focus_keyword) {
            update_post_meta($post_id, '_aioseo_keyphrases', wp_json_encode([['keyphrase' => sanitize_text_field($focus_keyword), 'score' => 0]]));
        }
        return true;
    }

    // ── Content Write-back ───────────────────────────────────────────────────

    public static function write_content(int $post_id, string $content, string $page_builder): bool {
        switch ($page_builder) {
            case 'elementor':
                return self::write_elementor($post_id, $content);
            case 'divi':
                return self::write_divi($post_id, $content);
            default:
                return self::write_gutenberg($post_id, $content);
        }
    }

    private static function write_gutenberg(int $post_id, string $content): bool {
        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_kses_post($content),
        ]);
        return !is_wp_error($result);
    }

    private static function write_elementor(int $post_id, string $content): bool {
        // Update the raw post content — Elementor will render it via its own meta
        // For full Elementor widget write-back, the plugin must be active
        if (!class_exists('Elementor\Plugin')) {
            return self::write_gutenberg($post_id, $content);
        }

        // Write as a single HTML widget in Elementor data
        $elementor_data = [
            [
                'id'       => uniqid(),
                'elType'   => 'section',
                'settings' => [],
                'elements' => [
                    [
                        'id'       => uniqid(),
                        'elType'   => 'column',
                        'settings' => ['_column_size' => 100],
                        'elements' => [
                            [
                                'id'         => uniqid(),
                                'elType'     => 'widget',
                                'widgetType' => 'html',
                                'settings'   => ['html' => wp_kses_post($content)],
                                'elements'   => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        // Also update raw post content for non-Elementor rendering
        wp_update_post(['ID' => $post_id, 'post_content' => wp_kses_post($content)]);
        return true;
    }

    private static function write_divi(int $post_id, string $content): bool {
        // Divi stores content in post_content with shortcodes
        // Write as-is and let Divi render it
        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_kses_post($content),
        ]);
        return !is_wp_error($result);
    }
}
