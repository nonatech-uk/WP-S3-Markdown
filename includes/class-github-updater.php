<?php
/**
 * GitHub Plugin Updater
 *
 * Enables WordPress to check for and install updates from a GitHub repository.
 *
 * @package S3_Markdown
 * @author NonaTech Services Ltd
 */

if (!defined('ABSPATH')) {
    exit;
}

class S3MD_GitHub_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $repo;
    private $current_version;
    private $cache_key;
    private $cache_expiry = 43200; // 12 hours

    /**
     * Initialize the updater
     *
     * @param string $plugin_file Main plugin file path
     * @param string $repo GitHub repository (e.g., 'nonatech-uk/WP-S3-Markdown')
     * @param string $current_version Current plugin version
     */
    public function __construct($plugin_file, $repo, $current_version) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->repo = $repo;
        $this->current_version = $current_version;
        $this->cache_key = 's3md_github_update_' . md5($repo);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_directory_name'), 10, 4);
        add_filter('plugin_action_links_' . $this->plugin_slug, array($this, 'add_check_update_link'));
        add_action('admin_init', array($this, 'handle_check_update'));
    }

    /**
     * Check GitHub for updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_data = $this->get_remote_data();

        if ($remote_data && version_compare($this->current_version, $remote_data->version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_data->version,
                'url' => $remote_data->url,
                'package' => $remote_data->download_url,
                'icons' => array(),
                'banners' => array(),
                'tested' => '',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the update details popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $remote_data = $this->get_remote_data();

        if (!$remote_data) {
            return $result;
        }

        return (object) array(
            'name' => 'S3 Markdown',
            'slug' => dirname($this->plugin_slug),
            'version' => $remote_data->version,
            'author' => '<a href="https://nonatech.co.uk">NonaTech Services Ltd</a>',
            'homepage' => 'https://github.com/' . $this->repo,
            'download_link' => $remote_data->download_url,
            'sections' => array(
                'description' => 'Render markdown files from an AWS S3 bucket via shortcode.',
                'changelog' => $this->format_changelog($remote_data->changelog),
            ),
            'requires' => '5.0',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'last_updated' => $remote_data->released_at,
        );
    }

    /**
     * Fix the directory name after extraction
     * GitHub archives extract to 'repo-name-tag' format
     */
    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $source;
        }

        global $wp_filesystem;

        $correct_name = dirname($this->plugin_slug);
        $new_source = trailingslashit($remote_source) . $correct_name . '/';

        if ($source !== $new_source) {
            $wp_filesystem->move($source, $new_source);
            return $new_source;
        }

        return $source;
    }

    /**
     * Get release data from GitHub
     */
    private function get_remote_data() {
        $cached = get_transient($this->cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $api_url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';

        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response));

        if (empty($release) || !isset($release->tag_name)) {
            return false;
        }

        // Extract version from tag name (remove 'v' prefix if present)
        $version = ltrim($release->tag_name, 'v');

        $data = (object) array(
            'version' => $version,
            'url' => $release->html_url,
            'download_url' => $release->zipball_url,
            'changelog' => $release->body ?? '',
            'released_at' => $release->published_at ?? '',
        );

        set_transient($this->cache_key, $data, $this->cache_expiry);

        return $data;
    }

    /**
     * Format changelog for display
     */
    private function format_changelog($changelog) {
        if (empty($changelog)) {
            return '<p>See the <a href="https://github.com/' . $this->repo . '/releases">GitHub releases page</a> for changelog.</p>';
        }

        // Convert markdown to basic HTML
        $html = esc_html($changelog);
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^\- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);
        $html = nl2br($html);

        return $html;
    }

    /**
     * Add "Check for updates" link to plugin action links
     */
    public function add_check_update_link($links) {
        $url = wp_nonce_url(admin_url('plugins.php?s3md_check_update=1'), 's3md_check_update');
        $links[] = '<a href="' . esc_url($url) . '">Check for updates</a>';
        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_check_update() {
        if (empty($_GET['s3md_check_update'])) {
            return;
        }

        if (!current_user_can('update_plugins') || !wp_verify_nonce($_GET['_wpnonce'], 's3md_check_update')) {
            return;
        }

        $this->clear_cache();
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Clear the update cache
     */
    public function clear_cache() {
        delete_transient($this->cache_key);
    }
}
