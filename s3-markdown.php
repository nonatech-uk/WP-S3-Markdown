<?php
/**
 * Plugin Name: S3 Markdown
 * Description: Render markdown files from an AWS S3 bucket via shortcode
 * Version: 1.2.0
 * Author: NonaTech Services Ltd
 * License: CC BY-NC 4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-s3md-s3.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/Parsedown.php';

class S3_Markdown {

    private static $instance = null;
    private $option_name = 's3md_settings';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('s3md', array($this, 'shortcode_handler'));
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function shortcode_handler($atts) {
        $atts = shortcode_atts(array(
            'file' => 'index.md',
        ), $atts, 's3md');

        // Allow ?doc= query parameter to override the file attribute
        if (isset($_GET['doc']) && $_GET['doc'] !== '') {
            $file = sanitize_text_field($_GET['doc']);
        } else {
            $file = $atts['file'];
        }

        // Validate file path — alphanumeric, hyphens, underscores, slashes, dots; must end .md
        if (!preg_match('/^[\w\-\/\.]+\.md$/i', $file)) {
            return '<!-- s3md: invalid file path -->';
        }

        // Block directory traversal
        if (strpos($file, '..') !== false) {
            return '<!-- s3md: invalid file path -->';
        }

        $cache_key = 's3md_' . md5($file);

        // Check transient cache
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Fetch from S3
        $options = get_option($this->option_name, array());

        if (empty($options['bucket']) || empty($options['access_key']) || empty($options['secret_key'])) {
            return '<!-- s3md: plugin not configured -->';
        }

        $prefix = trim($options['prefix'] ?? '', '/');
        $s3_key = $prefix !== '' ? $prefix . '/' . $file : $file;

        $s3 = new S3MD_S3(
            $options['bucket'],
            $options['region'] ?? 'us-east-1',
            $options['access_key'],
            $options['secret_key']
        );

        $markdown = $s3->get_object($s3_key);

        if (is_wp_error($markdown)) {
            return '<!-- s3md error: ' . esc_html($markdown->get_error_message()) . ' -->';
        }

        // Render markdown with safe mode
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);
        $rendered = $parsedown->text($markdown);

        // Rewrite internal .md links to use ?doc= query parameter
        $rendered = preg_replace_callback(
            '/href="((?!https?:\/\/|mailto:|#)[^"]*\.md)"/',
            function ($matches) use ($file) {
                $target = $matches[1];
                // Resolve relative paths against current file's directory
                $dir = dirname($file);
                if ($dir !== '.' && strpos($target, '/') !== 0) {
                    $target = $dir . '/' . $target;
                }
                // Normalise any foo/bar/../baz paths
                $parts = array();
                foreach (explode('/', $target) as $seg) {
                    if ($seg === '..') {
                        array_pop($parts);
                    } elseif ($seg !== '.' && $seg !== '') {
                        $parts[] = $seg;
                    }
                }
                $target = implode('/', $parts);
                return 'href="?doc=' . esc_attr($target) . '"';
            },
            $rendered
        );

        $html = '<div class="s3md-content">' . $rendered . '</div>';

        // Cache the rendered HTML for 24 hours
        set_transient($cache_key, $html, DAY_IN_SECONDS);

        // Track this cache key for flush
        $this->track_cache_key($cache_key);

        return $html;
    }

    // -------------------------------------------------------------------------
    // Cache tracking (Redis-compatible)
    // -------------------------------------------------------------------------

    private function track_cache_key($cache_key) {
        $keys = get_option('s3md_cache_keys', array());
        if (!in_array($cache_key, $keys, true)) {
            $keys[] = $cache_key;
            update_option('s3md_cache_keys', $keys);
        }
    }

    private function flush_cache() {
        $keys = get_option('s3md_cache_keys', array());
        foreach ($keys as $key) {
            delete_transient($key);
        }
        update_option('s3md_cache_keys', array());
    }

    // -------------------------------------------------------------------------
    // Admin settings page
    // -------------------------------------------------------------------------

    public function add_settings_page() {
        add_options_page(
            'S3 Markdown Settings',
            'S3 Markdown',
            'manage_options',
            's3-markdown',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitize_settings'));

        add_settings_section(
            's3md_main',
            'AWS S3 Settings',
            null,
            's3-markdown'
        );

        add_settings_field(
            'bucket',
            'Bucket Name',
            array($this, 'render_text_field'),
            's3-markdown',
            's3md_main',
            array('field' => 'bucket')
        );

        add_settings_field(
            'prefix',
            'Path Prefix',
            array($this, 'render_text_field'),
            's3-markdown',
            's3md_main',
            array('field' => 'prefix', 'description' => 'Optional key prefix, e.g. <code>documentation/website-docs</code>')
        );

        add_settings_field(
            'region',
            'Region',
            array($this, 'render_text_field'),
            's3-markdown',
            's3md_main',
            array('field' => 'region', 'default' => 'us-east-1')
        );

        add_settings_field(
            'access_key',
            'Access Key ID',
            array($this, 'render_text_field'),
            's3-markdown',
            's3md_main',
            array('field' => 'access_key')
        );

        add_settings_field(
            'secret_key',
            'Secret Access Key',
            array($this, 'render_text_field'),
            's3-markdown',
            's3md_main',
            array('field' => 'secret_key', 'type' => 'password')
        );
    }

    public function render_text_field($args) {
        $options = get_option($this->option_name, array());
        $field   = $args['field'];
        $default = isset($args['default']) ? $args['default'] : '';
        $type    = isset($args['type']) ? $args['type'] : 'text';
        $value   = isset($options[$field]) ? $options[$field] : $default;
        printf(
            '<input type="%s" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr($type),
            esc_attr($this->option_name),
            esc_attr($field),
            esc_attr($value)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', $args['description']);
        }
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        $sanitized['bucket']     = sanitize_text_field($input['bucket'] ?? '');
        $sanitized['prefix']     = sanitize_text_field($input['prefix'] ?? '');
        $sanitized['region']     = sanitize_text_field($input['region'] ?? 'us-east-1');
        $sanitized['access_key'] = sanitize_text_field($input['access_key'] ?? '');
        $sanitized['secret_key'] = sanitize_text_field($input['secret_key'] ?? '');
        return $sanitized;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle cache flush
        if (isset($_POST['s3md_flush_cache']) &&
            wp_verify_nonce($_POST['s3md_cache_nonce'], 's3md_flush_cache')) {
            $this->flush_cache();
            add_settings_error('s3md_settings', 's3md_cache_flushed', 'Cache flushed successfully.', 'success');
        }

        ?>
        <div class="wrap">
            <h1>S3 Markdown Settings</h1>

            <?php settings_errors('s3md_settings'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('s3-markdown');
                submit_button();
                ?>
            </form>

            <hr>
            <h2>Cache Management</h2>
            <p>Rendered markdown is cached for 24 hours. Flush the cache to re-fetch from S3 on the next page view.</p>
            <form method="post" action="">
                <?php wp_nonce_field('s3md_flush_cache', 's3md_cache_nonce'); ?>
                <input type="submit" name="s3md_flush_cache" class="button button-secondary" value="Flush Markdown Cache">
            </form>

            <hr>
            <h2>Usage</h2>
            <p>Use the shortcode <code>[s3md]</code> to display <code>index.md</code> from your S3 bucket.</p>
            <p>Specify a different file with <code>[s3md file="path/to/file.md"]</code>.</p>
        </div>
        <?php
    }
}

// Initialize the plugin
S3_Markdown::get_instance();

// Initialize GitHub updater
new S3MD_GitHub_Updater(__FILE__, 'nonatech-uk/WP-S3-Markdown', '1.2.0');
