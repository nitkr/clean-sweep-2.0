<?php
/**
 * Clean Sweep - Malware Signature Loader
 *
 * Production: offline csig-v1 pack (AES-256-GCM + ECDSA P-256)
 *   signatures/versions/current.csig
 *
 * Legacy: gzip+base64+sha256 current.json (read-only compatibility)
 *
 * Emergency fallback: tiny generic set only (not the full rule base).
 * Full plain lists live in signatures_build.php for builds — do not ship
 * that file if you want pack privacy.
 */

require_once __DIR__ . '/signatures/SignatureCrypto.php';
require_once __DIR__ . '/signatures/SignatureEmbeddedKeys.php';

/**
 * Malware Signature Manager
 */
class CleanSweep_MalwareSignatures {

    /** @var array<int, string> Regex patterns for scanners (indexed) */
    private $signatures = [];

    /** @var array<int, array{id:string,category:string,pattern:string,severity?:string,targets?:string[],family?:string}> */
    private $entries = [];

    /** @var string */
    private $source = 'unknown';

    /** @var string */
    private $version = '';

    public function __construct() {
        $this->load_signatures();
    }

    private function load_signatures() {
        // 1) Preferred: csig-v1 encrypted+signed pack
        $csig = __DIR__ . '/signatures/versions/current.csig';
        if (is_file($csig)) {
            if ($this->load_csig_pack($csig)) {
                return;
            }
        }

        // Also try versioned latest if current.csig missing
        $versions_dir = __DIR__ . '/signatures/versions';
        if (is_dir($versions_dir)) {
            $candidates = glob($versions_dir . '/v*_current.csig') ?: [];
            rsort($candidates);
            foreach ($candidates as $path) {
                if ($this->load_csig_pack($path)) {
                    return;
                }
            }
        }

        // 2) Legacy protected format (gzip+base64+hash) — reversible; kept for migration
        $legacy = __DIR__ . '/signatures/versions/current.json';
        if (is_file($legacy)) {
            if ($this->load_legacy_protected($legacy)) {
                return;
            }
        }

        // 3) Dev-only: load plain signatures_build.php if present
        $build_src = __DIR__ . '/signatures_build.php';
        if (is_file($build_src)) {
            if ($this->load_plain_build_source($build_src)) {
                $this->log('Loaded plain signatures_build.php (dev mode — not for production privacy)');
                return;
            }
        }

        // 4) Emergency micro-set
        $this->load_emergency_fallback();
        $this->log('Using emergency fallback signatures only', 'warning');
    }

    /**
     * @param string $path
     * @return bool
     */
    private function load_csig_pack($path) {
        if (!CleanSweep_SignatureEmbeddedKeys::is_configured()) {
            $this->log('csig pack found but embedded keys not configured', 'error');
            return false;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }
        $pack = json_decode($raw, true);
        if (!is_array($pack)) {
            $this->log('Invalid csig JSON: ' . $path, 'error');
            return false;
        }

        try {
            $payload = CleanSweep_SignatureCrypto::open(
                $pack,
                CleanSweep_SignatureEmbeddedKeys::encryption_key_bin(),
                CleanSweep_SignatureEmbeddedKeys::public_key_pem()
            );
        } catch (Throwable $e) {
            $this->log('csig open failed: ' . $e->getMessage(), 'error');
            return false;
        }

        $this->ingest_signature_list($payload['signatures']);
        $this->version = (string) ($pack['version'] ?? $payload['version'] ?? '');
        $this->source = 'csig-v1:' . ($this->version !== '' ? $this->version : basename($path));
        $this->log('Loaded ' . count($this->signatures) . ' signatures from ' . $this->source);
        return !empty($this->signatures);
    }

    /**
     * Legacy gzip+base64+sha256 package (current.json).
     */
    private function load_legacy_protected($protected_file) {
        $data = @file_get_contents($protected_file);
        if ($data === false || $data === '') {
            return false;
        }
        $version_data = json_decode($data, true);
        if (!is_array($version_data) || empty($version_data['data']) || empty($version_data['hash'])) {
            return false;
        }
        // Do not accept packs that claim csig format without going through open()
        if (($version_data['format'] ?? '') === CleanSweep_SignatureCrypto::FORMAT) {
            return $this->load_csig_pack($protected_file);
        }

        $expected_hash = hash('sha256', $version_data['data']);
        if (!hash_equals($expected_hash, $version_data['hash'])) {
            $this->log('Legacy signature integrity check failed', 'error');
            return false;
        }

        $raw = base64_decode($version_data['data'], true);
        if ($raw === false) {
            return false;
        }
        $decoded = function_exists('gzdecode') ? @gzdecode($raw) : false;
        if ($decoded === false) {
            $decoded = @gzuncompress($raw);
        }
        if ($decoded === false) {
            $decoded = @gzinflate($raw);
        }
        if ($decoded === false) {
            return false;
        }
        $list = json_decode($decoded, true);
        if (!is_array($list)) {
            return false;
        }

        $this->ingest_signature_list($list);
        $this->version = (string) ($version_data['version'] ?? '');
        $this->source = 'legacy-protected:' . ($this->version !== '' ? $this->version : 'unknown');
        $this->log('Loaded ' . count($this->signatures) . ' signatures from legacy package (consider rebuilding as csig-v1)', 'warning');
        return !empty($this->signatures);
    }

    private function load_plain_build_source($path) {
        $signatures = null;
        require $path;
        if (empty($signatures) || !is_array($signatures)) {
            return false;
        }
        $this->ingest_signature_list($signatures);
        $this->source = 'plain:signatures_build.php';
        $this->version = 'dev';
        return !empty($this->signatures);
    }

    /**
     * Tiny always-on set if no pack is available — not a substitute for full DB.
     */
    private function load_emergency_fallback() {
        $list = [
            ['id' => 'em_0001', 'pattern' => '/eval\s*\(\s*base64_decode\s*\(/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0002', 'pattern' => '/eval\s*\(\s*gzinflate\s*\(/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0003', 'pattern' => '/assert\s*\(\s*base64_decode\s*\(/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0004', 'pattern' => '/preg_replace\s*\(\s*[\'"].*\/e[\'"]/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0005', 'pattern' => '/shell_exec\s*\(\s*\$_(?:GET|POST|REQUEST)/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0006', 'pattern' => '/system\s*\(\s*\$_(?:GET|POST|REQUEST)/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0007', 'pattern' => '/passthru\s*\(\s*\$_(?:GET|POST|REQUEST)/i', 'category' => 'php_dangerous'],
            ['id' => 'em_0008', 'pattern' => '/\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"][^\'"]+[\'"]\s*\]\s*\(\s*\$_/i', 'category' => 'php_dangerous'],
        ];
        $this->ingest_signature_list($list);
        $this->source = 'emergency-fallback';
        $this->version = '0';
    }

    /**
     * Accept list of strings or entry objects:
     * {id, pattern, category, severity?, targets?, family?}.
     */
    private function ingest_signature_list(array $list) {
        $this->signatures = [];
        $this->entries = [];
        $i = 0;
        foreach ($list as $item) {
            $i++;
            if (is_string($item)) {
                $id = sprintf('cs_%04d', $i);
                $pattern = $item;
                $category = 'general';
                $severity = 'medium';
                $targets = ['php', 'phtml', 'inc', 'phar', 'db'];
                $family = '';
            } elseif (is_array($item)) {
                $pattern = (string) ($item['pattern'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                $id = (string) ($item['id'] ?? sprintf('cs_%04d', $i));
                $category = (string) ($item['category'] ?? 'general');
                $severity = strtolower((string) ($item['severity'] ?? 'medium'));
                if (!in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
                    $severity = 'medium';
                }
                $targets = $item['targets'] ?? null;
                if (!is_array($targets) || $targets === []) {
                    $targets = ($category === 'js_web' || $category === 'js_malicious')
                        ? ['js']
                        : ['php', 'phtml', 'inc', 'phar', 'db'];
                } else {
                    $targets = array_values(array_unique(array_map('strval', $targets)));
                }
                $family = (string) ($item['family'] ?? '');
            } else {
                continue;
            }
            // Validate regex once
            if (@preg_match($pattern, '') === false) {
                continue;
            }
            $idx = count($this->signatures);
            $this->signatures[$idx] = $pattern;
            $entry = [
                'id' => $id,
                'pattern' => $pattern,
                'category' => $category !== '' ? $category : 'general',
                'severity' => $severity,
                'targets' => $targets,
            ];
            if ($family !== '') {
                $entry['family'] = $family;
            }
            $this->entries[$idx] = $entry;
        }
    }

    /**
     * Patterns for scanners (list of regex strings, 0-based).
     */
    public function get_signatures() {
        return $this->signatures;
    }

    /**
     * Full entries (pattern for matching only; not for UI display).
     */
    public function get_entries() {
        return $this->entries;
    }

    /**
     * @param int $index
     * @return array{id:string,category:string,pattern:string,severity?:string,targets?:string[],family?:string}|null
     */
    public function get_entry($index) {
        return $this->entries[$index] ?? null;
    }

    /**
     * Public-facing id for a signature index (never the raw regex).
     */
    public function get_signature_id($index) {
        if (isset($this->entries[$index]['id'])) {
            return $this->entries[$index]['id'];
        }
        return 'sig_' . (int) $index;
    }

    public function get_category($index) {
        return $this->entries[$index]['category'] ?? 'general';
    }

    /**
     * @param int $index
     * @return string critical|high|medium|low
     */
    public function get_severity($index) {
        $sev = strtolower((string) ($this->entries[$index]['severity'] ?? 'medium'));
        return in_array($sev, ['critical', 'high', 'medium', 'low'], true) ? $sev : 'medium';
    }

    /**
     * @param int $index
     * @return string[]
     */
    public function get_targets($index) {
        $t = $this->entries[$index]['targets'] ?? null;
        return is_array($t) ? array_values($t) : [];
    }

    /**
     * @param int $index
     * @return string
     */
    public function get_family($index) {
        return (string) ($this->entries[$index]['family'] ?? '');
    }

    /**
     * Category => list of signature indices from loaded csig/plain entries.
     * Used by CleanSweep_SignaturePreFilter so file-type filtering follows
     * pack metadata instead of brittle index bands.
     *
     * @return array<string, int[]>
     */
    public function get_category_index_map() {
        $map = [];
        foreach ($this->entries as $index => $entry) {
            $cat = (string) ($entry['category'] ?? 'general');
            if ($cat === '') {
                $cat = 'general';
            }
            if (!isset($map[$cat])) {
                $map[$cat] = [];
            }
            $map[$cat][] = (int) $index;
        }
        return $map;
    }

    /**
     * File extension / scan target => signature indices from entry targets[].
     * The special target "db" is for database content scanners (not file ext).
     *
     * @return array<string, int[]>
     */
    public function get_target_index_map() {
        $map = [];
        foreach ($this->entries as $index => $entry) {
            $targets = $entry['targets'] ?? null;
            if (!is_array($targets) || $targets === []) {
                continue;
            }
            foreach ($targets as $t) {
                $t = strtolower((string) $t);
                if ($t === '') {
                    continue;
                }
                if (!isset($map[$t])) {
                    $map[$t] = [];
                }
                $map[$t][] = (int) $index;
            }
        }
        return $map;
    }

    public function count() {
        return count($this->signatures);
    }

    public function get_source() {
        return $this->source;
    }

    public function get_version() {
        return $this->version;
    }

    /**
     * Meta for UI / API (no raw patterns).
     */
    public function get_public_meta() {
        return [
            'source' => $this->source,
            'version' => $this->version,
            'count' => $this->count(),
            'format' => strpos($this->source, 'csig-v1') === 0 ? 'csig-v1' : $this->source,
            'schema' => ['id', 'pattern', 'category', 'severity', 'targets', 'family'],
        ];
    }

    private function log($msg, $level = 'info') {
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message($msg, $level);
        }
    }
}

/**
 * Singleton accessor
 * @return CleanSweep_MalwareSignatures
 */
function clean_sweep_get_malware_signatures() {
    static $signatures;
    if (!isset($signatures)) {
        $signatures = new CleanSweep_MalwareSignatures();
    }
    return $signatures;
}
