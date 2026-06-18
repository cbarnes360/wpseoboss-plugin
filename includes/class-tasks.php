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

    private static function diag( string $step, array $extra = [] ): void {
        update_option( 'wpseoboss_scan_diag', array_merge( [ 'step' => $step, 'ts' => time(), 'mem' => memory_get_usage(true) ], $extra ), false );
    }

    private static function execute_scan( string $task_id, string $key ): void {
        global $wpdb;

        self::diag( 'started', [ 'task_id' => $task_id ] );

        $seo_plugin = WPSeoBoss_Detector::detect_seo_plugin();

        self::diag( 'seo_plugin_detected', [ 'seo_plugin' => $seo_plugin ] );

        $per_page  = 50;
        $page      = 1;
        $max_pages = null;
        $all_posts = [];

        // Direct $wpdb queries for everything — bypasses WP_Query, pre_get_posts,
        // get_post_metadata, and all other WordPress filters. No WP function calls
        // in the inner loop; no filter can intercept or kill the PHP process here.
        do {
            $offset = ( $page - 1 ) * $per_page;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_excerpt, post_type,
                        post_status, post_parent, post_date, guid
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post', 'page')
                 ORDER BY post_date DESC
                 LIMIT %d OFFSET %d",
                $per_page, $offset
            ) );

            if ( $page === 1 ) {
                $total     = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
                );
                $max_pages = max( 1, (int) ceil( $total / $per_page ) );
                self::diag( 'page_1_queried', [ 'total' => $total, 'max_pages' => $max_pages, 'rows_got' => count( $rows ) ] );
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery

            if ( empty( $rows ) ) break;

            // Build post ID list for the batch meta query
            $ids         = [];
            $rows_by_id  = [];
            foreach ( $rows as $row ) {
                $ids[]              = (int) $row->ID;
                $rows_by_id[ (int) $row->ID ] = $row;
            }

            // Fetch all needed SEO meta in ONE direct query per page — bypasses
            // get_post_metadata filter (which AIOSEO hooks to compute dynamic values)
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders
            $meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ($placeholders)
                 AND meta_key IN (
                     '_aioseo_title','_aioseo_description',
                     '_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw',
                     'rank_math_title','rank_math_description','rank_math_focus_keyword'
                 )",
                ...$ids
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders

            // Index meta by [post_id][meta_key]
            $meta = [];
            foreach ( $meta_rows as $mr ) {
                $meta[ (int) $mr->post_id ][ $mr->meta_key ] = $mr->meta_value;
            }

            foreach ( $rows as $row ) {
                $pid = (int) $row->ID;
                $pm  = $meta[ $pid ] ?? [];

                $seo_title = '';
                $seo_desc  = '';
                $focus_kw  = '';
                if ( $seo_plugin === 'yoast' ) {
                    $seo_title = (string) ( $pm['_yoast_wpseo_title']   ?? '' );
                    $seo_desc  = (string) ( $pm['_yoast_wpseo_metadesc'] ?? '' );
                    $focus_kw  = (string) ( $pm['_yoast_wpseo_focuskw']  ?? '' );
                } elseif ( $seo_plugin === 'rankmath' ) {
                    $seo_title = (string) ( $pm['rank_math_title']       ?? '' );
                    $seo_desc  = (string) ( $pm['rank_math_description'] ?? '' );
                    $focus_kw  = (string) ( $pm['rank_math_focus_keyword'] ?? '' );
                } elseif ( $seo_plugin === 'aioseo' ) {
                    $seo_title = (string) ( $pm['_aioseo_title']       ?? '' );
                    $seo_desc  = (string) ( $pm['_aioseo_description'] ?? '' );
                }

                // Excerpt: raw field first; fallback uses PHP-native string ops only
                // (wp_trim_words hooks excerpt_length/excerpt_more — avoid filters)
                $excerpt = trim( $row->post_excerpt ?? '' );
                if ( ! $excerpt && ! empty( $row->post_content ) ) {
                    $stripped = strip_tags( substr( $row->post_content, 0, 5000 ) );
                    $words    = preg_split( '/\s+/u', trim( $stripped ), 57 );
                    $excerpt  = count( $words ) > 55
                        ? implode( ' ', array_slice( $words, 0, 55 ) ) . '...'
                        : implode( ' ', $words );
                }

                // Content capped to 500 chars — all we need for SEO analysis
                $content = isset( $row->post_content ) ? substr( $row->post_content, 0, 500 ) : '';

                $all_posts[] = [
                    'id'      => $pid,
                    'link'    => (string) get_permalink( $pid ),
                    'title'   => [ 'rendered' => html_entity_decode( $row->post_title ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ],
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

            self::diag( 'page_done', [ 'page' => $page, 'accumulated' => count( $all_posts ) ] );
            $page++;
        } while ( $page <= $max_pages );

        self::diag( 'loop_complete', [ 'total_posts' => count( $all_posts ) ] );

        if ( empty( $all_posts ) ) {
            self::diag( 'failing_no_posts', [ 'db_err' => $wpdb->last_error ] );
            self::fail_task( $task_id, $key, 'No published posts/pages found (table=' . $wpdb->posts . ' db_err=' . ( $wpdb->last_error ?: 'none' ) . ')' );
            return;
        }

        $body = (string) json_encode( [ 'result' => [ 'posts' => $all_posts ] ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
        self::diag( 'json_encoded', [ 'body_bytes' => strlen( $body ), 'post_count' => count( $all_posts ) ] );

        self::complete_task_raw( $task_id, $key, $body );
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
        $body = (string) json_encode( [ 'result' => $result ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
        self::complete_task_raw( $task_id, $key, $body );
    }

    private static function complete_task_raw( string $task_id, string $key, string $body ): void {
        $url = self::APP_URL . '/api/plugin/tasks/' . rawurlencode( $task_id ) . '/done?key=' . rawurlencode( $key );

        // Raw cURL bypasses wp_remote_post() and all pre_http_request filters so security
        // plugins (AIOSEO, Wordfence) cannot intercept and kill the PHP process.
        if ( function_exists( 'curl_init' ) ) {
            self::diag( 'curl_starting', [ 'url' => $url, 'body_bytes' => strlen( $body ) ] );
            $ch = curl_init( $url );
            curl_setopt_array( $ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json', 'Content-Length: ' . strlen( $body ) ],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ] );
            $response  = curl_exec( $ch );
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $errno     = curl_errno( $ch );
            $errmsg    = curl_error( $ch );
            curl_close( $ch );
            self::diag( 'curl_done', [
                'http_code' => $http_code,
                'errno'     => $errno,
                'errmsg'    => $errmsg,
                'resp_len'  => is_string( $response ) ? strlen( $response ) : -1,
            ] );
            // If server rejected the body (4xx/5xx), call /fail so the task surface an error
            // instead of silently staying 'running' forever.
            if ( $http_code < 200 || $http_code >= 300 ) {
                self::fail_task( $task_id, $key, 'done endpoint returned HTTP ' . $http_code . ' (curl_errno=' . $errno . ')' );
            }
        } else {
            self::diag( 'curl_unavailable_using_wp_remote' );
            wp_remote_post( $url, [
                'body'      => $body,
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'timeout'   => 30,
                'sslverify' => true,
            ] );
            self::diag( 'wp_remote_post_done' );
        }
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
