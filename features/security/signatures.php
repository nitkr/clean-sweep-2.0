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
