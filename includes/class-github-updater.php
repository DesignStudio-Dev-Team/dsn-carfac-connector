<?php
namespace DSNWooPowerall;

if (!defined('ABSPATH')) {
    exit;
}

class GitHub_Updater {
    private $owner;
    private $repo;
    private $plugin_file;
    private $token;

    public function __construct($owner, $repo, $plugin_file, $token = '') {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->plugin_file = $plugin_file;
        $this->token = $token;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 10, 3);
    }

    private function api_request($url) {
        $args = array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WP-GitHub-Updater',
            ),
            'timeout' => 15,
        );
        if (!empty($this->token)) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('github_api_error', 'GitHub API returned HTTP ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('github_decode_error', 'Failed to decode GitHub API response');
        }

        return $decoded;
    }

    public function get_latest_release() {
        $transient_key = 'dsn_github_latest_release_' . $this->owner . '_' . $this->repo;
        $cached = get_transient($transient_key);
        if ($cached) {
            return $cached;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode($this->owner), rawurlencode($this->repo));
        $result = $this->api_request($url);
        if (is_wp_error($result)) {
            return $result;
        }

        // Cache for 6 hours
        set_transient($transient_key, $result, HOUR_IN_SECONDS * 6);
        return $result;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $plugin_slug = plugin_basename($this->plugin_file);

        $release = $this->get_latest_release();
        if (is_wp_error($release)) {
            return $transient;
        }

        $tag = isset($release['tag_name']) ? ltrim($release['tag_name'], 'v') : null;
        if (!$tag) {
            return $transient;
        }

        $current = defined('DSN_WOO_POWERALL_VERSION') ? DSN_WOO_POWERALL_VERSION : null;
        if (!$current) {
            return $transient;
        }

        if (version_compare($tag, $current, '>')) {
            $package = isset($release['zipball_url']) ? $release['zipball_url'] : sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', $this->owner, $this->repo, $release['tag_name']);

            $obj = new \stdClass();
            $obj->slug = dirname($plugin_slug);
            $obj->new_version = $tag;
            $obj->url = isset($release['html_url']) ? $release['html_url'] : '';
            $obj->package = $package;

            $transient->response[$plugin_slug] = $obj;
        }

        return $transient;
    }

    public function plugin_api_call($res, $action, $args) {
        $plugin_slug = plugin_basename($this->plugin_file);
        $requested = isset($args->slug) ? $args->slug : null;
        if ($requested !== dirname($plugin_slug)) {
            return $res;
        }

        $release = $this->get_latest_release();
        if (is_wp_error($release)) {
            return $res;
        }

        $tag = isset($release['tag_name']) ? ltrim($release['tag_name'], 'v') : '';

        $data = new \stdClass();
        $data->name = isset($release['name']) ? $release['name'] : $this->repo;
        $data->slug = dirname($plugin_slug);
        $data->version = $tag;
        $data->author = isset($release['author']['login']) ? $release['author']['login'] : '';
        $data->homepage = isset($release['html_url']) ? $release['html_url'] : '';
        $data->sections = array(
            'description' => isset($release['body']) ? $release['body'] : '',
        );
        $data->download_link = isset($release['zipball_url']) ? $release['zipball_url'] : '';

        return $data;
    }
}

?>
