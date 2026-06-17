<?php
defined('ABSPATH') || exit;

class WPSeoBoss_Tasks {

    const APP_URL  = WPSEOBOSS_APP_URL;
    const CRON_KEY = 'wpseoboss_task_cron';

    // ── Registration ────────────────────────────────────────────────────────────

    /**
     * Call our server to register this site.
     * Runs on activation, admin page load, and via WP Cron.
     * All traffic is outbound from WordPress — no WAF issues.
     */
    public static function register(): void {
        $key = get_option( WPSEOBOSS_OPTION_KEY, '' );
        if ( ! $key ) return;

        $info = WPSeoBoss_Detector::get_site_info();

        wp_remote_post( self::APP_URL . '/api/plugin/register', [
            'body'    => wp_json_encode( [
                'key'          => $key,
                'seo_plugin'   => $info['seo_plugin']   ?? null,
                'page_builder' => $info['page_builder'] ?? null,
            ] ),
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'timeout'   => 10,
            'blocking'  => false, // fire-and-forget — don't block page load
            'sslverify' => true,
        ] );
    }

    // ── WP Cron ─────────────────────────────────────────────────────────────────

    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_KEY ) ) {
            wp_schedule_event( time(), 'wpseoboss_every_minute', self::CRON_KEY );
        }
    }

    public static function clear_cron(): void {
        wp_clear_scheduled_hook( self::CRON_KEY );
    }

    /** Runs every minute: re-register + poll for pending tasks. */
    public static function run_cron(): void {
        // Always re-register (idempotent on server side) so connection stays confirmed
        self::register_blocking();
        // Poll and execute any pending tasks
        self::poll_and_execute();
    }

    // ── Task polling ─────────────────────────────────────────────────────────────

    /**
     * Finishes the HTTP response (if FastCGI) then polls and executes any pending tasks.
     * Safe to call from a shutdown function — won't block admin page loads on PHP-FPM hosts.
     */
    public static function run_pending_background(): void {
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        }
        self::poll_and_execute();
    }

    public static function poll_and_execute(): void {
        // Running detached after fastcgi_finish_request — remove PHP time limit so long
        // scans (200+ posts) can complete without being killed by the server's 60s default.
        @set_time_limit( 0 );
        $key = get_option( WPSEOBOSS_OPTION_KEY, '' );
        if ( ! $key ) return;

        $response = wp_remote_get(
            self::APP_URL . '/api/plugin/tasks?key=' . rawurlencode( $key ),
            [ 'timeout' => 15, 'sslverify' => true ]
        );

        if ( is_wp_error( $response ) ) return;
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( empty( $data['tasks'] ) ) return;

        foreach ( $data['tasks'] as $task ) {
            self::execute_task( $task, $key );
        }
    }

    private static function execute_task( array $task, string $key ): void {
        $task_id = $task['id']   ?? '';
        $type    = $task['type'] ?? '';

        switch ( $type ) {
            case 'scan':
                self::execute_scan( $task_id, $key );
                break;
            case 'publish':
                self::execute_publish( $task_id, $task['payload'] ?? [], $key );
                break;
            case 'apply-fix':
                self::execute_apply_fix( $task_id, $task['payload'] ?? [], $key );
                break;
            default:
                self::fail_task( $task_id, $key, 'Unknown task type: ' . $type );
        }
    }

    // ── Task execution ────────────────────────────────────────────────────────────

    private static function execute_scan( string $task_id, string $key ): void {
        global $wpdb;

        $seo_plugin = WPSeoBoss_Detector::detect_seo_plugin();
        $per_page   = 50;
        $page       = 1;
        $max_pages  = null;
        $all_posts  = [];

        // Direct $wpdb query bypasses WP_Query and all pre_get_posts filters.
        // AIOSEO / Elementor register filters that suppress queries in admin-ajax
        // context; going to the DB directly avoids them entirely.
        do {
            $offset = ( $page - 1 ) * $per_page;
            $rows   = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_excerpt, post_type,
                        post_status, post_parent, post_date, guid
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post', 'page')
                 ORDER BY post_date DESC
                 LIMIT %d OFFSET %d",
                $per_page, $offset
            ) );

            if ( $page === 1 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $total     = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
                );
                $max_pages = max( 1, (int) ceil( $total / $per_page ) );
            }

            if ( empty( $rows ) ) break;

            // Batch-load all post meta for this page in one query
            $ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
            update_meta_cache( 'post', $ids );

            foreach ( $rows as $row ) {
                // Build the record directly from DB values — no WordPress filter calls.
                // format_post_public() calls get_permalink() (hierarchy DB queries) and
                // get_the_excerpt() (Elementor full-page render) — both are catastrophically
                // slow for 100+ posts in a background process.
                $seo_title = '';
                $seo_desc  = '';
                $focus_kw  = '';
                if ( $seo_plugin === 'yoast' ) {
                    $seo_title = (string) get_post_meta( (int) $row->ID, '_yoast_wpseo_title', true );
                    $seo_desc  = (string) get_post_meta( (int) $row->ID, '_yoast_wpseo_metadesc', true );
                    $focus_kw  = (string) get_post_meta( (int) $row->ID, '_yoast_wpseo_focuskw', true );
                } elseif ( $seo_plugin === 'rankmath' ) {
                    $seo_title = (string) get_post_meta( (int) $row->ID, 'rank_math_title', true );
                    $seo_desc  = (string) get_post_meta( (int) $row->ID, 'rank_math_description', true );
                    $focus_kw  = (string) get_post_meta( (int) $row->ID, 'rank_math_focus_keyword', true );
                } elseif ( $seo_plugin === 'aioseo' ) {
                    $seo_title = (string) get_post_meta( (int) $row->ID, '_aioseo_title', true );
                    $seo_desc  = (string) get_post_meta( (int) $row->ID, '_aioseo_description', true );
                }

                // Excerpt: raw field first; fallback strips tags on first 5k chars only
                // (avoids slow regex on 200KB Elementor/Divi JSON strings)
                $excerpt = trim( $row->post_excerpt ?? '' );
                if ( ! $excerpt && ! empty( $row->post_content ) ) {
                    $excerpt = wp_trim_words( wp_strip_all_tags( substr( $row->post_content, 0, 5000 ) ), 55, '...' );
                }

                // Content: cap at 500 chars for scan payload — keeps total POST body
                // under 100KB regardless of site size or page builder used
                $content = $row->post_content ?? '';
                if ( strlen( $content ) > 500 ) {
                    $content = substr( $content, 0, 500 );
                }

                $all_posts[] = [
                    'id'     => (int) $row->ID,
                    'link'   => $row->guid,  // guid avoids get_permalink() hierarchy queries
                    'title'  => [ 'rendered' => html_entity_decode( $row->post_title ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ],
                    'excerpt' => [ 'rendered' => $excerpt ],
                    'content' => [ 'rendered' => $content ],
                    'parent'  => (int) $row->post_parent,
                    'type'    => $row->post_type,
                    'status'  => $row->post_status,
                    'yoast_head_json' => [
                        'title'       => $seo_title ?: null,
                        'description' => $seo_desc  ?: null,
                        'focuskw'     => $focus_kw  ?: null,
                    ],
                ];
            }

            $page++;
        } while ( $page <= $max_pages );

        if ( empty( $all_posts ) ) {
            self::fail_task( $task_id, $key, 'No published posts/pages found (table=' . $wpdb->posts . ' db_err=' . ( $wpdb->last_error ?: 'none' ) . ')' );
            return;
        }

        // Deliver everything in one done call — no intermediate /posts round trips.
        // One outbound HTTP call is reliable across all WordPress hosting environments.
        self::complete_task( $task_id, $key, [ 'posts' => $all_posts ] );
    }

    private static function execute_publish( string $task_id, array $payload, string $key ): void {
        // Delegate to class-api.php publish handler
        $result = WPSeoBoss_API::publish_from_payload( $payload );
        if ( isset( $result['error'] ) ) {
            self::fail_task( $task_id, $key, $result['error'] );
        } else {
            self::complete_task( $task_id, $key, $result );
        }
    }

    private static function execute_apply_fix( string $task_id, array $payload, string $key ): void {
        $post_id      = intval( $payload['post_id'] ?? 0 );
        $seo_plugin   = sanitize_text_field( $payload['seo_plugin'] ?? 'none' );
        $page_builder = sanitize_text_field( $payload['page_builder'] ?? 'gutenberg' );
        $meta_title   = sanitize_text_field( $payload['meta_title'] ?? '' );
        $meta_desc    = sanitize_textarea_field( $payload['meta_description'] ?? '' );
        $focus_kw     = sanitize_text_field( $payload['focus_keyword'] ?? '' );
        $content      = $payload['content'] ?? '';

        if ( ! $post_id || ! get_post( $post_id ) ) {
            self::fail_task( $task_id, $key, 'Invalid post_id' );
            return;
        }

        $results = [];
        if ( $meta_title || $meta_desc || $focus_kw ) {
            $results['seo_meta'] = WPSeoBoss_Writer::write_seo_meta( $post_id, $meta_title, $meta_desc, $seo_plugin, $focus_kw );
        }
        if ( $content ) {
            $results['content'] = WPSeoBoss_Writer::write_content( $post_id, $content, $page_builder );
        }

        self::complete_task( $task_id, $key, [ 'success' => true, 'results' => $results ] );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private static function complete_task( string $task_id, string $key, array $result ): void {
        wp_remote_post(
            self::APP_URL . '/api/plugin/tasks/' . rawurlencode( $task_id ) . '/done?key=' . rawurlencode( $key ),
            [
                'body'      => wp_json_encode( [ 'result' => $result ] ),
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'timeout'   => 30,
                'sslverify' => true,
            ]
        );
    }

    private static function fail_task( string $task_id, string $key, string $error ): void {
        wp_remote_post(
            self::APP_URL . '/api/plugin/tasks/' . rawurlencode( $task_id ) . '/fail?key=' . rawurlencode( $key ),
            [
                'body'      => wp_json_encode( [ 'error' => $error ] ),
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'timeout'   => 10,
                'sslverify' => true,
            ]
        );
    }

    /** Blocking version of register — used in cron where we want to confirm before polling tasks. */
    private static function register_blocking(): void {
        $key = get_option( WPSEOBOSS_OPTION_KEY, '' );
        if ( ! $key ) return;
        $info = WPSeoBoss_Detector::get_site_info();
        wp_remote_post( self::APP_URL . '/api/plugin/register', [
            'body'      => wp_json_encode( [
                'key'          => $key,
                'seo_plugin'   => $info['seo_plugin']   ?? null,
                'page_builder' => $info['page_builder'] ?? null,
            ] ),
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'timeout'   => 10,
            'blocking'  => true,
            'sslverify' => true,
        ] );
    }
}
