<?php
defined('ABSPATH') || exit;

class WPSeoBoss_API {

    public static function register_routes(): void {
        $namespace = 'wpseoboss/v1';

        // Health check + site info (used during connection flow)
        register_rest_route($namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_status'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);

        // Apply SEO fix (meta title, description, content)
        register_rest_route($namespace, '/apply-fix', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_apply_fix'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);
    }

    public static function verify_api_key(\WP_REST_Request $request): bool {
        $provided = $request->get_header('X-WPSeoBoss-Key');
        $stored   = get_option(WPSEOBOSS_OPTION_KEY);
        return $provided && hash_equals((string) $stored, (string) $provided);
    }

    public static function handle_status(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'connected' => true,
            'info'      => WPSeoBoss_Detector::get_site_info(),
        ], 200);
    }

    public static function handle_apply_fix(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_json_params();

        $post_id      = intval($params['post_id'] ?? 0);
        $seo_plugin   = sanitize_text_field($params['seo_plugin'] ?? 'none');
        $page_builder = sanitize_text_field($params['page_builder'] ?? 'gutenberg');
        $meta_title   = sanitize_text_field($params['meta_title'] ?? '');
        $meta_desc    = sanitize_textarea_field($params['meta_description'] ?? '');
        $content      = $params['content'] ?? '';

        if (!$post_id || !get_post($post_id)) {
            return new \WP_REST_Response(['error' => 'Invalid post_id'], 400);
        }

        $results = [];

        if ($meta_title || $meta_desc) {
            $results['seo_meta'] = WPSeoBoss_Writer::write_seo_meta($post_id, $meta_title, $meta_desc, $seo_plugin);
        }

        if ($content) {
            $results['content'] = WPSeoBoss_Writer::write_content($post_id, $content, $page_builder);
        }

        return new \WP_REST_Response([
            'success' => true,
            'results' => $results,
        ], 200);
    }
}
