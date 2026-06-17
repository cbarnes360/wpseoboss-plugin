<?php
defined('ABSPATH') || exit;

class WPSeoBoss_API {

    public static function register_routes(): void {
        $namespace = 'wpseoboss/v1';

        register_rest_route($namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_status'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);

        register_rest_route($namespace, '/posts', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_get_posts'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);

        register_rest_route($namespace, '/categories', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_get_categories'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);

        register_rest_route($namespace, '/publish', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_publish'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);

        register_rest_route($namespace, '/apply-fix', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_apply_fix'],
            'permission_callback' => [self::class, 'verify_api_key'],
        ]);
    }

    public static function verify_api_key(\WP_REST_Request $request): bool {
        // Accept key via header (preferred) or query param (fallback for WAF-restricted hosts)
        $provided = $request->get_header('X-WPSeoBoss-Key')
                 ?: $request->get_param('key');
        $stored   = get_option(WPSEOBOSS_OPTION_KEY);
        return $provided && hash_equals((string) $stored, (string) $provided);
    }

    public static function handle_status(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'connected' => true,
            'info'      => WPSeoBoss_Detector::get_site_info(),
        ], 200);
    }

    // GET /wpseoboss/v1/posts?type=post|page|any&page=1&per_page=20
    public static function handle_get_posts(\WP_REST_Request $request): \WP_REST_Response {
        $type     = sanitize_text_field($request->get_param('type') ?? 'any');
        $page     = max(1, intval($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?? 20)));

        $post_types = ($type === 'any') ? ['post', 'page'] : [$type];

        $query = new WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $seo_plugin = WPSeoBoss_Detector::detect_seo_plugin();
        $posts      = [];

        foreach ($query->posts as $post) {
            $posts[] = self::format_post($post, $seo_plugin);
        }

        return new \WP_REST_Response([
            'posts' => $posts,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ], 200);
    }

    // Public alias used by WPSeoBoss_Tasks for scan task execution
    public static function format_post_public(\WP_Post $post, string $seo_plugin): array {
        return self::format_post($post, $seo_plugin);
    }

    private static function format_post(\WP_Post $post, string $seo_plugin): array {
        // Build yoast_head_json-compatible shape for all supported SEO plugins
        $seo_title = '';
        $seo_desc  = '';
        $focus_kw  = '';

        if ($seo_plugin === 'yoast') {
            $seo_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
            $seo_desc  = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
            $focus_kw  = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
        } elseif ($seo_plugin === 'rankmath') {
            $seo_title = get_post_meta($post->ID, 'rank_math_title', true);
            $seo_desc  = get_post_meta($post->ID, 'rank_math_description', true);
            $focus_kw  = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        } elseif ($seo_plugin === 'aioseo') {
            $seo_title = get_post_meta($post->ID, '_aioseo_title', true);
            $seo_desc  = get_post_meta($post->ID, '_aioseo_description', true);
        }

        return [
            'id'             => $post->ID,
            'link'           => get_permalink($post->ID),
            'title'          => ['rendered' => get_the_title($post->ID)],
            'excerpt'        => ['rendered' => get_the_excerpt($post)],
            'content'        => ['rendered' => $post->post_content],
            'parent'         => (int) $post->post_parent,
            'type'           => $post->post_type,
            'status'         => $post->post_status,
            'yoast_head_json' => [
                'title'       => $seo_title ?: null,
                'description' => $seo_desc  ?: null,
                'focuskw'     => $focus_kw  ?: null,
            ],
        ];
    }

    // GET /wpseoboss/v1/categories
    public static function handle_get_categories(\WP_REST_Request $request): \WP_REST_Response {
        $cats = get_categories(['hide_empty' => false, 'number' => 500]);
        $data = [];
        foreach ($cats as $cat) {
            $data[] = [
                'id'     => $cat->term_id,
                'name'   => $cat->name,
                'slug'   => $cat->slug,
                'parent' => $cat->parent,
                'count'  => $cat->count,
            ];
        }
        return new \WP_REST_Response($data, 200);
    }

    /** Used by WPSeoBoss_Tasks to execute publish tasks from the task queue. */
    public static function publish_from_payload( array $params ): array {
        $title          = sanitize_text_field($params['title']          ?? '');
        $content        = wp_kses_post($params['content']               ?? '');
        $status         = in_array($params['status'] ?? 'draft', ['publish', 'draft', 'pending']) ? $params['status'] : 'draft';
        $category_ids   = array_map('intval', $params['category_ids']   ?? []);
        $meta_title     = sanitize_text_field($params['meta_title']     ?? '');
        $meta_desc      = sanitize_textarea_field($params['meta_description'] ?? '');
        $focus_keyword  = sanitize_text_field($params['focus_keyword']  ?? '');
        $featured_image = $params['featured_image']                     ?? '';
        $img_filename   = sanitize_file_name($params['featured_image_filename'] ?? 'featured-image.jpg');

        if (!$title) return ['error' => 'title is required'];

        $featured_media_id = 0;
        if ($featured_image && preg_match('/^data:(image\/[a-z+]+);base64,(.+)$/i', $featured_image, $m)) {
            $image_data = base64_decode($m[2]);
            if ($image_data) {
                $upload = wp_upload_bits($img_filename, null, $image_data);
                if (!$upload['error']) {
                    $att_id = wp_insert_attachment(['post_mime_type' => $m[1], 'post_title' => sanitize_file_name($img_filename), 'post_status' => 'inherit'], $upload['file']);
                    if ($att_id && !is_wp_error($att_id)) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $upload['file']));
                        $featured_media_id = $att_id;
                    }
                }
            }
        }

        $post_data = ['post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'post_type' => 'post', 'post_category' => $category_ids ?: []];
        if ($featured_media_id) $post_data['meta_input'] = ['_thumbnail_id' => $featured_media_id];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) return ['error' => $post_id->get_error_message()];
        if ($featured_media_id) set_post_thumbnail($post_id, $featured_media_id);

        $seo_plugin = WPSeoBoss_Detector::detect_seo_plugin();
        if ($seo_plugin === 'yoast') {
            if ($meta_title)    update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            if ($meta_desc)     update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            if ($focus_keyword) update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        } elseif ($seo_plugin === 'rankmath') {
            if ($meta_title)    update_post_meta($post_id, 'rank_math_title', $meta_title);
            if ($meta_desc)     update_post_meta($post_id, 'rank_math_description', $meta_desc);
            if ($focus_keyword) update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        } elseif ($seo_plugin === 'aioseo') {
            if ($meta_title) update_post_meta($post_id, '_aioseo_title', $meta_title);
            if ($meta_desc)  update_post_meta($post_id, '_aioseo_description', $meta_desc);
        }

        return ['success' => true, 'id' => $post_id, 'link' => get_permalink($post_id)];
    }

    // POST /wpseoboss/v1/publish
    public static function handle_publish(\WP_REST_Request $request): \WP_REST_Response {
        $params         = $request->get_json_params();
        $title          = sanitize_text_field($params['title']          ?? '');
        $content        = wp_kses_post($params['content']               ?? '');
        $status         = in_array($params['status'] ?? 'draft', ['publish', 'draft', 'pending']) ? $params['status'] : 'draft';
        $category_ids   = array_map('intval', $params['category_ids']   ?? []);
        $meta_title     = sanitize_text_field($params['meta_title']     ?? '');
        $meta_desc      = sanitize_textarea_field($params['meta_description'] ?? '');
        $focus_keyword  = sanitize_text_field($params['focus_keyword']  ?? '');
        $featured_image = $params['featured_image']                     ?? ''; // base64 data URL
        $img_filename   = sanitize_file_name($params['featured_image_filename'] ?? 'featured-image.jpg');

        if (!$title) {
            return new \WP_REST_Response(['error' => 'title is required'], 400);
        }

        // Upload featured image if provided
        $featured_media_id = 0;
        if ($featured_image && preg_match('/^data:(image\/[a-z+]+);base64,(.+)$/i', $featured_image, $m)) {
            $image_data = base64_decode($m[2]);
            if ($image_data) {
                $upload = wp_upload_bits($img_filename, null, $image_data);
                if (!$upload['error']) {
                    $attachment = [
                        'post_mime_type' => $m[1],
                        'post_title'     => sanitize_file_name($img_filename),
                        'post_status'    => 'inherit',
                    ];
                    $att_id = wp_insert_attachment($attachment, $upload['file']);
                    if ($att_id && !is_wp_error($att_id)) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $meta = wp_generate_attachment_metadata($att_id, $upload['file']);
                        wp_update_attachment_metadata($att_id, $meta);
                        $featured_media_id = $att_id;
                    }
                }
            }
        }

        $post_data = [
            'post_title'    => $title,
            'post_content'  => $content,
            'post_status'   => $status,
            'post_type'     => 'post',
            'post_category' => $category_ids ?: [],
        ];
        if ($featured_media_id) {
            $post_data['meta_input'] = ['_thumbnail_id' => $featured_media_id];
        }

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return new \WP_REST_Response(['error' => $post_id->get_error_message()], 500);
        }

        // Set featured image properly
        if ($featured_media_id) {
            set_post_thumbnail($post_id, $featured_media_id);
        }

        // Write SEO meta
        $seo_plugin = WPSeoBoss_Detector::detect_seo_plugin();
        if ($seo_plugin === 'yoast') {
            if ($meta_title)    update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            if ($meta_desc)     update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            if ($focus_keyword) update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        } elseif ($seo_plugin === 'rankmath') {
            if ($meta_title)    update_post_meta($post_id, 'rank_math_title', $meta_title);
            if ($meta_desc)     update_post_meta($post_id, 'rank_math_description', $meta_desc);
            if ($focus_keyword) update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        } elseif ($seo_plugin === 'aioseo') {
            if ($meta_title) update_post_meta($post_id, '_aioseo_title', $meta_title);
            if ($meta_desc)  update_post_meta($post_id, '_aioseo_description', $meta_desc);
        }

        return new \WP_REST_Response([
            'success' => true,
            'id'      => $post_id,
            'link'    => get_permalink($post_id),
        ], 200);
    }

    public static function handle_apply_fix(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_json_params();

        $post_id       = intval($params['post_id'] ?? 0);
        $seo_plugin    = sanitize_text_field($params['seo_plugin'] ?? 'none');
        $page_builder  = sanitize_text_field($params['page_builder'] ?? 'gutenberg');
        $meta_title    = sanitize_text_field($params['meta_title'] ?? '');
        $meta_desc     = sanitize_textarea_field($params['meta_description'] ?? '');
        $focus_keyword = sanitize_text_field($params['focus_keyword'] ?? '');
        $content       = $params['content'] ?? '';

        if (!$post_id || !get_post($post_id)) {
            return new \WP_REST_Response(['error' => 'Invalid post_id'], 400);
        }

        $results = [];

        if ($meta_title || $meta_desc || $focus_keyword) {
            $results['seo_meta'] = WPSeoBoss_Writer::write_seo_meta($post_id, $meta_title, $meta_desc, $seo_plugin, $focus_keyword);
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
