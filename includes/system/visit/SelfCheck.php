<?php
/**
 * Toolkit self-integrity: unexpected files + shipped-path allowlist.
 *
 * Kind A: extra file under clean-sweep/ (mass-dropper).
 * Kind B: shipped PHP hash mismatch (only when files.sha256 is present).
 */
final class CleanSweep_SelfCheck {

    public const KIND_OK = 'ok';
    public const KIND_EXTRA = 'extra';
    public const KIND_PATCHED = 'patched';
    public const KIND_NO_MANIFEST = 'no_manifest';

    /**
     * Runtime / generated trees relative to the toolkit root.
     * These are created after install (or by local builds) and are not shipped.
     * A mass-dropper that only hits these is still caught in includes/, api/, etc.
     */
    private const SKIP_PREFIXES = [
        'logs/',
        'backups/',
        'core/fresh/',
        'node_modules/',
        '.git/',
        'assets/dist/',
        // Local/CI signature corpus (includes intentional malware samples) — not shipped payload
        'features/security/signatures/fixtures/',
        // Build-time signature source and private keys — never ship to customers
        'features/security/signatures_build.php',
        'features/security/signatures/build/',
        'features/security/signatures/keys/private/',
    ];

    /** Extensions that count as unexpected if not on the allowlist. */
    private const WATCH_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'htaccess', 'user.ini', 'ini',
    ];

    private string $root;
    private CleanSweep_VisitCapabilities $caps;

    public function __construct(?string $root = null, ?CleanSweep_VisitCapabilities $caps = null) {
        $this->root = rtrim(str_replace('\\', '/', $root ?: (
            defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/'
        )), '/') . '/';
        $this->caps = $caps ?: CleanSweep_VisitCapabilities::instance();
    }

    public function run(): array {
        $allow = $this->load_allowlist();
        $disk = $this->scan_watchable();

        $extras = [];
        foreach ($disk as $rel => $abs) {
            if (!isset($allow['paths'][$rel]) && !isset($allow['paths'][$this->norm($rel)])) {
                $extras[] = [
                    'path' => $rel,
                    'hash' => $this->caps->hash_path($abs),
                    'mtime' => $this->caps->mtime($abs),
                    'ctime' => $this->caps->ctime($abs),
                ];
            }
        }

        $patched = [];
        if (!empty($allow['hashes'])) {
            foreach ($allow['hashes'] as $rel => $expected) {
                $abs = $this->root . $rel;
                if (!is_readable($abs)) {
                    continue;
                }
                $got = $this->caps->hash_path($abs);
                if ($got !== null && $expected !== '' && !hash_equals((string) $expected, $got)) {
                    $patched[] = ['path' => $rel];
                }
            }
        }

        $kind = self::KIND_OK;
        if ($patched) {
            $kind = self::KIND_PATCHED;
        } elseif ($extras) {
            $kind = self::KIND_EXTRA;
        } elseif (empty($allow['paths'])) {
            $kind = self::KIND_NO_MANIFEST;
        }

        return [
            'kind' => $kind,
            'ok' => $kind === self::KIND_OK,
            'extras' => $extras,
            'patched' => $patched,
            'checked' => count($disk),
            'allowlist' => count($allow['paths']),
            'has_hashes' => !empty($allow['hashes']),
        ];
    }

    /**
     * @return array{paths: array<string,true>, hashes: array<string,string>}
     */
    public function load_allowlist(): array {
        $out = ['paths' => [], 'hashes' => []];
        $manifest = $this->root . 'includes/system/visit/manifest/files.sha256';
        if (is_readable($manifest)) {
            $lines = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('/^([a-f0-9]+)\s+\*?(.+)$/i', $line, $m)) {
                    $rel = $this->norm($m[2]);
                    $out['paths'][$rel] = true;
                    $out['hashes'][$rel] = strtolower($m[1]);
                } else {
                    $rel = $this->norm($line);
                    $out['paths'][$rel] = true;
                }
            }
        }

        // Always allow the visit engine + this manifest (added during Slice 1).
        foreach ($this->builtin_paths() as $rel) {
            $out['paths'][$this->norm($rel)] = true;
        }
        return $out;
    }

    /** @return array<string,string> rel => abs */
    public function scan_watchable(): array {
        $found = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = str_replace('\\', '/', $file->getPathname());
            $rel = $this->norm(substr($abs, strlen($this->root)));
            if ($this->should_skip($rel)) {
                continue;
            }
            $base = basename($rel);
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if ($base === '.htaccess' || $base === '.user.ini' || $base === 'web.config') {
                $found[$rel] = $abs;
                continue;
            }
            if (in_array($ext, self::WATCH_EXTENSIONS, true)) {
                $found[$rel] = $abs;
            }
        }
        return $found;
    }

    private function should_skip(string $rel): bool {
        $n = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (strpos($n, $prefix) === 0) {
                return true;
            }
        }
        // Nested vendor / VCS trees anywhere under the toolkit.
        foreach (['/node_modules/', '/.git/'] as $seg) {
            if (strpos('/' . $n, $seg) !== false) {
                return true;
            }
        }
        // Dev-only signature regression runners (optional on disk; not mass-dropper bait)
        if (strpos($n, 'bin/test-signature-') === 0 && substr($n, -4) === '.php') {
            return true;
        }
        return false;
    }

    private function norm(string $rel): string {
        $rel = str_replace('\\', '/', $rel);
        return ltrim($rel, '/');
    }

    /** @return string[] */
    private function builtin_paths(): array {
        return [
            'includes/system/visit/VisitCapabilities.php',
            'includes/system/visit/VisitState.php',
            'includes/system/visit/SelfCheck.php',
            'includes/system/visit/ScopeSealer.php',
            'includes/system/visit/Snapshot.php',
            'includes/system/visit/VisitStore.php',
            'includes/system/visit/Census.php',
            'includes/system/visit/Correlator.php',
            'includes/system/visit/SnapshotCompare.php',
            'includes/system/visit/VisitSignals.php',
            'includes/system/visit/VisitWatch.php',
            'includes/system/visit/bootstrap.php',
            'features/security/scan/workers/CensusWorker.php',
            'includes/system/visit/manifest/files.sha256',
            'index.php',
        ];
    }
}
