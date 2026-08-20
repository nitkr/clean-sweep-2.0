<?php
/**
 * Clean Sweep - Database scan planner
 *
 * Shared by CleanSweep_Scanner (initial queue) and CleanSweep_DbSiteDiscoveryWorker (lazy
 * sub-site expansion). Builds ID-range work units with profile filters.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_DbScanPlanner {

    /** @var array<string,string> suffix => primary key */
    private const ID_COLUMNS = [
        'posts' => 'ID',
        'comments' => 'comment_ID',
        'postmeta' => 'meta_id',
        'users' => 'ID',
        'options' => 'option_id',
        'usermeta' => 'umeta_id',
        'terms' => 'term_id',
        'term_taxonomy' => 'term_taxonomy_id',
        'termmeta' => 'meta_id',
        'commentmeta' => 'meta_id',
        'sitemeta' => 'meta_id',
        'blogs' => 'blog_id',
        'blogmeta' => 'meta_id',
        'signups' => 'signup_id',
    ];

    /**
     * @return array<string,string>
     */
    public static function id_columns(): array {
        return self::ID_COLUMNS;
    }

    public static function id_column(string $suffix): string {
        return self::ID_COLUMNS[$suffix] ?? 'ID';
    }

    public static function base_prefix(): string {
        global $wpdb;
        if (isset($wpdb->base_prefix) && is_string($wpdb->base_prefix) && $wpdb->base_prefix !== '') {
            return $wpdb->base_prefix;
        }
        return isset($wpdb->prefix) ? (string)$wpdb->prefix : 'wp_';
    }

    public static function current_blog_id(): int {
        if (function_exists('get_current_blog_id')) {
            $id = (int)get_current_blog_id();
            if ($id > 0) {
                return $id;
            }
        }
        global $wpdb;
        $id = (int)($wpdb->blogid ?? 0);
        return $id > 0 ? $id : 1;
    }

    public static function blog_prefix(int $blog_id): string {
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb, 'get_blog_prefix')) {
            $prefix = $wpdb->get_blog_prefix($blog_id);
            if (is_string($prefix) && $prefix !== '') {
                return $prefix;
            }
        }
        $base = self::base_prefix();
        return $blog_id > 1 ? $base . $blog_id . '_' : $base;
    }

    /**
     * Target-aware multisite detection (recovery bootstrap may not define MULTISITE).
     */
    public static function is_multisite_target(): bool {
        if (function_exists('clean_sweep_detect_target_multisite')) {
            try {
                return (bool)clean_sweep_detect_target_multisite();
            } catch (Throwable $e) {
                // fall through
            }
        }
        if (function_exists('is_multisite') && is_multisite()) {
            return true;
        }
        global $wpdb;
        if (!isset($wpdb) || $wpdb === null) {
            return false;
        }
        $blogs = self::base_prefix() . 'blogs';
        // phpcs:ignore WordPress.DB.PreparedSQL
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $blogs));
        return !empty($found);
    }

    public static function table_exists(string $table): bool {
        global $wpdb;
        if (!isset($wpdb) || $table === '') {
            return false;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return !empty($found);
    }

    /**
     * @return string[]
     */
    public static function blogs_table(): string {
        return self::base_prefix() . 'blogs';
    }

    /**
     * WHERE clause for a table suffix, or null if the full table is in scope.
     */
    public static function where_clause(string $suffix, CleanSweep_ScanProfile $profile): ?string {
        if ($suffix === 'posts') {
            if ($profile->include_revisions_in_main_walk()) {
                return null;
            }
            return "(post_status IN ('publish','draft','private','pending') "
                . "OR post_type IN ('wp_block','wp_template','wp_template_part','wp_navigation','wp_global_styles')) "
                . "AND post_type <> 'revision'";
        }

        if ($suffix === 'comments') {
            if ($profile->get_profile_id() === CleanSweep_ScanProfile::DEEP) {
                return null;
            }
            if ($profile->get_profile_id() === CleanSweep_ScanProfile::QUICK) {
                return "comment_approved = '1'";
            }
            return "comment_approved IN ('1','0')";
        }

        if ($suffix === 'options' || $suffix === 'sitemeta') {
            return self::transient_where($suffix, $profile);
        }

        return null;
    }

    private static function transient_where(string $suffix, CleanSweep_ScanProfile $profile): ?string {
        $min = $profile->get_transient_min_length();
        $col = $suffix === 'sitemeta' ? 'meta_key' : 'option_name';
        $val = $suffix === 'sitemeta' ? 'meta_value' : 'option_value';

        $not_transient = "({$col} NOT LIKE '_transient%' AND {$col} NOT LIKE '_site_transient%')";
        if ($min < 0) {
            return $not_transient;
        }

        $large = "({$col} NOT LIKE '_transient_timeout%' AND {$col} NOT LIKE '_site_transient_timeout%'"
            . " AND ({$col} LIKE '_transient%' OR {$col} LIKE '_site_transient%')"
            . " AND LENGTH({$val}) > " . (int)$min . ")";

        return "({$not_transient} OR {$large})";
    }

    /**
     * Enqueue ID-range segments for one physical table.
     *
     * @return int Number of work units enqueued
     */
    public static function enqueue_table(
        CleanSweep_FileBasedScanWorkQueue $queue,
        string $scan_id,
        string $table,
        string $suffix,
        CleanSweep_ScanProfile $profile,
        CleanSweep_HostDetector $host,
        array $extra_payload = []
    ): int {
        global $wpdb;
        if (!isset($wpdb) || !self::table_exists($table)) {
            return 0;
        }

        $idcol = self::id_column($suffix);
        $where = $extra_payload['where_clause'] ?? self::where_clause($suffix, $profile);

        $caps = [
            'posts' => (int)$profile->get_db_post_limit(),
            'users' => (int)$profile->get_db_user_limit(),
            'comments' => (int)$profile->get_db_comment_limit(),
        ];
        $cap = (int)($extra_payload['row_cap'] ?? ($caps[$suffix] ?? 0));
        unset($extra_payload['row_cap']);

        $where_sql = $where ? " AND {$where}" : '';

        // phpcs:ignore WordPress.DB.PreparedSQL
        $range = $wpdb->get_row("SELECT MIN(`{$idcol}`) AS min_id, MAX(`{$idcol}`) AS max_id, COUNT(*) AS cnt FROM `{$table}` WHERE 1=1{$where_sql}");
        if (!$range || (int)$range->cnt === 0) {
            return 0;
        }

        $min = (int)$range->min_id;
        $max = (int)$range->max_id;
        $cnt = (int)$range->cnt;

        // Newest-first soft cap: walk the highest IDs, not the oldest.
        if ($cap > 0 && $cnt > $cap) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $floor = $wpdb->get_var($wpdb->prepare(
                "SELECT `{$idcol}` FROM `{$table}` WHERE 1=1{$where_sql} ORDER BY `{$idcol}` DESC LIMIT %d,1",
                max(0, $cap - 1)
            ));
            if ($floor !== null) {
                $min = max($min, (int)$floor);
                $cnt = min($cnt, $cap);
            }
        }

        $batch_size = (int)$profile->get_db_segment_span();
        if ($batch_size < 50) {
            $batch_size = 50;
        }
        $max_segments = $host->isSharedHosting() ? 80 : 200;
        $id_span = max(1, $max - $min + 1);
        $est = (int)ceil($id_span / max(1, $batch_size));
        if ($est > $max_segments) {
            $batch_size = (int)ceil($id_span / $max_segments);
        }

        $cur = $min;
        $segments = 0;
        while ($cur <= $max && $segments < $max_segments) {
            $end = min($cur + $batch_size - 1, $max);
            $payload = array_merge([
                'table' => $table,
                'id_column' => $idcol,
                'start_id' => $cur,
                'end_id' => $end,
                // Exclusive cursor: first row included is start_id.
                'last_processed_id' => $cur - 1,
            ], $extra_payload);
            if ($where) {
                $payload['where_clause'] = $where;
            }
            $priority = !empty($extra_payload['skip_revision_follow_on']) ? 140 : 150;
            $unit = CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_DB_TABLE_SEGMENT,
                $payload,
                $priority
            );
            $queue->enqueue($unit);
            $cur = $end + 1;
            $segments++;
        }

        if ($cur <= $max) {
            clean_sweep_log_message(
                "CleanSweep_DbScanPlanner: capped segments for {$table} at {$segments} (span={$batch_size})",
                'info'
            );
        }

        return $segments;
    }

    /**
     * Enqueue every in-scope table for one blog prefix.
     *
     * @return int
     */
    public static function enqueue_blog_tables(
        CleanSweep_FileBasedScanWorkQueue $queue,
        string $scan_id,
        string $blog_prefix,
        CleanSweep_ScanProfile $profile,
        CleanSweep_HostDetector $host
    ): int {
        $allowed = $profile->get_effective_db_suffixes();
        $per_blog = array_intersect($profile->get_per_blog_db_suffixes(), $allowed);
        $n = 0;
        foreach ($per_blog as $suffix) {
            $n += self::enqueue_table(
                $queue,
                $scan_id,
                $blog_prefix . $suffix,
                $suffix,
                $profile,
                $host
            );
        }
        return $n;
    }

    /**
     * Enqueue network-global tables once.
     *
     * @return int
     */
    public static function enqueue_global_tables(
        CleanSweep_FileBasedScanWorkQueue $queue,
        string $scan_id,
        CleanSweep_ScanProfile $profile,
        CleanSweep_HostDetector $host
    ): int {
        $allowed = $profile->get_effective_db_suffixes();
        $globals = array_intersect($profile->get_global_db_suffixes(), $allowed);
        $base = self::base_prefix();
        $n = 0;
        foreach ($globals as $suffix) {
            $n += self::enqueue_table(
                $queue,
                $scan_id,
                $base . $suffix,
                $suffix,
                $profile,
                $host
            );
        }
        return $n;
    }

    /**
     * Queue revision + autosave rows for flagged live posts.
     *
     * @param int[] $parent_ids
     * @return int
     */
    public static function enqueue_revisions(
        CleanSweep_FileBasedScanWorkQueue $queue,
        string $scan_id,
        string $posts_table,
        array $parent_ids,
        CleanSweep_ScanProfile $profile,
        CleanSweep_HostDetector $host
    ): int {
        global $wpdb;
        $parent_ids = array_values(array_unique(array_filter(array_map('intval', $parent_ids))));
        if (empty($parent_ids) || !self::table_exists($posts_table)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
        $args = array_merge($parent_ids, ['%-autosave-v1']);
        $sql = $wpdb->prepare(
            "SELECT MIN(ID) AS min_id, MAX(ID) AS max_id, COUNT(*) AS cnt
             FROM `{$posts_table}`
             WHERE post_parent IN ({$placeholders})
             AND (post_type = 'revision' OR post_name LIKE %s)",
            ...$args
        );
        $range = $wpdb->get_row($sql);
        if (!$range || (int)$range->cnt === 0) {
            return 0;
        }

        $in_list = implode(',', $parent_ids);
        $where = "post_parent IN ({$in_list}) AND (post_type = 'revision' OR post_name LIKE '%-autosave-v1')";

        return self::enqueue_table(
            $queue,
            $scan_id,
            $posts_table,
            'posts',
            $profile,
            $host,
            [
                'where_clause' => $where,
                'row_cap' => 0,
                'skip_revision_follow_on' => true,
            ]
        );
    }
}
