<?php
defined('ABSPATH') || exit;

class WPSeoBoss_Admin {

    public static function add_menu(): void {
        add_options_page(
            'WPSeoBoss',
            'WPSeoBoss',
            'manage_options',
            'wpseoboss',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting('wpseoboss_settings', WPSEOBOSS_OPTION_KEY);
    }

    public static function render_settings_page(): void {
        $api_key      = get_option(WPSEOBOSS_OPTION_KEY, '');
        $site_info    = WPSeoBoss_Detector::get_site_info();
        $rest_url     = get_rest_url(null, 'wpseoboss/v1/status');
        $connect_url  = WPSEOBOSS_APP_URL . '/sites?connect_plugin=1';

        ?>
        <div class="wrap">
            <h1>WPSeoBoss Connector</h1>
            <p>Connect this site to <a href="<?php echo esc_url(WPSEOBOSS_APP_URL); ?>" target="_blank">WPSeoBoss</a> to enable AI-powered SEO fix write-back.</p>

            <table class="form-table">
                <tr>
                    <th>Plugin API Key</th>
                    <td>
                        <code style="font-size:14px;padding:6px 10px;background:#f0f0f0;border-radius:4px;"><?php echo esc_html($api_key); ?></code>
                        <p class="description">Copy this key into WPSeoBoss when connecting your site.</p>
                    </td>
                </tr>
                <tr>
                    <th>REST Endpoint</th>
                    <td>
                        <code><?php echo esc_html($rest_url); ?></code>
                    </td>
                </tr>
                <tr>
                    <th>Detected SEO Plugin</th>
                    <td><strong><?php echo esc_html(ucfirst($site_info['seo_plugin'])); ?></strong></td>
                </tr>
                <tr>
                    <th>Detected Page Builder</th>
                    <td><strong><?php echo esc_html(ucfirst($site_info['page_builder'])); ?></strong></td>
                </tr>
                <tr>
                    <th>WordPress Version</th>
                    <td><?php echo esc_html($site_info['wp_version']); ?></td>
                </tr>
            </table>

            <p>
                <a href="<?php echo esc_url($connect_url); ?>" class="button button-primary" target="_blank">
                    Connect in WPSeoBoss →
                </a>
            </p>
        </div>
        <?php
    }
}
