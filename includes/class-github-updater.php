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
    private $logger;

    public function __construct($owner, $repo, $plugin_file, $token = '', $logger = null) {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->plugin_file = $plugin_file;
        $this->token = $token;
        $this->logger = $logger;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);
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

        if ($this->logger) {
            $this->logger->info('GitHub Updater: Requesting URL ' . $url);
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->error('GitHub Updater: Connection Error - ' . $response->get_error_message());
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            if ($this->logger) {
                $this->logger->error('GitHub Updater: API Error HTTP ' . $code);
            }
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
            if ($this->logger) {
                $this->logger->info('GitHub Updater: Using cached release info.');
            }
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

        if ($this->logger) {
            $this->logger->info('GitHub Updater: Checking for updates...');
        }

        $plugin_slug = plugin_basename($this->plugin_file);

        $release = $this->get_latest_release();
        if (is_wp_error($release)) {
            return $transient;
        }

        $tag = isset($release['tag_name']) ? ltrim($release['tag_name'], 'v') : null;
        if (!$tag) {
            if ($this->logger) {
                $this->logger->warning('GitHub Updater: No tag found in release.');
            }
            return $transient;
        }

        $current = defined('DSN_WOO_POWERALL_VERSION') ? DSN_WOO_POWERALL_VERSION : null;
        if (!$current) {
            return $transient;
        }

        if (version_compare($tag, $current, '>')) {
            if ($this->logger) {
                $this->logger->info('GitHub Updater: New version found: ' . $tag . ' (Current: ' . $current . ')');
            }
            // Prioritize release assets (which have the correct folder structure) over source zipball
            $package = '';
            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if ($asset['content_type'] === 'application/zip' || substr($asset['name'], -4) === '.zip') {
                        $package = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            if (empty($package)) {
                $package = isset($release['zipball_url']) ? $release['zipball_url'] : sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', $this->owner, $this->repo, $release['tag_name']);
            }

            $obj = new \stdClass();
            $obj->slug = dirname($plugin_slug);
            $obj->new_version = $tag;
            $obj->url = isset($release['html_url']) ? $release['html_url'] : '';
            $obj->package = $package;

            $transient->response[$plugin_slug] = $obj;
        } else {
             if ($this->logger) {
                $this->logger->info('GitHub Updater: No new version. Tag: ' . $tag . ' <= Current: ' . $current);
            }
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
        // Prioritize release assets
        $download_link = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if ($asset['content_type'] === 'application/zip' || substr($asset['name'], -4) === '.zip') {
                    $download_link = $asset['browser_download_url'];
                    break;
                }
            }
        }
        
        if (empty($download_link)) {
            $download_link = isset($release['zipball_url']) ? $release['zipball_url'] : '';
        }

        $data->download_link = $download_link;

        return $data;
    }

    public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;

        if ($this->logger) {
            $this->logger->info('GitHub Updater: Processing source selection. Source: ' . $source);
        }

        $plugin_file_name = basename($this->plugin_file);

        // Check if the source directory contains our plugin file
        if ($wp_filesystem->exists($source . $plugin_file_name)) {
            $new_source = trailingslashit(dirname($source)) . 'dsn-woo-powerall-connector/';
            
            if ($this->logger) {
                $this->logger->info('GitHub Updater: Found plugin file. Attempting rename to: ' . $new_source);
            }

            if ($source !== $new_source) {
                $result = $wp_filesystem->move($source, $new_source);
                if ($result) {
                    if ($this->logger) {
                        $this->logger->info('GitHub Updater: Rename successful.');
                    }
                    return $new_source;
                } else {
                    if ($this->logger) {
                        $this->logger->error('GitHub Updater: Rename failed.');
                    }
                    return $source;
                }
            }
        }

        return $source;
    }
}

?>