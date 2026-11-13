<?php
/**
 * Read MySQL triggers, events, and stored routines once per database scan.
 *
 * WordPress does not create these objects. A trigger that inserts a user
 * when a comment or post is written lives here, not in a table row.
 */
final class CleanSweep_DbSchemaPersistence {

    private const STATEMENT_CAP = 65536;
    private const BODY_FETCH_CAP = 30;

    /**
     * Quote a schema or object name for SHOW statements.
     * $wpdb->prepare() quotes strings, which breaks SHOW ... FROM.
     */
    public static function quote_identifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function triggers_sql(string $database): string {
        return 'SHOW TRIGGERS FROM ' . self::quote_identifier($database);
    }

    public static function events_sql(string $database): string {
        return 'SHOW EVENTS FROM ' . self::quote_identifier($database);
    }

    /**
     * @param object $wpdb
     * @return array{threats: array<int,array>, counts: array<string,int>, unread: array<string,string>}
     */
    public function scan($wpdb): array {
        $empty = [
            'threats' => [],
            'counts' => ['triggers' => 0, 'events' => 0, 'routines' => 0],
            'unread' => [],
        ];
        $database = is_object($wpdb) ? (string) ($wpdb->dbname ?? '') : '';
        if ($database === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $empty;
        }

        $suppress = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : null;
        try {
            return $this->collect($wpdb, $database);
        } finally {
            if ($suppress !== null && method_exists($wpdb, 'suppress_errors')) {
                $wpdb->suppress_errors($suppress);
            }
        }
    }

    /**
     * Classify one schema object. The statement may be a trigger body or a
     * SHOW CREATE script. Detection does not depend on a particular login,
     * email, or bait string.
     *
     * @param array{kind: string, name: string, table?: string, timing?: string, event?: string, statement?: string, definer?: string} $object
     * @return array
     */
    public static function assess(array $object): array {
        $kind = strtolower((string) ($object['kind'] ?? 'trigger'));
        if (!in_array($kind, ['trigger', 'event', 'procedure', 'function'], true)) {
            $kind = 'trigger';
        }
        $name = self::clip((string) ($object['name'] ?? ''), 128);
        if ($name === '') {
            $name = $kind;
        }
        $table = self::clip((string) ($object['table'] ?? ''), 128);
        $timing = strtoupper(self::clip((string) ($object['timing'] ?? ''), 16));
        $event = strtoupper(self::clip((string) ($object['event'] ?? ''), 16));
        $statement = self::clip((string) ($object['statement'] ?? ''), self::STATEMENT_CAP);
        $definer = self::clip((string) ($object['definer'] ?? ''), 128);
        $flat = self::flatten($statement);

        $writes_users = self::writes_table($flat, 'users');
        $writes_usermeta = self::writes_table($flat, 'usermeta');
        $plants_account = $writes_users || $writes_usermeta;
        $writes_content = self::writes_table($flat, 'options')
            || self::writes_table($flat, 'sitemeta')
            || self::writes_table($flat, 'posts')
            || self::writes_table($flat, 'comments');

        if ($plants_account) {
            $level = 'critical';
            $score = 95;
        } elseif ($kind === 'procedure' || $kind === 'function') {
            $level = ($writes_content || self::has_dynamic_sql($flat)) ? 'high' : 'medium';
            $score = $level === 'high' ? 75 : 50;
        } else {
            $level = 'high';
            $score = $writes_content || self::has_dynamic_sql($flat) ? 85 : 70;
        }

        $summary = self::summary($kind, $name, $table, $timing, $event, $level, $flat, $plants_account);
        $preview = $statement !== '' ? $statement : $summary;
        $location_table = $table !== '' ? $table : 'mysql_schema';

        return [
            'id' => md5('db-schema|' . $kind . '|' . strtolower($name) . '|' . strtolower($location_table)),
            'source' => 'database',
            'pattern' => 'MYSQL_' . strtoupper($kind),
            'signature_id' => 'MYSQL_' . strtoupper($kind),
            'category' => 'persistence',
            'family' => 'db_persistence',
            'match' => self::clip($summary, 100),
            'matched_content' => self::clip($summary . ($statement !== '' ? "\n" . $statement : ''), 800),
            'content_preview' => self::clip($preview, 200),
            'description' => $summary,
            'threat_level' => $level,
            'risk_level' => $level,
            'severity' => $level,
            'risk_score' => $score,
            'table' => $location_table,
            'row_id' => $name,
            'column' => $kind,
            'open_in_editor' => 'DB:' . $location_table . ':' . $name . ':' . $kind,
            'definer' => $definer,
            'detected_at' => date('c'),
        ];
    }

    /**
     * @param array<int,string> $failures
     */
    public static function unread_finding(array $failures): array {
        $detail = self::clip(implode('; ', $failures), 300);
        $summary = 'The database user could not list schema persistence objects (' . $detail . '). This scan did not see those objects.';
        return [
            'id' => md5('db-schema|unread|' . $detail),
            'source' => 'database',
            'pattern' => 'MYSQL_SCHEMA_UNREAD',
            'signature_id' => 'MYSQL_SCHEMA_UNREAD',
            'category' => 'persistence',
            'family' => 'db_persistence',
            'match' => self::clip($summary, 100),
            'matched_content' => $summary,
            'content_preview' => self::clip($summary, 200),
            'description' => $summary,
            'threat_level' => 'info',
            'risk_level' => 'info',
            'severity' => 'info',
            'risk_score' => 10,
            'table' => 'mysql_schema',
            'row_id' => 'permission',
            'column' => 'check',
            'open_in_editor' => 'DB:mysql_schema:permission:check',
            'detected_at' => date('c'),
        ];
    }

    /**
     * @param object $wpdb
     * @return array{threats: array<int,array>, counts: array<string,int>, unread: array<string,string>}
     */
    private function collect($wpdb, string $database): array {
        $threats = [];
        $unread = [];
        $counts = ['triggers' => 0, 'events' => 0, 'routines' => 0];

        $triggers = $this->rows($wpdb, self::triggers_sql($database));
        if (!$triggers['ok']) {
            $unread['triggers'] = $triggers['error'];
        } else {
            $counts['triggers'] = count($triggers['rows']);
            foreach ($triggers['rows'] as $row) {
                $threats[] = self::assess([
                    'kind' => 'trigger',
                    'name' => self::field($row, ['Trigger', 'trigger_name', 'Name']),
                    'table' => self::field($row, ['Table', 'Event_object_table']),
                    'timing' => self::field($row, ['Timing', 'Action_timing']),
                    'event' => self::field($row, ['Event', 'Event_manipulation']),
                    'statement' => self::field($row, ['Statement', 'Action_statement']),
                    'definer' => self::field($row, ['Definer']),
                ]);
            }
        }

        $events = $this->rows($wpdb, self::events_sql($database));
        if (!$events['ok']) {
            $unread['events'] = $events['error'];
        } else {
            $counts['events'] = count($events['rows']);
            $fetched = 0;
            foreach ($events['rows'] as $row) {
                $name = self::field($row, ['Name', 'Event_name']);
                $body = '';
                if ($name !== '' && $fetched < self::BODY_FETCH_CAP) {
                    $body = $this->create_body($wpdb, 'EVENT', $name);
                    $fetched++;
                }
                $threats[] = self::assess([
                    'kind' => 'event',
                    'name' => $name,
                    'table' => '',
                    'timing' => self::field($row, ['Type', 'Status']),
                    'event' => self::field($row, ['Interval field']),
                    'statement' => $body,
                    'definer' => self::field($row, ['Definer']),
                ]);
            }
        }

        foreach (['PROCEDURE' => 'procedure', 'FUNCTION' => 'function'] as $sql_kind => $kind) {
            $status = $this->rows(
                $wpdb,
                $wpdb->prepare('SHOW ' . $sql_kind . ' STATUS WHERE Db = %s', $database)
            );
            if (!$status['ok']) {
                $unread[$kind] = $status['error'];
                continue;
            }
            $counts['routines'] += count($status['rows']);
            $fetched = 0;
            foreach ($status['rows'] as $row) {
                $name = self::field($row, ['Name']);
                $body = '';
                if ($name !== '' && $fetched < self::BODY_FETCH_CAP) {
                    $body = $this->create_body($wpdb, $sql_kind, $name);
                    $fetched++;
                }
                $threats[] = self::assess([
                    'kind' => $kind,
                    'name' => $name,
                    'table' => '',
                    'statement' => $body,
                    'definer' => self::field($row, ['Definer']),
                ]);
            }
        }

        if ($unread !== []) {
            $parts = [];
            foreach ($unread as $which => $error) {
                $parts[] = $which . ': ' . self::clip($error, 160);
            }
            $threats[] = self::unread_finding($parts);
        }

        return [
            'threats' => $threats,
            'counts' => $counts,
            'unread' => $unread,
        ];
    }

    /**
     * @param object $wpdb
     * @return array{ok: bool, error: string, rows: array<int,object>}
     */
    private function rows($wpdb, string $sql): array {
        if (is_object($wpdb) && property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($sql);
        $error = is_object($wpdb) && isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        if ($error !== '') {
            return ['ok' => false, 'error' => $error, 'rows' => []];
        }
        if (!is_array($rows)) {
            return ['ok' => false, 'error' => 'Query returned no row set', 'rows' => []];
        }
        $objects = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $objects[] = $row;
            }
        }
        return ['ok' => true, 'error' => '', 'rows' => $objects];
    }

    /**
     * @param object $wpdb
     */
    private function create_body($wpdb, string $kind, string $name): string {
        if (!in_array($kind, ['EVENT', 'PROCEDURE', 'FUNCTION', 'TRIGGER'], true)) {
            return '';
        }
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $sql = 'SHOW CREATE ' . $kind . ' ' . self::quote_identifier($name);
        $row = $wpdb->get_row($sql);
        $error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        if ($error !== '' || !is_object($row)) {
            return '';
        }
        return self::clip(self::field($row, [
            'Create Event',
            'Create Procedure',
            'Create Function',
            'Create Trigger',
            'Statement',
        ]), self::STATEMENT_CAP);
    }

    private static function writes_table(string $sql, string $suffix): bool {
        $suffix = preg_quote($suffix, '#');
        return (bool) preg_match(
            '#\b(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE)\s+`?[A-Za-z0-9_]*' . $suffix . '`?(?![A-Za-z0-9_])#i',
            $sql
        );
    }

    private static function has_dynamic_sql(string $sql): bool {
        return (bool) preg_match('#\b(?:PREPARE|EXECUTE)\b|\bCALL\s+[A-Za-z0-9_]+#i', $sql);
    }

    private static function flatten(string $sql): string {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        if (!is_string($sql)) {
            return '';
        }
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql);
        return is_string($sql) ? $sql : '';
    }

    private static function summary(
        string $kind,
        string $name,
        string $table,
        string $timing,
        string $event,
        string $level,
        string $flat,
        bool $plants_account
    ): string {
        if ($kind === 'trigger') {
            $when = trim($timing . ' ' . $event);
            $text = 'MySQL trigger `' . $name . '`';
            if ($when !== '') {
                $text .= ' runs ' . $when;
            }
            if ($table !== '') {
                $text .= ' on `' . $table . '`';
            }
            $text .= '.';
        } elseif ($kind === 'event') {
            $text = 'MySQL event `' . $name . '` is scheduled in this database.';
        } else {
            $text = 'MySQL ' . $kind . ' `' . $name . '` is stored in this database.';
        }

        if ($plants_account) {
            $text .= ' It writes the users or usermeta table.';
            $login = self::mentioned_login($flat);
            if ($login !== '') {
                $text .= ' It names the login `' . $login . '`.';
            }
            $text .= ' Deleting that account leaves this object in place.';
            return $text;
        }

        if ($level === 'high' && self::writes_table($flat, 'options')) {
            $text .= ' It writes the options table.';
        }
        $text .= ' WordPress does not create this object.';
        return $text;
    }

    private static function mentioned_login(string $sql): string {
        if (!preg_match(
            "#INSERT\\s+INTO\\s+`?[A-Za-z0-9_]*users`?(?![A-Za-z0-9_]).{0,400}?'([A-Za-z0-9._@-]{1,60})'#is",
            $sql,
            $matches
        )) {
            return '';
        }
        $login = $matches[1];
        if ($login === '' || strpos($login, '$') !== false) {
            return '';
        }
        return $login;
    }

    /**
     * @param object $row
     * @param array<int,string> $names
     */
    private static function field($row, array $names): string {
        if (!is_object($row)) {
            return '';
        }
        foreach ($names as $name) {
            if (isset($row->$name) && $row->$name !== null && (string) $row->$name !== '') {
                return (string) $row->$name;
            }
        }
        $lower = [];
        foreach (get_object_vars($row) as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }
        foreach ($names as $name) {
            $key = strtolower($name);
            if (array_key_exists($key, $lower) && $lower[$key] !== null && (string) $lower[$key] !== '') {
                return (string) $lower[$key];
            }
        }
        return '';
    }

    private static function clip(string $value, int $max): string {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}
