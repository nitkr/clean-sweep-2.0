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
