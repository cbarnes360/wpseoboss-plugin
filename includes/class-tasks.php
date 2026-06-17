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

        $seo_plugin  = WPSeoBoss_Detector::detect_seo_plugin();
        $per_page    = 50;
        $page        = 1;
        $max_pages   = null;
        $total_sent  = 0;
        $diag        = [];

        // Direct $wpdb query bypasses WP_Query and all pre_get_posts filters.
        // AIOSEO / Elementor register filters that return 0 results in admin-ajax
        // context; going directly to the database avoids them entirely.
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
                $diag['posts_table'] = $wpdb->posts;
                $diag['db_error']    = $wpdb->last_error ?: null;
                $diag['rows_p1']     = is_array( $rows ) ? count( $rows ) : gettype( $rows );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $total     = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
                );
                $diag['total']       = $total;
                $diag['count_err']   = $wpdb->last_error ?: null;
                $max_pages = max( 1, (int) ceil( $total / $per_page ) );
            }

            if ( empty( $rows ) ) break;

            // Batch-load all post meta for this page in one query (same as update_post_meta_cache)
            $ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
            update_meta_cache( 'post', $ids );

            $batch = [];
            foreach ( $rows as $row ) {
                $batch[] = WPSeoBoss_API::format_post_public( new WP_Post( $row ), $seo_plugin );
            }
            self::post_batch( $task_id, $key, $batch );
            $total_sent += count( $batch );

            $page++;
        } while ( $page <= $max_pages );

        if ( $total_sent === 0 ) {
            // No posts sent — fail with diagnostics so we can see the root cause
            self::fail_task( $task_id, $key, 'wpdb_zero diag=' . wp_json_encode( $diag ) );
            return;
        }

        // Signal completion — server uses the accumulated batches for AI analysis
        self::complete_task( $task_id, $key, [] );
    }

    private static function post_batch( string $task_id, string $key, array $posts ): void {
        wp_remote_post(
            self::APP_URL . '/api/plugin/tasks/' . rawurlencode( $task_id ) . '/posts?key=' . rawurlencode( $key ),
            [
                'body'      => wp_json_encode( [ 'posts' => $posts ] ),
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'timeout'   => 30,
                'sslverify' => true,
            ]
        );
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
