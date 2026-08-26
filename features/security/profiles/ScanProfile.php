<?php
/**
 * Clean Sweep - Scan Profile
 *
 * Profile definitions and selection for configurable scan behavior.
 * Profiles control depth, scope, database limits, and analysis options.
 */

class CleanSweep_ScanProfile {

    const QUICK = 'quick';
    const STANDARD = 'standard';
    const DEEP = 'deep';
    const CUSTOM = 'custom';

    /** @var string Profile identifier */
    private $profile_id;

    /** @var int Directory scanning max depth */
    private $max_depth;

    /** @var bool Include uploads directory */
    private $include_uploads;

    /** @var bool Include mu-plugins directory */
    private $include_mu_plugins;

    /** @var bool Include vendor directory */
    private $include_vendor;

    /** @var bool Include backup-related directories (backups/, backup*, updraft, etc.) */
    private $include_backups;

    /** @var array File types to scan */
    private $scan_file_types;

    /**
     * Drop-in / high-value basenames: always eligible to scan and never excluded.
     * These are classic persistence locations under wp-content.
     */
    private static $high_value_basenames = [
        'db.php',
        'object-cache.php',
        'advanced-cache.php',
        'sunrise.php',
        '.htaccess',
        '.user.ini',
        'php.ini',
    ];

    /** @var int Database post limit */
    private $db_post_limit;

    /** @var int Database user limit */
    private $db_user_limit;

    /** @var int Database comment limit */
    private $db_comment_limit;

    /** @var array|null Specific tables to scan (null = use defaults) */
    private $db_tables;

    /** @var bool Enable encoding chain detection */
    private $enable_encoding_chains;

    /** @var bool Enable deconstructed function analysis */
    private $enable_deconstructed;

    /** @var bool Enable persistence detection */
    private $enable_persistence;

    /** @var string Exclusion strictness: aggressive, balanced, minimal */
    private $exclusion_strictness;

    /** @var bool Enable vulnerability scanning */
    private $enable_vulnerability_scan;

    /** @var bool Enable ML-based anomaly detection */
    private $enable_ml_anomaly_detection;

    /** @var bool Enable parallel processing (for VPS/dedicated environments) */
    private $enable_parallel_scan;

    /** @var bool Enable differential/incremental scanning (for faster repeat scans) */
    private $enable_differential_scan;

    /**
     * Create profile with configuration array.
     *
     * @param array $config Profile configuration
     */
    public function __construct($config = []) {
        $defaults = self::get_default_config();
        $config = array_merge($defaults, $config);

        $this->profile_id = $config['profile_id'] ?? self::STANDARD;
        $this->max_depth = $config['max_depth'] ?? 3;
        $this->include_uploads = $config['include_uploads'] ?? false;
        $this->include_mu_plugins = $config['include_mu_plugins'] ?? true;
        $this->include_vendor = $config['include_vendor'] ?? false;
        $this->include_backups = $config['include_backups'] ?? false;
        $this->scan_file_types = $config['scan_file_types'] ?? ['php', 'js', 'conf', 'cfg', 'ini', 'config'];
        $this->db_post_limit = $config['db_post_limit'] ?? 2000;
        $this->db_user_limit = $config['db_user_limit'] ?? 300;
        $this->db_comment_limit = $config['db_comment_limit'] ?? 500;
        $this->db_tables = $config['db_tables'] ?? null;
        $this->enable_encoding_chains = $config['enable_encoding_chains'] ?? true;
        $this->enable_deconstructed = $config['enable_deconstructed'] ?? true;
        $this->enable_persistence = $config['enable_persistence'] ?? false;
        $this->exclusion_strictness = $config['exclusion_strictness'] ?? 'balanced';
        $this->enable_vulnerability_scan = $config['enable_vulnerability_scan'] ?? false;
        $this->enable_ml_anomaly_detection = $config['enable_ml_anomaly_detection'] ?? false;
        $this->enable_parallel_scan = $config['enable_parallel_scan'] ?? false;
        $this->enable_differential_scan = $config['enable_differential_scan'] ?? true; // Default to enabled
    }

    /**
     * Get default configuration.
     *
     * @return array Default config values
     */
    private static function get_default_config() {
        return [
            'profile_id' => self::STANDARD,
            'max_depth' => 3,
            'include_uploads' => true,
            'include_mu_plugins' => true,
            'include_vendor' => false,
            'include_backups' => false,
            'scan_file_types' => self::standard_file_types(),
            'db_post_limit' => 2000,
            'db_user_limit' => 300,
            'db_comment_limit' => 500,
            'db_tables' => null,
            'enable_encoding_chains' => true,
            'enable_deconstructed' => true,
            'enable_persistence' => false,
            'exclusion_strictness' => 'balanced',
            'enable_vulnerability_scan' => false,
            'enable_ml_anomaly_detection' => false,
            'enable_parallel_scan' => false,
            'enable_differential_scan' => true, // Enable for faster repeat scans
        ];
    }

    /**
     * Core scannable extensions for Quick (light host pass).
     *
     * @return array
     */
    private static function quick_file_types() {
        return ['php', 'js', 'json', 'conf', 'cfg', 'ini', 'config'];
    }

    /**
     * Broader scannable extensions for Standard / Deep (shells + config + json).
     *
     * @return array
     */
    private static function standard_file_types() {
        return [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
            'js', 'json', 'html', 'htm',
            'conf', 'cfg', 'ini', 'config',
        ];
    }

    /**
     * Create QUICK profile — light scope for low-resource / shared hosts.
     * Plugin/theme tree depth 5 (assets/js); shallow uploads (uploads/ and
     * uploads/YYYY/, not month folders).
     *
     * @return self
     */
    public static function create_quick() {
        return new self([
            'profile_id' => self::QUICK,
            'max_depth' => 2,
            'include_uploads' => true,
            'include_mu_plugins' => true,
            'include_vendor' => false,
            'include_backups' => false,
            'scan_file_types' => self::quick_file_types(),
            'db_post_limit' => 300,
            'db_user_limit' => 50,
            'db_comment_limit' => 200,
            'db_tables' => ['posts', 'options', 'postmeta', 'users', 'comments'],
            'enable_encoding_chains' => false,
            'enable_deconstructed' => false,
            'enable_persistence' => false,
            'exclusion_strictness' => 'aggressive',
            'enable_vulnerability_scan' => false,
            // Quick scan enables differential for faster repeat scans
            // On restricted hosts (clean_sweep_is_shared_hosting), this will use conservative settings
            'enable_differential_scan' => true,
        ]);
    }

    /**
     * Create STANDARD profile — default investigation scan (main product path).
     * plugins/themes/mu-plugins + uploads (max_depth) + full WP DB set; no vendor/backups.
     *
     * @return self
     */
    public static function create_standard() {
        return new self([
            'profile_id' => self::STANDARD,
            'max_depth' => 3,
            'include_uploads' => true,
            'include_mu_plugins' => true,
            'include_vendor' => false,
            'include_backups' => false,
            'scan_file_types' => self::standard_file_types(),
            'db_post_limit' => 2000,
            'db_user_limit' => 300,
            'db_comment_limit' => 500,
            'db_tables' => null, // Use defaults
            'enable_encoding_chains' => true,
            'enable_deconstructed' => true,
            'enable_persistence' => false,
            'exclusion_strictness' => 'balanced',
            'enable_vulnerability_scan' => false,
            'enable_differential_scan' => true, // Enable for faster repeat scans
        ]);
    }

    /**
     * Create DEEP profile — broadest supported filesystem scope (reinfection / heavy malware).
     * Includes uploads, vendor, backups; still skips universal junk (cache, node_modules, …).
     *
     * @return self
     */
    public static function create_deep() {
        return new self([
            'profile_id' => self::DEEP,
            'max_depth' => 5,
            'include_uploads' => true,
            'include_mu_plugins' => true,
            'include_vendor' => true,
            'include_backups' => true,
            'scan_file_types' => self::standard_file_types(),
            'db_post_limit' => 0, // 0 = all (cursor-based)
            'db_user_limit' => 0,
            'db_comment_limit' => 0,
            'db_tables' => null, // Use defaults
            'enable_encoding_chains' => true,
            'enable_deconstructed' => true,
            'enable_persistence' => true,
            'exclusion_strictness' => 'minimal',
            'enable_vulnerability_scan' => true,
            'enable_ml_anomaly_detection' => true,
            'enable_parallel_scan' => true, // Enable parallel processing in deep scans for VPS
            'enable_differential_scan' => true, // Deep uses full differential for comprehensive comparison
        ]);
    }

    /**
     * Create CUSTOM profile with user-defined configuration.
     *
     * @param array $config Custom configuration
     * @return self
     */
    public static function create_custom($config) {
        $config['profile_id'] = self::CUSTOM;
        return new self($config);
    }

    /**
     * Create profile by ID.
     *
     * @param string $profile_id QUICK, STANDARD, DEEP, or CUSTOM
     * @param array $custom_config Configuration for CUSTOM profile
     * @return self
     */
    public static function create($profile_id, $custom_config = []) {
        switch ($profile_id) {
            case self::QUICK:
                return self::create_quick();
            case self::STANDARD:
                return self::create_standard();
            case self::DEEP:
                return self::create_deep();
            case self::CUSTOM:
            default:
                return self::create_custom($custom_config);
        }
    }

    // ============================================================================
    // GETTERS
    // ============================================================================

    public function get_profile_id() {
        return $this->profile_id;
    }

    public function get_max_depth() {
        return $this->max_depth;
    }

    public function get_include_uploads() {
        return $this->include_uploads;
    }

    public function get_include_mu_plugins() {
        return $this->include_mu_plugins;
    }

    public function get_include_vendor() {
        return $this->include_vendor;
    }

    public function get_include_backups() {
        return $this->include_backups;
    }

    public function get_scan_file_types() {
        return $this->scan_file_types;
    }

    /**
     * Whether a file path should be signature-scanned (extension or high-value basename).
     *
     * @param string $path
     * @return bool
     */
    public function should_scan_file($path) {
        $normalized = str_replace('\\', '/', (string) $path);
        $base = basename($normalized);

        if (in_array($base, self::$high_value_basenames, true)) {
            return true;
        }

        if ($this->profile_id !== self::QUICK && $this->looks_like_php_disguise($base)) {
            return true;
        }

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, $this->scan_file_types, true);
    }

    /**
     * shell.php.jpg / backdoor.php.gif — last suffix is not php.
     */
    public function looks_like_php_disguise($basename) {
        return (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', (string) $basename);
    }

    public function is_php_family_file($path) {
        $base = basename(str_replace('\\', '/', (string) $path));
        if ($this->looks_like_php_disguise($base)) {
            return true;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        return in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'], true);
    }

    /**
     * Max depth from wp-content for this path's tree. 0 = do not enter.
     */
    public function get_tree_max_depth($path) {
        $n = str_replace('\\', '/', (string) $path);
        if ($this->path_has_segment($n, 'uploads')) {
            if (!$this->include_uploads) {
                return 0;
            }
            if ($this->profile_id === self::DEEP) {
                return 6;
            }
            // Quick: original default depth 2 → uploads/ and uploads/YYYY/ only.
            return $this->profile_id === self::QUICK ? 2 : 4;
        }
        if ($this->path_has_segment($n, 'vendor')) {
            return ($this->include_vendor && $this->profile_id === self::DEEP) ? 6 : 0;
        }
        if ($this->is_backup_path($n)) {
            return ($this->include_backups && $this->profile_id === self::DEEP) ? 6 : 0;
        }
        if ($this->path_is_cache($n)) {
            if ($this->profile_id === self::QUICK) {
                return 0;
            }
            return $this->profile_id === self::DEEP ? 6 : 4;
        }
        if ($this->path_has_segment($n, 'mu-plugins')
            || $this->path_has_segment($n, 'plugins')
            || $this->path_has_segment($n, 'themes')) {
            if ($this->profile_id === self::DEEP) {
                return 12;
            }
            if ($this->profile_id === self::QUICK) {
                // 4 reaches plugins/<slug>/assets/js (wp-content=0 … js=4).
                return 5;
            }
            return 8;
        }
        return max(1, (int) $this->max_depth);
    }

    /**
     * How many path segments this is below wp-content (wp-content = 0).
     */
    public function content_relative_depth($path) {
        $n = str_replace('\\', '/', (string) $path);
        foreach (['/wp-content/', 'wp-content/'] as $m) {
            $i = stripos($n, $m);
            if ($i === false) {
                continue;
            }
            $rest = trim(substr($n, $i + strlen($m)), '/');
            if ($rest === '') {
                return 0;
            }
            return substr_count($rest, '/') + 1;
        }
        return 1;
    }

    public function path_is_cache($normalized_path) {
        return $this->path_has_segment($normalized_path, 'cache')
            || $this->path_has_segment($normalized_path, 'wpcache');
    }

    /**
     * Drop-in / high-value basenames that must never be excluded.
     *
     * @param string $path
     * @return bool
     */
    public function is_high_value_path($path) {
        $base = basename(str_replace('\\', '/', (string) $path));
        return in_array($base, self::$high_value_basenames, true);
    }

    public function get_db_post_limit() {
        return $this->db_post_limit;
    }

    public function get_db_user_limit() {
        return $this->db_user_limit;
    }

    public function get_db_comment_limit() {
        return $this->db_comment_limit;
    }

    public function get_db_tables() {
        return $this->db_tables;
    }

    public function get_enable_encoding_chains() {
        return $this->enable_encoding_chains;
    }

    public function get_enable_deconstructed() {
        return $this->enable_deconstructed;
    }

    public function get_enable_persistence() {
        return $this->enable_persistence;
    }

    public function get_exclusion_strictness() {
        return $this->exclusion_strictness;
    }

    public function get_enable_vulnerability_scan() {
        return $this->enable_vulnerability_scan;
    }

    public function get_enable_ml_anomaly_detection() {
        return $this->enable_ml_anomaly_detection;
    }

    public function get_enable_parallel_scan() {
        return $this->enable_parallel_scan;
    }

    public function get_enable_differential_scan() {
        return $this->enable_differential_scan;
    }

    /**
     * Suffixes that exist once per network (not per blog).
     *
     * @return string[]
     */
    public function get_global_db_suffixes() {
        return ['users', 'usermeta', 'sitemeta', 'blogs', 'blogmeta', 'signups'];
    }

    /**
     * Suffixes that exist as {$blog_prefix}{$suffix} on each site.
     *
     * @return string[]
     */
    public function get_per_blog_db_suffixes() {
        return [
            'posts', 'postmeta', 'options', 'comments', 'commentmeta',
            'terms', 'term_taxonomy', 'termmeta',
        ];
    }

    /**
     * Table suffixes this profile will actually queue, after intersecting
     * the explicit db_tables list (Quick) with the default set.
     *
     * @return string[]
     */
    public function get_effective_db_suffixes() {
        $allowed = $this->get_db_tables();
        if ($this->profile_id === self::QUICK) {
            $defaults = is_array($allowed) && !empty($allowed)
                ? $allowed
                : ['posts', 'options', 'postmeta', 'users', 'comments'];
            return array_values(array_unique($defaults));
        }

        $defaults = array_merge(
            ['posts', 'comments', 'postmeta', 'users', 'options', 'usermeta'],
            ['terms', 'term_taxonomy', 'termmeta', 'commentmeta'],
            ['sitemeta', 'blogs', 'blogmeta', 'signups']
        );
        if (is_array($allowed) && !empty($allowed)) {
            return array_values(array_intersect($defaults, $allowed));
        }
        return $defaults;
    }

    /**
     * Gutenberg handling: none | unescape | decode.
     *
     * @return string
     */
    public function get_gutenberg_mode() {
        if ($this->profile_id === self::QUICK) {
            return 'none';
        }
        if ($this->profile_id === self::DEEP) {
            return 'decode';
        }
        return 'unescape';
    }

    /**
     * Whether to maybe_unserialize / peel encoding chains on option/meta blobs.
     *
     * @return bool
     */
    public function should_unpack_db_values() {
        // Quick stays raw. Standard unserializes option/meta blobs.
        // Deep also peels encoding chains (see should_decode_encoding_chains).
        return $this->profile_id !== self::QUICK;
    }

    /**
     * Peel base64/gzip/rot13 layers on DB blobs (Deep only).
     */
    public function should_decode_encoding_chains() {
        return $this->profile_id === self::DEEP;
    }

    /**
     * Deep walks revisions in the main posts pass. Quick/Standard only
     * follow up when a live parent is flagged.
     *
     * @return bool
     */
    public function include_revisions_in_main_walk() {
        return $this->profile_id === self::DEEP;
    }

    /**
     * -1 = skip every transient; otherwise minimum LENGTH(option_value)
     * for _transient_* / _site_transient_* rows to be scanned.
     *
     * @return int
     */
    public function get_transient_min_length() {
        if ($this->profile_id === self::QUICK) {
            return -1;
        }
        return 1000;
    }

    /**
     * Soft cap on transient rows scanned (0 = unlimited). Standard only.
     *
     * @return int
     */
    public function get_transient_row_cap() {
        if ($this->profile_id === self::STANDARD) {
            return 200;
        }
        return 0;
    }

    /**
     * ID span per DB work unit. Deep uses smaller spans because unpack/decode
     * makes cost-per-row much less predictable.
     *
     * @return int
     */
    public function get_db_segment_span() {
        if ($this->profile_id === self::DEEP) {
            return $this->is_restricted_host() ? 250 : 400;
        }
        return 2000;
    }

    /**
     * Max network sites to queue (0 = no cap). Quick is current + main only.
     *
     * @return int
     */
    public function get_max_multisite_sites() {
        if ($this->profile_id === self::QUICK) {
            return 2;
        }
        if ($this->profile_id === self::STANDARD) {
            return $this->is_restricted_host() ? 25 : 50;
        }
        return 0;
    }

    /**
     * How many additional blogs the discovery worker expands per tick.
     *
     * @return int
     */
    public function get_multisite_sites_per_tick() {
        return $this->is_restricted_host() ? 8 : 15;
    }

    /**
     * Deep also walks archived sites. Deleted/spam are never queued.
     *
     * @return bool
     */
    public function include_archived_sites() {
        return $this->profile_id === self::DEEP;
    }

    /**
     * Should vulnerability scan be run for this profile?
     * Quick/Standard: disabled by default
     * Deep: enabled by default
     * Custom: user choice
     *
     * @return bool
     */
    public function should_run_vulnerability_scan() {
        if ($this->profile_id === self::QUICK || $this->profile_id === self::STANDARD) {
            return false;
        }

        if ($this->profile_id === self::DEEP) {
            return true;
        }

        // Custom profile uses explicit setting
        return $this->enable_vulnerability_scan;
    }

    /**
     * Get batch size based on profile and environment.
     *
     * @param string $type 'files' or 'db_rows'
     * @return int Batch size
     */
    public function get_batch_size($type = 'files') {
        $is_shared = defined('CLEAN_SWEEP_HOSTING_SHARED_LIMITS') && CLEAN_SWEEP_HOSTING_SHARED_LIMITS;

        if ($type === 'files') {
            if ($this->profile_id === self::QUICK) {
                return $is_shared ? 30 : 40;
            } elseif ($this->profile_id === self::STANDARD) {
                return $is_shared ? 40 : 60;
            } else {
                return $is_shared ? 60 : 100;
            }
        } else {
            // db_rows
            if ($this->profile_id === self::QUICK) {
                return $is_shared ? 60 : 100;
            } elseif ($this->profile_id === self::STANDARD) {
                return $is_shared ? 100 : 200;
            } else {
                return $is_shared ? 200 : 400;
            }
        }
    }

    /**
     * Check if running on a restricted/shared host.
     *
     * Phase 2: combines config.php CLEAN_SWEEP_HOSTING_SHARED_LIMITS with CleanSweep_HostDetector
     * when available so profile batch/pause settings adapt even when the
     * static clean_sweep_is_shared_hosting() heuristic is wrong.
     *
     * @return bool
     */
    public function is_restricted_host() {
        if (defined('CLEAN_SWEEP_HOSTING_SHARED_LIMITS') && CLEAN_SWEEP_HOSTING_SHARED_LIMITS) {
            return true;
        }
        // Runtime detector (CleanSweep_Scanner v2) — more accurate on real hosts.
        if (class_exists('CleanSweep_HostDetector', false)) {
            try {
                static $cached = null;
                if ($cached === null) {
                    $cached = (new CleanSweep_HostDetector())->isSharedHosting();
                }
                return $cached;
            } catch (Throwable $e) {
                // fall through
            }
        }
        return false;
    }

    /**
     * Apply Phase 2 host adaptation on top of the base profile.
     * Called at scan start so Quick/Standard tighten further on restricted hosts.
     *
     * @param CleanSweep_HostDetector|null $host
     * @return self (fluent)
     */
    public function apply_host_adaptation($host = null) {
        $restricted = $host instanceof CleanSweep_HostDetector
            ? $host->isSharedHosting()
            : $this->is_restricted_host();

        if (!$restricted) {
            return $this;
        }

        // Shared/restricted hosts: shrink *execution* knobs, not high-value scope.
        // mu-plugins + shallow uploads stay included; vendor/backups stay off for Quick.
        if ($this->profile_id === self::QUICK) {
            $this->max_depth = min($this->max_depth, 2);
            $this->include_vendor = false;
            $this->include_backups = false;
            $this->enable_encoding_chains = false;
            $this->enable_deconstructed = false;
            $this->enable_persistence = false;
            $this->enable_ml_anomaly_detection = false;
            $this->enable_parallel_scan = false;
            // Differential stays ON for Quick (Phase 2.1) — faster repeat scans.
            $this->enable_differential_scan = true;
        } elseif ($this->profile_id === self::STANDARD) {
            // Soften Standard on bad hosts without becoming as shallow as Quick.
            // Keep uploads + mu-plugins; still skip vendor/backups.
            $this->include_vendor = false;
            $this->include_backups = false;
            $this->enable_ml_anomaly_detection = false;
            $this->enable_parallel_scan = false;
        } elseif ($this->profile_id === self::DEEP) {
            // Deep on shared still disables parallel (fork often blocked).
            $this->enable_parallel_scan = false;
        }

        return $this;
    }

    /**
     * Human-readable summary for the UI (Phase 2.3).
     *
     * @return array
     */
    public function get_ui_summary() {
        $summaries = [
            self::QUICK => [
                'label' => 'Quick Scan',
                'tagline' => 'Light / shared hosting',
                'details' => 'plugins, themes, mu-plugins, shallow uploads; no vendor/backups. Smaller batches and early pauses. Incomplete by design.',
                'time_estimate' => '15–60s per drain step',
                'resilient_default' => false,
            ],
            self::STANDARD => [
                'label' => 'Standard Scan',
                'tagline' => 'Recommended default investigation',
                'details' => 'plugins, themes, mu-plugins, uploads (by depth); no vendor/backups. Full supported WP DB set. Best for most cleans.',
                'time_estimate' => '1–5 min total',
                'resilient_default' => true,
            ],
            self::DEEP => [
                'label' => 'Deep Scan',
                'tagline' => 'Broadest filesystem scope',
                'details' => 'Also includes vendor and backup trees; deepest walk. Prefer for reinfection or high malware volume; more FPs possible.',
                'time_estimate' => '5–20+ min total',
                'resilient_default' => false,
            ],
            self::CUSTOM => [
                'label' => 'Custom Scan',
                'tagline' => 'User-defined configuration',
                'details' => 'Uses the options you provide.',
                'time_estimate' => 'Varies',
                'resilient_default' => false,
            ],
        ];
        $base = $summaries[$this->profile_id] ?? $summaries[self::STANDARD];
        $base['profile_id'] = $this->profile_id;
        $base['restricted_host'] = $this->is_restricted_host();
        $base['differential'] = $this->enable_differential_scan;
        $base['heavy_analysis_disabled'] = $this->should_disable_heavy_analysis();
        $base['advisory'] = $this->get_environment_advisory();
        return $base;
    }

    /**
     * Get an advisory message for the user based on environment.
     * Returns null if no special message needed.
     *
     * @return string|null Advisory message or null
     */
    public function get_environment_advisory() {
        if ($this->is_restricted_host()) {
            if ($this->profile_id === self::QUICK) {
                return 'Restricted hosting detected. Quick Scan is using safer settings (smaller batches, frequent checkpoints, differential mode). Keep this tab open so auto-resume can finish the queue.';
            }
            if ($this->profile_id === self::DEEP) {
                return 'Restricted hosting detected. Deep Scan may pause often. Consider Quick or Standard, or run via CLI (bin/run-scan.php) for large sites.';
            }
            return 'Restricted hosting environment detected. Using safer scan settings with smaller batches and more frequent checkpoints. Keep this tab open for auto-resume.';
        }
        if ($this->profile_id === self::QUICK) {
            return 'Quick Scan is a light pass (shallow uploads; no vendor/backups). Use Standard for a full investigation.';
        }
        return null;
    }

    /**
     * Get checkpoint interval adjusted for environment and profile.
     * On restricted hosts, checkpoints are more frequent to enable faster resume.
     *
     * @return int CleanSweep_Checkpoint interval in items processed
     */
    public function get_checkpoint_interval() {
        $is_shared = $this->is_restricted_host();

        if ($this->profile_id === self::QUICK) {
            // Quick scan uses most frequent checkpoints for fastest resume
            return $is_shared ? 50 : 75;
        } elseif ($this->profile_id === self::STANDARD) {
            return $is_shared ? 75 : 100;
        } else {
            return $is_shared ? 100 : 150;
        }
    }

    /**
     * Get pause threshold - number of items after which scan should auto-pause.
     * On restricted hosts, this triggers earlier to prevent timeouts.
     *
     * Note: With the new CpuGovernor doing per-signature throttling, the
     * scan can run much longer per request without burning the CPU. These
     * thresholds reflect that — they govern when the *work unit* boundary
     * is hit (clean checkpoint, GC, optionally schedule continuation kick).
     *
     * @return int Number of items processed before auto-pause
     */
    public function get_pause_threshold() {
        $is_shared = $this->is_restricted_host();

        if ($this->profile_id === self::QUICK) {
            return $is_shared ? 75 : 150;
        } elseif ($this->profile_id === self::STANDARD) {
            return $is_shared ? 120 : 250;
        } else {
            return $is_shared ? 175 : 400;
        }
    }

    /**
     * Check if heavy analysis features should be disabled on this host.
     * Restricted hosts should disable ML anomaly detection and parallel processing.
     *
     * @return bool True if heavy analysis should be disabled
     */
    public function should_disable_heavy_analysis() {
        // On restricted hosts, always disable heavy analysis
        if ($this->is_restricted_host()) {
            return true;
        }

        // On any host, if this is Quick profile, disable heavy analysis
        if ($this->profile_id === self::QUICK) {
            return true;
        }

        return false;
    }

    /**
     * Get recommended time limit for scan phase (in seconds).
     *
     * The CpuGovernor now yields inside the hot path, so we can use a more
     * generous time budget per phase without pegging CPU. The 80% rule in
     * FileScanner::should_pause_for_time_limit() means we trigger clean pause
     * at this fraction of max_execution_time. Keep these below typical
     * Apache max_execution_time (30-120s) on shared hosting.
     *
     * @param string $phase Phase name (files, database, analysis)
     * @return int Time limit in seconds
     */
    public function get_phase_time_limit($phase = 'files') {
        $is_shared = $this->is_restricted_host();

        if ($phase === 'files') {
            return $is_shared ? 18 : 40;
        } elseif ($phase === 'database') {
            return $is_shared ? 22 : 45;
        } else {
            return $is_shared ? 12 : 25;
        }
    }

    /**
     * Get the throttle factor for CPU/IO control.
     *
     * Returns a preset name consumed by the new CpuGovernor, which actually
     * inspects system load. The previous return value (a float 1.0-2.0) was
     * mapped to a 1-1.5ms usleep once per file, which did nothing for the
     * actual CPU hot path (regex matching inside each file).
     *
     * The presets are:
     *  - aggressive: very restricted / under 1 CPU / under 256M
     *  - low:        shared hosting (the "scan kills my server" case)
     *  - balanced:   small VPS, 256-512M
     *  - high:       dedicated / 512M+
     *
     * The CpuGovernor will additionally *increase* delay if sys_getloadavg()
     * shows the box already busy — the preset is just the baseline.
     *
     * @return string Preset name
     */
    public function get_throttle_factor() {
        // Phase 2: Quick is always the conservative path; restricted hosts go lower.
        if ($this->is_restricted_host()) {
            return $this->profile_id === self::QUICK ? 'aggressive' : 'low';
        }
        if ($this->profile_id === self::QUICK) {
            return 'low';
        }
        if ($this->profile_id === self::DEEP) {
            return 'high';
        }
        return 'balanced';
    }

    /**
     * Get CpuGovernor preset for the new adaptive governor.
     * Same logic as get_throttle_factor() but always returns a preset.
     */
    public function get_cpu_governor_preset() {
        return $this->get_throttle_factor();
    }

    /**
     * Get exclusion patterns for this profile (base junk + optional dir flags for logging).
     *
     * Scope flags (uploads / mu-plugins / vendor / backups) are applied in is_excluded(),
     * not duplicated as hard-coded profile patterns.
     *
     * @return array Array of exclusion patterns
     */
    public function get_exclusion_patterns() {
        return $this->get_base_exclusions();
    }

    /**
     * Base exclusions for all profiles (universal junk / build noise).
     *
     * @return array
     */
    private function get_base_exclusions() {
        return [
            // Directory segments (matched as path segments where possible)
            '/cache/',
            '/logs/',
            '/node_modules/',
            '/bower_components/',
            '/.git/',
            '/.svn/',
            // File globs (matched against basename)
            '*.min.js',
            '*.min.css',
            '*.map',
            // Build artifacts
            '/assets/dist/',
            '/build/',
            '/dist/',
        ];
    }

    /**
     * Check if a path should be excluded from discovery / scanning.
     *
     * @param string $path Path to check
     * @return bool True if excluded
     */
    public function is_excluded($path) {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }

        // Drop-ins and .htaccess are never skipped (high-value persistence).
        if ($this->is_high_value_path($path)) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        // Profile scope flags — single source of truth for all profiles.
        if (!$this->include_uploads && $this->path_has_segment($normalized, 'uploads')) {
            return true;
        }
        if (!$this->include_mu_plugins && $this->path_has_segment($normalized, 'mu-plugins')) {
            return true;
        }
        if (!$this->include_vendor && $this->path_has_segment($normalized, 'vendor')) {
            return true;
        }
        if (!$this->include_backups && $this->is_backup_path($normalized)) {
            return true;
        }

        foreach ($this->get_exclusion_patterns() as $pattern) {
            if ($pattern === '/cache/' && $this->profile_id !== self::QUICK && $this->path_is_cache($normalized)) {
                if (is_dir($path)) {
                    return false;
                }
                if (is_file($path)) {
                    return !$this->is_php_family_file($normalized);
                }
                return false;
            }
            // Bundled plugin/theme *.min.js stays skipped (packer FPs). Uploads
            // min.js is a common malware drop and is still scanned.
            if ($pattern === '*.min.js' && $this->path_has_segment($normalized, 'uploads')) {
                continue;
            }
            if ($this->matches_pattern($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if path contains a directory segment equal to $segment (e.g. uploads, vendor).
     *
     * @param string $normalized_path Forward-slash path
     * @param string $segment
     * @return bool
     */
    private function path_has_segment($normalized_path, $segment) {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return false;
        }
        // .../segment/... or path ends with /segment
        return (bool) preg_match(
            '#/' . preg_quote($segment, '#') . '(/|$)#i',
            $normalized_path
        );
    }

    /**
     * Backup-related directories (backups, backup, backup-*, updraft, etc.).
     *
     * @param string $normalized_path
     * @return bool
     */
    private function is_backup_path($normalized_path) {
        // Segment starts with "backup" (backup, backups, backup-db, …)
        if (preg_match('#/(backup[^/]*)(/|$)#i', $normalized_path)) {
            return true;
        }
        // Common WP backup plugin folder names
        foreach (['updraft', 'backwpup', 'backupbuddy', 'wpvivid', 'ai1wm-backups'] as $name) {
            if ($this->path_has_segment($normalized_path, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match a path against a pattern.
     *
     * - Globs with * (e.g. *.min.js) match the basename (and full path as fallback).
     * - Directory-style patterns without * use substring match (e.g. /cache/).
     *
     * @param string $path Path to match (preferably normalized with /)
     * @param string $pattern Pattern (supports * glob)
     * @return bool
     */
    private function matches_pattern($path, $pattern) {
        $path = str_replace('\\', '/', (string) $path);
        $pattern = (string) $pattern;

        // Glob patterns like *.min.js — match basename first (full paths never matched ^*.min.js$)
        if (strpos($pattern, '*') !== false) {
            $base = basename($path);
            if (function_exists('fnmatch')) {
                $flags = defined('FNM_CASEFOLD') ? FNM_CASEFOLD : 0;
                if (@fnmatch($pattern, $base, $flags) || @fnmatch($pattern, $path, $flags)) {
                    return true;
                }
                return false;
            }
            // Fallback: convert glob to regex against basename
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            return (bool) @preg_match($regex, $base);
        }

        // Directory / substring patterns
        return strpos($path, $pattern) !== false;
    }

    /**
     * Get configuration as array (for logging).
     *
     * @return array
     */
    public function to_array() {
        return [
            'profile_id' => $this->profile_id,
            'max_depth' => $this->max_depth,
            'include_uploads' => $this->include_uploads,
            'include_mu_plugins' => $this->include_mu_plugins,
            'include_vendor' => $this->include_vendor,
            'include_backups' => $this->include_backups,
            'scan_file_types' => $this->scan_file_types,
            'db_post_limit' => $this->db_post_limit,
            'db_user_limit' => $this->db_user_limit,
            'db_comment_limit' => $this->db_comment_limit,
            'enable_encoding_chains' => $this->enable_encoding_chains,
            'enable_deconstructed' => $this->enable_deconstructed,
            'enable_persistence' => $this->enable_persistence,
            'exclusion_strictness' => $this->exclusion_strictness,
            'enable_vulnerability_scan' => $this->enable_vulnerability_scan,
            'enable_ml_anomaly_detection' => $this->enable_ml_anomaly_detection,
            'enable_parallel_scan' => $this->enable_parallel_scan,
            'enable_differential_scan' => $this->enable_differential_scan,
        ];
    }
}