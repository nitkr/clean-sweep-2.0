<?php
/**
 * Finished report. No excerpts, database values, keys, or prompts.
 */
final class AiReport {

    /**
     * @param array<int,array> $threats
     * @param array<int,array> $verdicts
     * @param array<int,array> $log
     * @param string[] $keeps
     * @param array<int,array> $notChecked
     */
    public static function build(
        string $scanId,
        array $threats,
        array $verdicts,
        array $log,
        array $keeps,
        array $notChecked,
        AiPolicy $policy,
        string $logsStatus,
        string $root
    ): array {
        $keepSet = array_fill_keys($keeps, true);
        $byPath = [];
        foreach ($verdicts as $row) {
            if (($row['subject'] ?? 'file') === 'file' && ($row['path'] ?? '') !== '') {
                $byPath[(string) $row['path']] = $row;
            }
        }
        $signatureByPath = [];
        $database = [];
        $integrity = [];
        foreach ($threats as $row) {
            $source = (string) ($row['source'] ?? '');
            if ($source === 'database') {
                $database[] = [
                    'table' => (string) ($row['table'] ?? ''),
                    'row_id' => $row['row_id'] ?? null,
                    'column' => (string) ($row['column'] ?? ''),
                    'pattern' => (string) ($row['pattern'] ?? $row['signature_id'] ?? ''),
                    'label' => 'Signature match. Not sent to AI.',
                ];
                continue;
            }
            if ($source === 'integrity') {
                $integrity[] = [
                    'path' => (string) ($row['path'] ?? ''),
                    'pattern' => (string) ($row['pattern'] ?? $row['signature_id'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'risk_level' => (string) ($row['risk_level'] ?? ''),
                ];
                continue;
            }
            if ($source === 'file') {
                $rel = ltrim(AiPaths::rel((string) ($row['path'] ?? $row['file'] ?? ''), $root), '/');
                $signatureByPath[$rel] = $row;
            }
        }
        $dangerous = [];
        $undecided = [];
        $safe = [];
        $cleared = [];
        $keptRows = [];
        $found = 0;
        foreach ($byPath as $path => $verdict) {
            $v = (string) ($verdict['verdict'] ?? 'unsure');
            $hit = $signatureByPath[$path] ?? null;
            $item = self::fileItem($path, $verdict, $hit, $root);
            $kept = isset($keepSet[$path]);
            if ($v === 'likely_safe' && $kept) {
                $keptRows[] = $item;
                continue;
            }
            if ($v === 'likely_safe' && $hit !== null) {
                $cleared[] = $item;
                continue;
            }
            if ($v === 'likely_safe') {
                $safe[] = $item;
                continue;
            }
            if ($hit === null && ($v === 'malware' || $v === 'unsure')) {
                $found++;
            }
            if ($v === 'malware') {
                $dangerous[] = $item;
                continue;
            }
            $undecided[] = $item;
        }
        $folders = self::folderNotes($dangerous, $byPath, $root);
        $does = [];
        foreach ($dangerous as $item) {
            $does[] = [
                'path' => (string) $item['path'],
                'summary' => (string) $item['summary'],
            ];
        }
        $narrative = self::narrative(count($dangerous), count($safe), $does, $folders);
        $notSent = [];
        foreach ($log as $row) {
            $outcome = (string) ($row['outcome'] ?? '');
            if (strpos($outcome, 'Not sent') === 0) {
                $notSent[] = [
                    'path' => (string) ($row['path'] ?? ''),
                    'outcome' => $outcome,
                    'subject' => (string) ($row['subject'] ?? 'file'),
                ];
            }
        }
        $safeLog = [];
        foreach ($log as $row) {
            $safeLog[] = [
                'path' => (string) ($row['path'] ?? ''),
                'outcome' => (string) ($row['outcome'] ?? ''),
                'verdict' => (string) ($row['verdict'] ?? ''),
                'subject' => (string) ($row['subject'] ?? 'file'),
            ];
        }
        return [
            'scan_id' => $scanId,
            'summary' => [
                'malware' => count($dangerous),
                'unsure' => count($undecided),
                'judged_safe' => count($safe),
                'cleared' => count($cleared),
                'found_by_ai' => $found,
                'not_sent' => count($notSent),
                'omitted_safe' => 0,
                'logs' => $logsStatus,
                'full_pass' => $policy->fullPass(),
                'provider' => $policy->provider(),
                'database_signature' => count($database),
                'files_sent_after' => 0,
            ],
            'narrative' => $narrative,
            'does' => $does,
            'folders' => $folders,
            'dangerous' => $dangerous,
            'undecided' => $undecided,
            'safe' => $safe,
            'kept' => $keptRows,
            'malware' => $dangerous,
            'database' => $database,
            'not_sent' => $notSent,
            'cleared' => $cleared,
            'not_checked' => $notChecked,
            'signature_checksum' => $integrity,
            'log' => $safeLog,
        ];
    }

    /** @param array|null $hit */
    private static function fileItem(string $path, array $verdict, $hit, string $root): array {
        $abs = AiPaths::abs($path, $root);
        return [
            'path' => $path,
            'file' => $abs,
            'verdict' => (string) ($verdict['verdict'] ?? ''),
            'summary' => (string) ($verdict['summary'] ?? ''),
            'file_time' => (string) ($verdict['file_time'] ?? ''),
            'file_time_basis' => (string) ($verdict['file_time_basis'] ?? ''),
            'first_request_at' => (string) ($verdict['first_request_at'] ?? ''),
            'pattern' => $hit ? (string) ($hit['pattern'] ?? $hit['signature_id'] ?? '') : '',
            'match' => $hit ? (string) ($hit['match'] ?? '') : (string) ($verdict['summary'] ?? ''),
            'line_number' => $hit['line_number'] ?? null,
            'signature_hit' => $hit !== null,
        ];
    }

    /**
     * @param array<int,array{path:string,summary:string}> $does
     * @param array<int,array{text:string}> $folders
     */
    private static function narrative(int $dangerous, int $safe, array $does, array $folders): string {
        $parts = [];
        if ($dangerous === 0) {
            $parts[] = 'No file was judged to be malware.';
        } elseif ($dangerous === 1) {
            $parts[] = '1 dangerous file.';
        } else {
            $parts[] = $dangerous . ' dangerous files.';
        }
        if ($dangerous > 0) {
            $parts[] = 'Each sentence says what that code is supposed to do. The other code files in the same folder were reviewed.';
            foreach ($does as $row) {
                $sentence = trim((string) ($row['summary'] ?? ''));
                if ($sentence === '') {
                    $sentence = 'The model did not say what this file does.';
                }
                $parts[] = $row['path'] . ': ' . $sentence;
            }
            foreach ($folders as $folder) {
                $text = trim((string) ($folder['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        } elseif ($safe === 1) {
            $parts[] = '1 file was judged ordinary.';
        } elseif ($safe > 1) {
            $parts[] = $safe . ' files were judged ordinary.';
        }
        return implode(' ', $parts);
    }

    /**
     * One sentence per nested WordPress directory the scan left out.
     *
     * @param array<int,array> $sites
     * @return string[]
     */
    public static function nestedLines(array $sites): array {
        $lines = [];
        $seen = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }
            $path = trim(AiPaths::norm((string) ($site['path'] ?? '')), '/');
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $reason = trim((string) ($site['reason'] ?? ''));
            if ($reason === '') {
                $reason = AiPaths::NESTED_REASON;
            }
            $lines[$path] = 'Skipped ' . $path . '/. ' . $reason;
        }
        ksort($lines);
        return array_values($lines);
    }

    /**
     * One sentence per file whose bytes could not be placed in the model request.
     *
     * @param array<int,array> $notSent
     * @return string[]
     */
    public static function encodeLines(array $notSent): array {
        $lines = [];
        foreach ($notSent as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['outcome'] ?? '') !== AiReadLog::NOT_ENCODE) {
                continue;
            }
            $path = trim(AiPaths::norm((string) ($row['path'] ?? '')), '/');
            if ($path === '' || isset($lines[$path])) {
                continue;
            }
            $lines[$path] = 'Not sent: ' . $path . '. Its bytes could not be encoded, so the model did not see it.';
        }
        ksort($lines);
        return array_values($lines);
    }

    /**
     * Sentences for files a malware excerpt named.
     *
     * @param array<int,array> $rows
     * @param array<string,array> $verdictByPath
     * @return array<int,array{from:string,text:string}>
     */
    public static function spreadLines(array $rows, array $verdictByPath): array {
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = (string) ($row['from'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $path = (string) ($row['path'] ?? '');
            $host = (string) ($row['host'] ?? '');
            if ($status === 'none') {
                $text = 'The excerpt does not name another local file.';
            } elseif ($status === 'unparsed') {
                $text = 'The model reply was not the expected JSON.';
            } elseif ($status === 'unread') {
                $text = 'Infection targets could not be read from this file.';
            } elseif ($status === 'unencoded') {
                $text = 'Its bytes could not be encoded, so infection targets were not asked.';
            } elseif ($status === 'empty') {
                $text = 'The provider answered, but the answer was empty, so files this code names were not listed.';
            } elseif ($status === 'timeout') {
                $text = 'The provider did not answer in time, so files this code names were not listed.';
            } elseif ($status === 'remote' && $host !== '') {
                $text = 'Contacts ' . $host . '. That host was not downloaded.';
            } elseif ($status === 'missing' && $path !== '') {
                $text = 'Names ' . $path . '. That file is not on disk.';
            } elseif ($status === 'nested' && $path !== '') {
                $text = 'Names ' . $path . '. That path is inside another WordPress site, so it was left out.';
            } elseif ($status === 'skipped' && $path !== '') {
                $text = 'Names ' . $path . '. It was not sent.';
            } elseif ($status === 'queued' && $path !== '') {
                $verdict = (string) ($verdictByPath[$path]['verdict'] ?? '');
                $summary = trim((string) ($verdictByPath[$path]['summary'] ?? ''));
                if ($verdict === 'malware') {
                    $text = 'Names ' . $path . '. That file was judged malware.';
                    if ($summary !== '') {
                        $text .= ' ' . $summary;
                    }
                } elseif ($verdict === 'likely_safe') {
                    $text = 'Names ' . $path . '. That file was judged ordinary.';
                } elseif ($verdict === 'unsure') {
                    $text = 'Names ' . $path . '. That file was not decided.';
                } else {
                    $text = 'Names ' . $path . '.';
                }
            } else {
                continue;
            }
            $lines[] = ['from' => $from, 'text' => $text];
        }
        return $lines;
    }

    /**
     * One sentence for the infection summary.
     *
     * @param array<int,array> $notes
     */
    public static function checksumLine(string $status, array $notes): string {
        if ($status !== 'read') {
            return 'Package checksums were not read. Only signature hits and the fixed locations were sent.';
        }
        $rows = [];
        foreach ($notes as $note) {
            if (is_array($note)) {
                $rows[] = $note;
            }
        }
        if ($rows === []) {
            return 'Package checksums were read. No plugin or theme rows were stored.';
        }
        $counts = [
            'matched' => 0,
            'outside' => 0,
            'unavailable' => 0,
            'divergent' => 0,
            'outliers' => 0,
            'single' => 0,
            'missing' => 0,
        ];
        $sent = [];
        foreach ($rows as $note) {
            $decision = (string) ($note['decision'] ?? '');
            if ($decision === 'matched' && (int) ($note['queued'] ?? 0) > 0) {
                $counts['outside']++;
                $decision = 'outside';
            } elseif (isset($counts[$decision])) {
                $counts[$decision]++;
            }
            if (!in_array($decision, ['outside', 'unavailable', 'divergent', 'outliers', 'single'], true)) {
                continue;
            }
            $name = trim((string) ($note['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($note['slug'] ?? ''));
            }
            if ($name !== '') {
                $sent[] = $name;
            }
        }
        $parts = [];
        $parts[] = self::countSentence($counts['matched'], 'package matched WordPress.org and was not sent.', 'packages matched WordPress.org and were not sent.');
        $parts[] = self::countSentence($counts['outside'], 'package matched WordPress.org, and only files outside that list were sent.', 'packages matched WordPress.org, and only files outside that list were sent.');
        $parts[] = self::countSentence($counts['unavailable'], 'package has no official checksum.', 'packages have no official checksum.');
        $parts[] = self::countSentence($counts['divergent'], 'package differs from the official copy.', 'packages differ from the official copy.');
        $parts[] = self::countSentence($counts['outliers'], 'package has files that do not match.', 'packages have files that do not match.');
        $parts[] = self::countSentence($counts['single'], 'package is a single file in the plugins or themes folder.', 'packages are a single file in the plugins or themes folder.');
        $parts[] = self::countSentence($counts['missing'], 'package folder was missing.', 'package folders were missing.');
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
        if ($sent !== []) {
            $parts[] = 'Sent packages: ' . implode(', ', $sent) . '.';
        }
        if ($parts === []) {
            return 'Package checksums were read.';
        }
        return implode(' ', $parts);
    }

    private static function countSentence(int $count, string $one, string $many): string {
        if ($count === 1) {
            return '1 ' . $one;
        }
        if ($count > 1) {
            return $count . ' ' . $many;
        }
        return '';
    }

    /**
     * @param array<int,array> $dangerous
     * @param array<string,array> $byPath
     * @return array<int,array>
     */
    private static function folderNotes(array $dangerous, array $byPath, string $root): array {
        $rels = [];
        foreach ($dangerous as $item) {
            $path = ltrim((string) ($item['path'] ?? ''), '/');
            if ($path !== '') {
                $rels[] = $path;
            }
        }
        usort($rels, static function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });
        $covered = [];
        $notes = [];
        foreach ($rels as $rel) {
            $dir = dirname($rel);
            if ($dir === '.' || $dir === '') {
                $dir = '';
            }
            $inside = false;
            foreach ($covered as $done) {
                if ($dir === $done || ($done !== '' && strpos($dir, $done . '/') === 0)) {
                    $inside = true;
                    break;
                }
            }
            if ($inside) {
                continue;
            }
            $covered[] = $dir;
            $found = AiFollowUp::codeBeside($rel, $root);
            $ordinary = 0;
            $undecided = 0;
            $also = 0;
            $unreviewed = 0;
            foreach ($found['paths'] as $path) {
                $verdict = (string) ($byPath[$path]['verdict'] ?? '');
                if ($verdict === 'malware') {
                    $also++;
                } elseif ($verdict === 'likely_safe') {
                    $ordinary++;
                } elseif ($verdict === 'unsure') {
                    $undecided++;
                } else {
                    $unreviewed++;
                }
            }
            $note = [
                'dir' => $dir === '' ? '.' : $dir,
                'ordinary' => $ordinary,
                'undecided' => $undecided,
                'also_dangerous' => $also,
                'unreviewed' => $unreviewed,
                'not_code' => (int) $found['not_code'],
                'truncated' => !empty($found['truncated']),
            ];
            $note['text'] = self::folderSentence($note);
            $notes[] = $note;
        }
        return $notes;
    }

    /** @param array $note */
    private static function folderSentence(array $note): string {
        $dir = (string) ($note['dir'] ?? '.');
        $label = $dir === '.' ? 'the site root' : $dir;
        $reviewed = (int) $note['ordinary'] + (int) $note['undecided'] + (int) $note['also_dangerous'];
        $parts = [];
        if ($reviewed === 0) {
            $parts[] = 'Folder ' . $label . ' had no other code files.';
        } else {
            $bits = [];
            if ((int) $note['also_dangerous'] > 0) {
                $bits[] = (int) $note['also_dangerous'] . ' also dangerous';
            }
            if ((int) $note['ordinary'] > 0) {
                $bits[] = (int) $note['ordinary'] . ' ordinary';
            }
            if ((int) $note['undecided'] > 0) {
                $bits[] = (int) $note['undecided'] . ' not decided';
            }
            $noun = $reviewed === 1 ? 'file' : 'files';
            $parts[] = 'Folder ' . $label . ': ' . $reviewed . ' other code ' . $noun . ' reviewed (' . implode(', ', $bits) . ').';
        }
        $notCode = (int) $note['not_code'];
        if ($notCode > 0) {
            $parts[] = $notCode === 1
                ? '1 other file is not code and was not sent.'
                : $notCode . ' other files are not code and were not sent.';
        }
        $unreviewed = (int) $note['unreviewed'];
        if ($unreviewed > 0) {
            $parts[] = $unreviewed === 1
                ? '1 code file in that folder was not reviewed.'
                : $unreviewed . ' code files in that folder were not reviewed.';
        }
        if (!empty($note['truncated'])) {
            $parts[] = 'That folder was only partly opened.';
        }
        return implode(' ', $parts);
    }
}
