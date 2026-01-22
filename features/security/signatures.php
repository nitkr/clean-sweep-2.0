<?php
/**
 * Clean Sweep - Malware Signatures
 *
 * Comprehensive malware signature definitions for WordPress threat detection
 * Updated for advanced obfuscation techniques and multi-layer encoding
 */

/**
 * Malware Signature Manager Class
 * Handles loading and managing comprehensive threat signatures
 */
class Clean_Sweep_Malware_Signatures {

    private $signatures = [];

    /**
     * Initialize with default signatures
     */
    public function __construct() {
        $this->load_default_signatures();
    }

    /**
     * Load comprehensive malware signatures
     */
    private function load_default_signatures() {
        // Enhanced malware patterns for sophisticated detection (obfuscated for security)
        $encoded = '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';
        $this->signatures = json_decode(gzuncompress(base64_decode($encoded)), true);
    }

    /**
     * Add custom signatures
     */
    public function add_signatures($new_signatures) {
        if (is_array($new_signatures)) {
            $this->signatures = array_merge($this->signatures, $new_signatures);
        }
    }

    /**
     * Get all signatures
     */
    public function get_signatures() {
        return $this->signatures;
    }

    /**
     * Get signature count
     */
    public function count() {
        return count($this->signatures);
    }

    /**
     * Scan content against all signatures
     */
    public function scan_content($content, $table) {
        $threats = [];

        foreach ($this->signatures as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $threats[] = [
                    'pattern' => $pattern,
                    'match' => substr($matches[0], 0, 100), // First 100 chars of match
                    'table' => $table,
                    'content_preview' => substr($content, 0, 200), // Content preview
                ];
            }
        }

        return $threats;
    }
}

/**
 * Helper function to get malware signatures object
 */
function clean_sweep_get_malware_signatures() {
    static $signatures;
    if (!isset($signatures)) {
        $signatures = new Clean_Sweep_Malware_Signatures();
    }
    return $signatures;
}
