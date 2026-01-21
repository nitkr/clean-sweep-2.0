<?php
/**
 * Clean Sweep - WordPress API Functions
 *
 * Contains WordPress API wrapper functions for version checking,
 * plugin data retrieval, and other WordPress-specific operations.
 *
 * @author Nithin K R
 */

/**
 * Get the latest WordPress version from WordPress.org API
 */
function clean_sweep_get_latest_wordpress_version() {
    $api_url = 'https://api.wordpress.org/core/version-check/1.7/';

    // Try to fetch from API
    $response = wp_remote_get($api_url, ['timeout' => 10]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($data && isset($data['offers']) && is_array($data['offers'])) {
            foreach ($data['offers'] as $offer) {
                if (isset($offer['response']) && $offer['response'] === 'latest' && isset($offer['version'])) {
                    return $offer['version'];
                }
            }
        }
    }

    // Fallback: use current WordPress version if API fails
    return get_bloginfo('version');
}

/**
 * Generate WordPress version options for dropdown with complete version numbers
 */
function clean_sweep_get_wordpress_version_options() {
    $api_url = 'https://api.wordpress.org/core/version-check/1.7/';

    // Try to fetch all available versions from API
    $response = wp_remote_get($api_url, ['timeout' => 10]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($data && isset($data['offers']) && is_array($data['offers'])) {
            $versions_by_minor = [];

            // Group versions by major.minor and find latest patch for each
            foreach ($data['offers'] as $offer) {
                if (isset($offer['version']) && isset($offer['response'])) {
                    $version = $offer['version'];

                    // Extract major.minor (e.g., "6.8" from "6.8.3")
                    if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
                        $minor_version = $matches[1];

                        // Keep track of the highest version for each minor version
                        if (!isset($versions_by_minor[$minor_version]) ||
                            version_compare($version, $versions_by_minor[$minor_version], '>')) {
                            $versions_by_minor[$minor_version] = $version;
                        }
                    }
                }
            }

            // Sort by version descending and return top versions
            if (!empty($versions_by_minor)) {
                uasort($versions_by_minor, 'version_compare');
                $versions = array_reverse($versions_by_minor);
                return array_slice($versions, 0, 6); // Return latest 6 versions
            }
        }
    }

    // Fallback: generate versions from latest if API fails
    $latest_version = clean_sweep_get_latest_wordpress_version();
    $versions = [$latest_version];

    // Extract major.minor from latest version
    if (preg_match('/^(\d+\.\d+)/', $latest_version, $matches)) {
        $base_version = $matches[1];
        list($major, $minor) = explode('.', $base_version);

        // Generate fallback versions (these will be incomplete but better than nothing)
        for ($i = 1; $i <= 5; $i++) {
            $prev_minor = $minor - $i;
            if ($prev_minor >= 0) {
                $versions[] = $major . '.' . $prev_minor;
            } else {
                $prev_major = $major - 1;
                if ($prev_major >= 3) {
                    $versions[] = $prev_major . '.9';
                }
            }
        }
    }

    return array_slice($versions, 0, 6);
}
