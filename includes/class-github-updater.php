<?php
namespace DSNCarfac;

if (!defined('ABSPATH')) {
    exit;
}

class GitHub_Updater {
    const RELEASE_CACHE_TTL = HOUR_IN_SECONDS;

    private $owner;
    private $repo;
    private $plugin_file;
    private $token;
    private $logger;

    public function __construct($owner, $repo, $plugin_file, $token = '', $logger = null) {
        $this->owner = (string) $owner;
        $this->repo = (string) $repo;
        $this->plugin_file = (string) $plugin_file;
        $this->token = (string) $token;
        $this->logger = $logger;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'clear_release_cache'), 10, 2);

        if ($this->token !== '') {
            add_filter('http_request_args', array($this, 'add_download_auth_headers'), 10, 2);
        }
    }

    private function plugin_basename() {
        return plugin_basename($this->plugin_file);
    }

    private function plugin_slug() {
        return dirname($this->plugin_basename());
    }

    private function cache_key() {
        return 'dsn_carfac_github_release_' . md5($this->owner . '/' . $this->repo);
    }

    private function log($level, $message) {
        if (!$this->logger || !method_exists($this->logger, $level)) {
            return;
        }

        $this->logger->{$level}('GitHub Updater: ' . $message);
    }

    private function api_request($url) {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'DSN-Carfac-WordPress-Updater',
            'X-GitHub-Api-Version' => '2022-11-28',
        );

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 20,
        ));

        if (is_wp_error($response)) {
            $this->log('error', 'Connection failed: ' . $response->get_error_message());
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $this->log('error', 'GitHub API returned HTTP ' . $status . '.');
            return new \WP_Error('dsn_carfac_github_api_error', 'GitHub API returned HTTP ' . $status);
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new \WP_Error('dsn_carfac_github_decode_error', 'GitHub returned an invalid release response.');
        }

        return $decoded;
    }

    public function get_latest_release($force_refresh = false) {
        if (!$force_refresh) {
            $cached = get_site_transient($this->cache_key());
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode($this->owner),
            rawurlencode($this->repo)
        );
        $release = $this->api_request($url);

        if (!is_wp_error($release)) {
            set_site_transient($this->cache_key(), $release, self::RELEASE_CACHE_TTL);
        }

        return $release;
    }

    private function release_version(array $release) {
        if (empty($release['tag_name'])) {
            return '';
        }

        return ltrim((string) $release['tag_name'], "vV \t\n\r\0\x0B");
    }

    private function release_asset(array $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        $version = $this->release_version($release);
        $slug = $this->plugin_slug();
        $preferred_names = array(
            $slug . '-v' . $version . '.zip',
            $slug . '-' . $version . '.zip',
            $slug . '.zip',
        );

        foreach ($preferred_names as $preferred_name) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && (string) $asset['name'] === $preferred_name) {
                    return $asset;
                }
            }
        }

        foreach ($release['assets'] as $asset) {
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            if (strpos($name, $slug . '-') === 0 && substr($name, -4) === '.zip') {
                return $asset;
            }
        }

        return null;
    }

    private function package_url(array $asset) {
        if ($this->token !== '' && !empty($asset['url'])) {
            return (string) $asset['url'];
        }

        return isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
    }

    public function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $plugin = $this->plugin_basename();
        $current_version = isset($transient->checked[$plugin])
            ? (string) $transient->checked[$plugin]
            : DSN_CARFAC_VERSION;
        $release = $this->get_latest_release();

        if (is_wp_error($release)) {
            return $transient;
        }

        $new_version = $this->release_version($release);
        if ($new_version === '' || !version_compare($new_version, $current_version, '>')) {
            return $transient;
        }

        $asset = $this->release_asset($release);
        if (!$asset) {
            $this->log('warning', 'Release v' . $new_version . ' has no WordPress-ready ZIP asset.');
            return $transient;
        }

        $package = $this->package_url($asset);
        if ($package === '') {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        $update = new \stdClass();
        $update->id = 'https://github.com/' . $this->owner . '/' . $this->repo;
        $update->slug = $this->plugin_slug();
        $update->plugin = $plugin;
        $update->new_version = $new_version;
        $update->url = isset($release['html_url']) ? (string) $release['html_url'] : $update->id;
        $update->package = $package;
        $update->requires_php = '8.1';
        $update->tested = '6.8';

        $transient->response[$plugin] = $update;
        if (isset($transient->no_update[$plugin])) {
            unset($transient->no_update[$plugin]);
        }

        $this->log('info', 'Update available: ' . $current_version . ' -> ' . $new_version . '.');
        return $transient;
    }

    public function plugin_api_call($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== $this->plugin_slug()) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (is_wp_error($release)) {
            return $result;
        }

        $asset = $this->release_asset($release);
        if (!$asset) {
            return $result;
        }

        $version = $this->release_version($release);
        $homepage = 'https://github.com/' . $this->owner . '/' . $this->repo;
        $body = isset($release['body']) ? (string) $release['body'] : '';

        $info = new \stdClass();
        $info->name = 'DSN Carfac';
        $info->slug = $this->plugin_slug();
        $info->version = $version;
        $info->author = '<a href="https://designstudio.com">DesignStudio Network Inc</a>';
        $info->homepage = isset($release['html_url']) ? (string) $release['html_url'] : $homepage;
        $info->requires = '6.0';
        $info->requires_php = '8.1';
        $info->tested = '6.8';
        $info->last_updated = isset($release['published_at']) ? (string) $release['published_at'] : '';
        $info->download_link = $this->package_url($asset);
        $info->sections = array(
            'description' => wpautop(esc_html__('WooCommerce integration with the Carfac Cloud API.', 'dsn-carfac')),
            'changelog' => $body !== '' ? wpautop(esc_html($body)) : '',
        );

        return $info;
    }

    public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = null) {
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename()) {
            return $source;
        }

        $source = trailingslashit($source);
        if (basename(untrailingslashit($source)) === $this->plugin_slug()) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem || !$wp_filesystem->exists($source . basename($this->plugin_file))) {
            return $source;
        }

        $target = trailingslashit($remote_source) . $this->plugin_slug() . '/';
        if ($wp_filesystem->exists($target) || !$wp_filesystem->move($source, $target)) {
            return $source;
        }

        return $target;
    }

    public function add_download_auth_headers($args, $url) {
        if ($this->token === '') {
            return $args;
        }

        $asset_api_prefix = sprintf(
            'https://api.github.com/repos/%s/%s/releases/assets/',
            $this->owner,
            $this->repo
        );
        if (strpos($url, $asset_api_prefix) !== 0) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = array();
        }
        $args['headers']['Authorization'] = 'Bearer ' . $this->token;
        $args['headers']['Accept'] = 'application/octet-stream';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';

        return $args;
    }

    public function clear_release_cache($upgrader, $hook_extra) {
        if (!is_array($hook_extra) || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])
            ? $hook_extra['plugins']
            : array($hook_extra['plugin'] ?? '');

        if (in_array($this->plugin_basename(), $plugins, true)) {
            delete_site_transient($this->cache_key());
        }
    }
}
