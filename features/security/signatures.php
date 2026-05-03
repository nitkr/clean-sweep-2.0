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
