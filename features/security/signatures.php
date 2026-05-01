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
