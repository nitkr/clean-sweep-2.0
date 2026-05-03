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
