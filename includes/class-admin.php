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
        $connect_url  = WPSEOBOSS_APP_URL . '/sites';

        ?>
        <div class="wrap">
            <h1>WPSeoBoss Connector</h1>
            <p>Connect this site to <a href="<?php echo esc_url(WPSEOBOSS_APP_URL); ?>" target="_blank">WPSeoBoss</a> to enable AI-powered SEO analysis, scanning, and content publishing.</p>

            <h2>Connection</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Plugin API Key</th>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input
                                type="text"
                                id="wpseoboss-api-key"
                                value="<?php echo esc_attr($api_key); ?>"
                                readonly
                                style="width:340px;font-family:monospace;font-size:13px;"
                            />
                            <button
                                type="button"
                                id="wpseoboss-copy-btn"
                                class="button"
                                onclick="
                                    navigator.clipboard.writeText(document.getElementById('wpseoboss-api-key').value)
                                        .then(function(){
                                            var btn = document.getElementById('wpseoboss-copy-btn');
                                            btn.textContent = 'Copied!';
                                            setTimeout(function(){ btn.textContent = 'Copy Key'; }, 2000);
                                        });
                                "
                            >Copy Key</button>
                        </div>
                        <p class="description">Copy this key, then paste it into WPSeoBoss when connecting your site.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Connect in WPSeoBoss</th>
                    <td>
                        <a href="<?php echo esc_url($connect_url); ?>" class="button button-primary" target="_blank">
                            Open WPSeoBoss &rarr;
                        </a>
                        <p class="description">Go to Sites &rarr; Edit your site &rarr; Plugin section &rarr; paste the key above.</p>
                    </td>
                </tr>
            </table>

            <h2>Site Detection</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">SEO Plugin</th>
                    <td><strong><?php echo esc_html(ucfirst($site_info['seo_plugin'])); ?></strong></td>
                </tr>
                <tr>
                    <th scope="row">Page Builder</th>
                    <td><strong><?php echo esc_html(ucfirst($site_info['page_builder'])); ?></strong></td>
                </tr>
                <tr>
                    <th scope="row">WordPress Version</th>
                    <td><?php echo esc_html($site_info['wp_version']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Plugin Version</th>
                    <td><?php echo esc_html(WPSEOBOSS_VERSION); ?></td>
                </tr>
            </table>

            <h2>Available Endpoints</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Status</th>
                    <td><code><?php echo esc_html(get_rest_url(null, 'wpseoboss/v1/status')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Posts / Pages</th>
                    <td><code><?php echo esc_html(get_rest_url(null, 'wpseoboss/v1/posts')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Categories</th>
                    <td><code><?php echo esc_html(get_rest_url(null, 'wpseoboss/v1/categories')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Publish</th>
                    <td><code><?php echo esc_html(get_rest_url(null, 'wpseoboss/v1/publish')); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Apply Fix</th>
                    <td><code><?php echo esc_html(get_rest_url(null, 'wpseoboss/v1/apply-fix')); ?></code></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
