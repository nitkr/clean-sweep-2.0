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

    // Try WordPress function first
    if (function_exists('wp_remote_get')) {
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
    }

    // Fallback: Use direct cURL if wp_remote_get fails or isn't available
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response_body) {
        $data = json_decode($response_body, true);
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
 * Generate WordPress version options with complete version numbers
 * Returns the 4 most recent WordPress versions with their full patch numbers
 */
function clean_sweep_get_wordpress_version_options() {
    $api_url = 'https://api.wordpress.org/core/version-check/1.7/';
    $response_body = null;

    // Try WordPress function first
    if (function_exists('wp_remote_get')) {
        $response = wp_remote_get($api_url, ['timeout' => 10]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $response_body = wp_remote_retrieve_body($response);
        }
    }

    // Fallback: Use direct cURL if wp_remote_get fails or isn't available
    if (empty($response_body)) {
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response_body = curl_exec($ch);
        curl_close($ch);
    }

    if ($response_body) {
        $data = json_decode($response_body, true);

        if ($data && isset($data['offers']) && is_array($data['offers'])) {
            $all_versions = [];

            // Collect ALL versions from the API
            foreach ($data['offers'] as $offer) {
                if (isset($offer['version'])) {
                    $all_versions[] = $offer['version'];
                }
            }

            // Sort all versions descending
            if (!empty($all_versions)) {
                usort($all_versions, 'version_compare');
                $all_versions = array_reverse($all_versions);
                // Return top 6 versions with complete patch numbers (filtering in JS may remove some)
                return array_slice($all_versions, 0, 6);
            }
        }
    }

    // Fallback: generate versions from latest if both methods fail
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
