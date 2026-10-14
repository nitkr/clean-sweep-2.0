<?php
/**
 * One slice of the AI scan per call. The browser is the clock.
 */
final class AiPipeline {

    private AiStore $store;
    private AiConfig $config;
    private ?string $rootOverride;
    /** @var array|null Token totals for the slice that is in progress. */
    private ?array $usageScratch = null;

    public function __construct(?AiStore $store = null, ?string $siteRoot = null) {
        $this->store = $store ?: new AiStore();
        $this->config = new AiConfig($this->store);
        $this->rootOverride = ($siteRoot !== null && $siteRoot !== '')
            ? rtrim(AiPaths::norm($siteRoot), '/') . '/'
            : null;
    }

    public function store(): AiStore {
        return $this->store;
    }

    public function status(): array {
        $state = $this->state();
        $scanId = (string) ($state['scan_id'] ?? '');
        $log = new AiReadLog($this->store);
        return [
            'scan_id' => $scanId,
            'phase' => (string) ($state['phase'] ?? 'idle'),
            'ai_status' => (string) ($state['ai_status'] ?? 'idle'),
            'done' => (int) ($state['done'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'error' => (string) ($state['error'] ?? ''),
            'policy' => is_array($state['policy'] ?? null) ? $state['policy'] : [],
            'log_count' => $scanId === '' ? 0 : $log->count($scanId),
            'logs' => (string) ($state['logs'] ?? ''),
            'files_sent_after' => (int) ($state['files_sent_after'] ?? 0),
        ];
    }

    /**
     * @return array{ok:bool,error?:string,code?:string}
     */
    public function begin(string $scanId): array {
        $public = $this->config->getPublic();
        if (empty($public['tested'])) {
            return ['ok' => false, 'error' => 'Test a key and model first.', 'code' => 'NOT_TESTED'];
        }
        if ($scanId === '') {
            return ['ok' => false, 'error' => 'Missing scan id.', 'code' => 'NO_SCAN'];
        }
        $current = $this->state();
        if (($current['scan_id'] ?? '') !== $scanId) {
            $this->store->remove('queue.jsonl');
            $this->store->remove('verdicts.jsonl');
            $this->store->remove('read-log.jsonl');
            $this->store->removeReports();
        }
        $policy = AiPolicy::fromConfig($this->config, new AiExcludeList($this->store, $this->siteRoot()));
        $this->writeState([
            'scan_id' => $scanId,
            'phase' => 'signature',
            'ai_status' => 'running',
            'cursor' => ['stage' => 'hits', 'index' => 0, 'file' => 0, 'queued' => 0],
            'policy' => $policy->snapshot(),
            'done' => 0,
            'total' => 0,
            'error' => '',
            'logs' => 'pending',
            'followup' => 0,
            'not_checked' => [],
            'log_lines' => [],
            'first_request' => [],
            'access_log' => '',
            'files_sent_after' => 0,
            'usage' => AiUsage::blank((string) ($this->config->raw()['model'] ?? '')),
        ]);
        $created = $this->state();
        $this->attachUsageMeta($created);
        $this->writeState($created);
        return ['ok' => true];
    }

    public function cancel(): array {
        $state = $this->state();
        $state['ai_status'] = 'cancelled';
        $state['phase'] = 'cancelled';
        $this->writeState($state);
        return $this->status();
    }

    /**
     * @param callable|null $malwareStatus function(string $scanId): array
     * @param callable|null $complete function(string $user, int $maxTokens): array
     */
    public function step(?callable $malwareStatus = null, ?callable $complete = null): array {
        $state = $this->state();
        if (in_array((string) ($state['ai_status'] ?? ''), ['cancelled', 'error'], true)) {
            return $this->status();
        }
        if (($state['scan_id'] ?? '') === '' || ($state['ai_status'] ?? '') === 'idle') {
            $state['ai_status'] = 'error';
            $state['error'] = 'Start the signature pass first.';
            $this->writeState($state);
            return $this->status();
        }
        $scanId = (string) $state['scan_id'];
        $phase = (string) ($state['phase'] ?? 'signature');
        if ($phase === 'signature') {
            $mal = $malwareStatus ? $malwareStatus($scanId) : $this->readMalwareStatus($scanId);
            $st = (string) ($mal['status'] ?? '');
            if (in_array($st, ['failed', 'cancelled'], true)) {
                $state['ai_status'] = 'error';
                $state['error'] = 'The signature pass did not finish.';
                $this->writeState($state);
                return $this->status();
            }
            $state['done'] = (int) ($mal['counters']['files_scanned'] ?? 0);
            $state['total'] = (int) ($mal['counters']['files_scanned'] ?? 0);
            if ($st !== 'completed') {
                $this->writeState($state);
                return $this->status();
            }
            $state['phase'] = 'queue';
            $state['done'] = 0;
            $state['total'] = 0;
            $this->writeState($state);
            return $this->status();
        }
        if ($phase === 'queue') {
            $root = $this->siteRoot();
            $threats = $this->loadThreats($scanId);
            if ($threats === null) {
                $state['ai_status'] = 'error';
                $state['error'] = 'The signature results could not be read.';
                $this->writeState($state);
                return $this->status();
            }
            $loaded = AiChecksumSource::load(AiChecksumSource::pathForStore($this->store->dir()));
            if (($state['checksums'] ?? '') !== 'read') {
                $state['checksums'] = $loaded['status'];
            }
            $packages = is_array($loaded['packages']) ? $loaded['packages'] : [];
            $policy = $this->policyFromState($state);
            $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : ['stage' => 'hits', 'index' => 0, 'file' => 0, 'queued' => 0];
            $queue = new AiQueue($root, $packages, $threats, $policy, $scanId, $this->store);
            $result = $queue->slice($cursor, microtime(true) + 12);
            $prior = is_array($state['package_notes'] ?? null) ? $state['package_notes'] : [];
            $state['package_notes'] = array_merge($prior, $queue->packageNotes());
            $state['nested_sites'] = $this->mergeNested(
                is_array($state['nested_sites'] ?? null) ? $state['nested_sites'] : [],
                $queue->nestedSites()
            );
            $state['cursor'] = $cursor;
            $queued = 0;
            foreach ($this->store->readJsonl('queue.jsonl') as $row) {
                if (($row['scan_id'] ?? '') === $scanId) {
                    $queued++;
                }
            }
            $state['total'] = $queued;
            $state['done'] = 0;
            if (!empty($result['done'])) {
                $dbUnits = (new AiDatabaseQueue())->collect($policy, $threats);
                foreach ($dbUnits as $unit) {
                    if (is_array($unit)) {
                        $unit['scan_id'] = $scanId;
                        $this->store->appendJsonl('queue.jsonl', $unit);
                    }
                }
                $state['phase'] = 'review';
            }
            $this->writeState($state);
            return $this->status();
        }
        if ($phase === 'review') {
            $this->attachLogsToState($state);
            $policy = $this->policyFromState($state);
            $root = $this->siteRoot();
            $model = (string) ($this->config->raw()['model'] ?? '');
            $complete = $this->countingComplete($complete ?: $this->defaultComplete(), $state);
            $review = new AiReview(
                $this->store,
                $root,
                $policy,
                $model,
                $complete,
                $this->hitsByPath($scanId, $root),
                is_array($state['first_request'] ?? null) ? $state['first_request'] : [],
                [],
                is_array($state['log_lines'] ?? null) ? $state['log_lines'] : []
            );
            $result = $review->batch($scanId);
            $this->keepUsage($state);
            $state['done'] = count((new AiVerdictStore($this->store))->forScan($scanId));
            $state['total'] = $this->queueCount($scanId);
            if (!empty($result['error'])) {
                if (!empty($result['timeout']) && (int) ($state['review_timeout_tries'] ?? 0) < 1) {
                    $state['review_timeout_tries'] = 1;
                    $this->writeState($state);
                    return $this->status();
                }
                $state['review_timeout_tries'] = 0;
                $state['ai_status'] = 'error';
                $state['error'] = $this->withJudgedCounts(
                    (string) $result['error'],
                    (int) $state['done'],
                    (int) $state['total']
                );
                $this->writeReport($state);
                $this->writeState($state);
                return $this->status();
            }
            $state['review_timeout_tries'] = 0;
            if (!empty($result['done'])) {
                $logs = (string) ($state['logs'] ?? '');
                $state['phase'] = ($logs === 'pending' || $logs === '') ? 'logs' : 'followup';
            }
            $this->writeState($state);
            return $this->status();
        }
        if ($phase === 'logs') {
            $policy = $this->policyFromState($state);
            $state['log_lines'] = [];
            $state['first_request'] = [];
            $state['access_log'] = '';
            if (!$policy->requestLogs()) {
                $state['logs'] = 'skipped';
                $state['logs_note'] = 'Request logs were turned off for this scan.';
            } else {
                $located = $this->locateLogs();
                $state['logs'] = ($located['status'] ?? '') === 'ok' ? 'checked' : 'skipped';
                if (($located['status'] ?? '') === 'ok' && !empty($located['access_log'])) {
                    $state['access_log'] = (string) $located['access_log'];
                    $paths = $this->queuedPaths($scanId);
                    $this->fillLogLines($state, $paths, $policy->includeQueryStrings(), $located);
                    $this->stampFirstRequest($scanId, is_array($state['first_request'] ?? null) ? $state['first_request'] : []);
                    $state['logs_note'] = $this->logsNote($state);
                } else {
                    $state['logs_note'] = AiHostLogs::skipNote(
                        $_SERVER,
                        $this->siteRoot(),
                        static function (string $path): bool {
                            return is_readable($path);
                        }
                    );
                    if ($state['logs_note'] === '') {
                        $state['logs_note'] = 'Request logs were not checked.';
                    }
                }
            }
            $this->logHostLogs((string) ($state['logs_note'] ?? ''));
            $state['phase'] = 'followup';
            if (!isset($state['followup'])) {
                $state['followup'] = 0;
            }
            $this->writeState($state);
            return $this->status();
        }
        if ($phase === 'followup') {
            $complete = $this->countingComplete($complete ?: $this->defaultComplete(), $state);
            $this->runFollowUp($state, $complete);
            $this->keepUsage($state);
            if (($state['ai_status'] ?? '') === 'error') {
                $this->writeReport($state);
            }
            $this->writeState($state);
            return $this->status();
        }
        if ($phase === 'report') {
            if ($complete === null) {
                $this->fetchWindow($state);
            }
            $this->writeReport($state);
            $state['phase'] = 'report';
            $state['ai_status'] = 'completed';
            $this->writeState($state);
            return $this->status();
        }
        return $this->status();
    }

    /**
     * Send one secret file after the report exists.
     *
     * @return array{ok:bool,error?:string,code?:string}
     */
    public function sendFile(string $rel, ?callable $complete = null): array {
        $state = $this->state();
        if (($state['ai_status'] ?? '') !== 'completed') {
            return ['ok' => false, 'error' => 'The report is not ready.', 'code' => 'NOT_READY'];
        }
        $root = $this->siteRoot();
        $rel = ltrim(AiPaths::norm($rel), '/');
        $policy = $this->policyFromState($state);
        if ($policy->excludes()->matches($rel)) {
            return ['ok' => false, 'error' => 'That path is on your skip list.', 'code' => 'SKIPPED'];
        }
        if (!AiPaths::insideRoot($rel, $root) || !is_file(AiPaths::abs($rel, $root))) {
            return ['ok' => false, 'error' => 'That file is not on the site.', 'code' => 'OUTSIDE_ROOT'];
        }
        $scanId = (string) $state['scan_id'];
        $this->store->appendJsonl('queue.jsonl', [
            'scan_id' => $scanId,
            'subject' => 'file',
            'path' => $rel,
            'reasons' => ['operator_send'],
            'signature_hit' => false,
            'truncated' => false,
            'masked_heavy' => false,
        ]);
        $sendPolicy = new AiPolicy(true, $policy->requestLogs(), $policy->excludes(), $policy->provider());
        $complete = $this->countingComplete($complete ?: $this->defaultComplete(), $state);
        $review = new AiReview(
            $this->store,
            $root,
            $sendPolicy,
            (string) $this->config->raw()['model'],
            $complete,
            $this->hitsByPath($scanId, $root),
            is_array($state['first_request'] ?? null) ? $state['first_request'] : [],
            [],
            is_array($state['log_lines'] ?? null) ? $state['log_lines'] : []
        );
        $sent = $review->batch($scanId, $rel);
        $this->keepUsage($state);
        if (!empty($sent['error'])) {
            $this->writeState($state);
            return ['ok' => false, 'error' => (string) $sent['error'], 'code' => 'PROVIDER'];
        }
        $state['files_sent_after'] = (int) ($state['files_sent_after'] ?? 0) + 1;
        $this->writeState($state);
        $this->writeReport($state);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function keep(string $rel): array {
        $state = $this->state();
        $rel = ltrim(AiPaths::norm($rel), '/');
        $data = $this->store->readJson('keeps.json', ['paths' => []]);
        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        if (!in_array($rel, $paths, true)) {
            $paths[] = $rel;
        }
        $this->store->writeJson('keeps.json', ['paths' => $paths]);
        if (in_array((string) ($state['ai_status'] ?? ''), ['completed', 'error'], true)) {
            $this->writeReport($state);
        }
        return ['ok' => true];
    }

    public function report(): ?array {
        $state = $this->state();
        $scanId = (string) ($state['scan_id'] ?? '');
        if ($scanId === '' || !in_array((string) ($state['ai_status'] ?? ''), ['completed', 'error'], true)) {
            return null;
        }
        $file = 'report-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $scanId) . '.json';
        $data = $this->store->readJson($file, []);
        return $data === [] ? null : $data;
    }

    /**
     * @param array $state
     * @param callable $complete function(string $user, int $maxTokens, string $system = ''): array
     */
    private function runFollowUp(array &$state, callable $complete): void {
        $step = (int) ($state['followup'] ?? 0);
        $root = $this->siteRoot();
        $scanId = (string) $state['scan_id'];
        $policy = $this->policyFromState($state);
        $not = is_array($state['not_checked'] ?? null) ? $state['not_checked'] : [];
        if ($step === 0) {
            foreach (['db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php'] as $name) {
                $rel = 'wp-content/' . $name;
                $this->queueIfAllowed($scanId, $rel, $root, $policy, $not, 'drop-in', 'followup', $state);
            }
        } elseif ($step === 1) {
            foreach (['.htaccess', '.user.ini', 'php.ini'] as $name) {
                foreach ([$name, 'wp-admin/' . $name, 'wp-content/' . $name] as $rel) {
                    $this->queueIfAllowed($scanId, $rel, $root, $policy, $not, $name, 'followup', $state);
                }
            }
        } elseif ($step === 2) {
            $cron = function_exists('get_option') ? get_option('cron') : null;
            foreach (AiFollowUp::cronPhpPaths($cron, $root) as $rel) {
                $this->queueIfAllowed($scanId, $rel, $root, $policy, $not, 'cron', 'followup', $state);
            }
        } elseif ($step === 3) {
            $days = [];
            foreach ((new AiVerdictStore($this->store))->forScan($scanId) as $row) {
                $basis = (string) ($row['file_time_basis'] ?? '');
                $time = (string) ($row['file_time'] ?? '');
                if ($basis !== '' && $basis !== 'unknown' && $time !== '') {
                    $days[] = substr($time, 0, 10);
                }
            }
            $users = $this->loadAdmins();
            $matches = AiFollowUp::adminsOnDays($users, array_values(array_unique($days)));
            $state['admin_matches'] = $matches;
        } elseif ($step === 4) {
            $this->queueMalwareFolders($scanId, $root, $policy, $not, $state);
        } elseif ($step === 5) {
            $spread = $this->spreadOne($state, $complete, $root, $policy, $not);
            if (!empty($spread['error'])) {
                $sources = is_array($state['spread_sources'] ?? null) ? $state['spread_sources'] : [];
                $remaining = max(0, count($sources) - (int) ($state['spread_at'] ?? 0));
                $state['ai_status'] = 'error';
                $state['error'] = $this->followStop((string) ($spread['file'] ?? ''), (string) $spread['error'], $remaining);
                $state['not_checked'] = $not;
                return;
            }
            if (!empty($spread['continue'])) {
                $state['not_checked'] = $not;
                $queued = $this->queueCount($scanId);
                $judged = count((new AiVerdictStore($this->store))->forScan($scanId));
                if ($judged < $queued) {
                    $state['phase'] = 'review';
                    $state['total'] = $queued;
                }
                return;
            }
        } else {
            $state['not_checked'] = $not;
            $queued = $this->queueCount($scanId);
            $judged = count((new AiVerdictStore($this->store))->forScan($scanId));
            if ($judged < $queued) {
                $state['phase'] = 'review';
                $state['total'] = $queued;
                return;
            }
            $state['phase'] = 'report';
            $state['not_checked'] = $not;
            return;
        }
        $state['not_checked'] = $not;
        $state['followup'] = $step + 1;
        $queued = $this->queueCount($scanId);
        $judged = count((new AiVerdictStore($this->store))->forScan($scanId));
        if ($judged < $queued) {
            $state['phase'] = 'review';
            $state['total'] = $queued;
        }
    }

    private function withJudgedCounts(string $message, int $done, int $total): string {
        $waiting = max(0, $total - $done);
        if ($total < 1 || $waiting < 1) {
            return $message;
        }
        $left = $waiting === 1 ? '1 was not judged.' : $waiting . ' were not judged.';
        return $message . ' ' . $done . ' of ' . $total . ' files were judged. ' . $left;
    }

    private function followStop(string $file, string $reason, int $remaining): string {
        $text = 'The follow-up stopped';
        if ($file !== '') {
            $text .= ' while asking about ' . $file;
        }
        $text .= '. ' . $reason;
        if ($remaining > 0) {
            $text .= ' The same question was not asked for the other malware files.';
        }
        return $text;
    }

    /**
     * Ask about one original malware file, then queue the local files it names.
     * A file opened here is not asked about its own targets.
     *
     * @param array $state
     * @param callable $complete
     * @param array<int,array> $not
     * @return array{continue:bool,error?:string}
     */
    private function spreadOne(array &$state, callable $complete, string $root, AiPolicy $policy, array &$not): array {
        $scanId = (string) $state['scan_id'];
        if (!is_array($state['spread_sources'] ?? null)) {
            $state['spread_sources'] = $this->spreadSources($scanId);
        }
        $sources = $state['spread_sources'];
        $index = (int) ($state['spread_at'] ?? 0);
        if ($index >= count($sources)) {
            return ['continue' => false];
        }
        $from = (string) $sources[$index];
        $state['spread_at'] = $index + 1;
        if (!is_array($state['spread'] ?? null)) {
            $state['spread'] = [];
        }
        $queuedNow = false;
        $unit = $this->queueUnit($scanId, $from);
        $abs = AiPaths::abs($from, $root);
        $hits = $this->hitsByPath($scanId, $root);
        $excerpt = AiExcerpt::read($abs, $unit, $hits[$from] ?? []);
        if (!empty($excerpt['missing'])) {
            $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'unread', 'host' => ''];
        } else {
            $text = (string) $excerpt['text'];
            if (!$policy->fullPass()) {
                $text = AiSecretMask::apply($text)['text'];
            }
            $ask = "path: {$from}\n" . $text;
            $parsed = null;
            if (!AiChatClient::encodable($ask)) {
                $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'unencoded', 'host' => ''];
            } else {
                $reply = call_user_func($complete, $ask, 512, AiSpread::SYSTEM);
                if (!is_array($reply)) {
                    $reply = ['ok' => false, 'content' => '', 'http' => 0, 'error' => 'The provider did not return a completion.'];
                }
                if (empty($reply['ok']) && AiChatClient::isEmptyAnswer($reply)) {
                    $again = call_user_func($complete, $ask, 512, AiSpread::SYSTEM);
                    if (is_array($again)) {
                        $reply = $again;
                    }
                }
                if (empty($reply['ok'])) {
                    if ((string) ($reply['error'] ?? '') === AiChatClient::ENCODE_ERROR) {
                        $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'unencoded', 'host' => ''];
                    } elseif (AiChatClient::isEmptyAnswer($reply)) {
                        $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'empty', 'host' => ''];
                    } elseif (AiChatClient::isTransportTimeout($reply)) {
                        $waiting = (string) ($state['spread_timeout_file'] ?? '');
                        if ($waiting !== $from) {
                            $state['spread_at'] = $index;
                            $state['spread_timeout_file'] = $from;
                            return ['continue' => true];
                        }
                        $state['spread_timeout_file'] = '';
                        $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'timeout', 'host' => ''];
                    } else {
                        $state['spread_timeout_file'] = '';
                        return [
                            'continue' => false,
                            'error' => AiChatClient::failureSentence($reply),
                            'file' => $from,
                        ];
                    }
                } else {
                    if ((string) ($state['spread_timeout_file'] ?? '') === $from) {
                        $state['spread_timeout_file'] = '';
                    }
                    $parsed = AiSpread::parse((string) ($reply['content'] ?? ''));
                }
            }
            if (is_array($parsed) && empty($parsed['read'])) {
                $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'unparsed', 'host' => ''];
            } elseif (is_array($parsed)) {
                $accepted = AiSpread::accept($parsed['targets'], $parsed['hosts'], $text, $from, $root);
                if ($accepted['files'] === [] && $accepted['hosts'] === []) {
                    $state['spread'][] = ['from' => $from, 'path' => '', 'status' => 'none', 'host' => ''];
                }
                foreach ($accepted['files'] as $item) {
                    $rel = (string) $item['path'];
                    if (($item['status'] ?? '') === 'missing') {
                        $state['spread'][] = ['from' => $from, 'path' => $rel, 'status' => 'missing', 'host' => ''];
                        continue;
                    }
                    $refusal = AiFollowUp::refusal($rel, $policy);
                    if ($refusal !== null) {
                        $state['spread'][] = ['from' => $from, 'path' => $rel, 'status' => 'skipped', 'host' => ''];
                        $this->noteRefusal($scanId, $rel, $refusal, $not);
                        continue;
                    }
                    $nested = AiPaths::nestedSiteRel($rel, $root);
                    if ($nested !== '') {
                        $this->rememberNested($state, $nested);
                        $state['spread'][] = ['from' => $from, 'path' => $rel, 'status' => 'nested', 'host' => ''];
                        continue;
                    }
                    $this->queueIfAllowed($scanId, $rel, $root, $policy, $not, 'spread', 'spread', $state);
                    $state['spread'][] = ['from' => $from, 'path' => $rel, 'status' => 'queued', 'host' => ''];
                    $queuedNow = true;
                }
                foreach ($accepted['hosts'] as $item) {
                    $state['spread'][] = [
                        'from' => $from,
                        'path' => '',
                        'status' => 'remote',
                        'host' => (string) ($item['host'] ?? ''),
                    ];
                }
            }
        }
        if ((string) ($state['spread_timeout_file'] ?? '') === $from) {
            $state['spread_timeout_file'] = '';
        }
        return ['continue' => $queuedNow || ($index + 1) < count($sources)];
    }

    /** @return string[] */
    private function spreadSources(string $scanId): array {
        $reasons = [];
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') !== $scanId) {
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $reasons[$path] = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
        }
        $out = [];
        foreach ((new AiVerdictStore($this->store))->forScan($scanId) as $row) {
            if ((string) ($row['verdict'] ?? '') !== 'malware') {
                continue;
            }
            $path = ltrim(AiPaths::norm((string) ($row['path'] ?? '')), '/');
            if ($path === '' || isset($out[$path])) {
                continue;
            }
            $why = $reasons[$path] ?? [];
            if (in_array('folder', $why, true) || in_array('spread', $why, true)) {
                continue;
            }
            $out[$path] = true;
        }
        return array_keys($out);
    }

    /** @return array<string,mixed> */
    private function queueUnit(string $scanId, string $rel): array {
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId && ($row['path'] ?? '') === $rel && is_array($row)) {
                return $row;
            }
        }
        return ['path' => $rel, 'reasons' => [], 'signature_hit' => false];
    }

    /**
     * After a malware verdict, queue the other code files under that file's directory.
     * One pass. A file queued here does not expand its own folder again.
     *
     * @param array<int,array> $not
     */
    private function queueMalwareFolders(string $scanId, string $root, AiPolicy $policy, array &$not, array &$state): void {
        $rels = [];
        foreach ((new AiVerdictStore($this->store))->forScan($scanId) as $row) {
            if ((string) ($row['verdict'] ?? '') !== 'malware') {
                continue;
            }
            $path = ltrim(AiPaths::norm((string) ($row['path'] ?? '')), '/');
            if ($path !== '') {
                $rels[] = $path;
            }
        }
        usort($rels, static function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });
        $covered = [];
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
            foreach ($found['paths'] as $path) {
                $this->queueIfAllowed($scanId, $path, $root, $policy, $not, 'folder', 'folder', $state);
            }
            if (!empty($found['truncated'])) {
                $not[] = [
                    'path' => $dir === '' ? '.' : $dir,
                    'reason' => 'This folder has more files than one pass reviews. Some were left out.',
                ];
            }
        }
    }

    /**
     * One follow-up refusal. A secret already recorded as not sent stays one read-log row.
     *
     * @param array<int,array> $not
     */
    private function noteRefusal(string $scanId, string $rel, string $reason, array &$not): void {
        $seen = false;
        foreach ($not as $row) {
            if (is_array($row) && (string) ($row['path'] ?? '') === $rel) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            $not[] = ['path' => $rel, 'reason' => $reason];
        }
        if (strpos($reason, 'secret') === false) {
            return;
        }
        $log = new AiReadLog($this->store);
        if (!$log->has($scanId, $rel)) {
            $log->append($scanId, $rel, AiReadLog::NOT_SECRET);
        }
    }

    /**
     * @param array<int,array> $not
     */
    private function queueIfAllowed(string $scanId, string $rel, string $root, AiPolicy $policy, array &$not, string $why, string $reason, array &$state): void {
        if (!is_file(AiPaths::abs($rel, $root))) {
            return;
        }
        $nested = AiPaths::nestedSiteRel($rel, $root);
        if ($nested !== '') {
            $this->rememberNested($state, $nested);
            return;
        }
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId && ($row['path'] ?? '') === $rel) {
                return;
            }
        }
        $refusal = AiFollowUp::refusal($rel, $policy);
        if ($refusal !== null) {
            $this->noteRefusal($scanId, $rel, $refusal, $not);
            return;
        }
        $this->store->appendJsonl('queue.jsonl', [
            'scan_id' => $scanId,
            'subject' => 'file',
            'path' => $rel,
            'reasons' => [$reason],
            'signature_hit' => false,
            'truncated' => false,
            'masked_heavy' => false,
        ]);
    }

    /** @param array $state */
    private function writeReport(array $state): void {
        $scanId = (string) $state['scan_id'];
        $root = $this->siteRoot();
        $threats = $this->loadThreats($scanId) ?? [];
        $policy = $this->policyFromState($state);
        $keeps = $this->store->readJson('keeps.json', ['paths' => []]);
        $report = AiReport::build(
            $scanId,
            $threats,
            (new AiVerdictStore($this->store))->forScan($scanId),
            (new AiReadLog($this->store))->forScan($scanId, 0, 100000),
            is_array($keeps['paths'] ?? null) ? $keeps['paths'] : [],
            is_array($state['not_checked'] ?? null) ? $state['not_checked'] : [],
            $policy,
            (string) ($state['logs'] ?? 'skipped'),
            $root
        );
        $report['summary']['files_sent_after'] = (int) ($state['files_sent_after'] ?? 0);
        $report['summary']['logs_note'] = ($state['logs'] ?? '') === 'checked' && ($state['access_log'] ?? '') !== ''
            ? AiHostLogs::outcomeNote($this->matchedLogCount($state), $this->dangerousRequestCount($report))
            : (string) ($state['logs_note'] ?? '');
        $notes = is_array($state['package_notes'] ?? null) ? $state['package_notes'] : [];
        $checksums = (string) ($state['checksums'] ?? 'unread');
        $report['checksums'] = $checksums;
        $report['packages'] = $notes;
        $report['checksum_line'] = AiReport::checksumLine($checksums, $notes);
        $nestedSites = is_array($state['nested_sites'] ?? null) ? $state['nested_sites'] : [];
        $report['nested_sites'] = $nestedSites;
        $report['nested_lines'] = AiReport::nestedLines($nestedSites);
        $report['encode_lines'] = AiReport::encodeLines(is_array($report['not_sent'] ?? null) ? $report['not_sent'] : []);
        $verdictByPath = [];
        foreach ((new AiVerdictStore($this->store))->forScan($scanId) as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path !== '') {
                $verdictByPath[$path] = $row;
            }
        }
        $spreadRows = is_array($state['spread'] ?? null) ? $state['spread'] : [];
        $report['spread'] = AiReport::spreadLines($spreadRows, $verdictByPath);
        $report['admins'] = is_array($state['admin_matches'] ?? null) ? $state['admin_matches'] : [];
        $requestLines = [];
        foreach (is_array($state['log_lines'] ?? null) ? $state['log_lines'] : [] as $path => $rows) {
            if (is_array($rows) && $rows !== []) {
                $requestLines[$path] = $rows;
            }
        }
        $report['request_lines'] = $requestLines;
        $this->attachUsageMeta($state);
        if (is_array($state['usage'] ?? null)) {
            $report['usage'] = AiUsage::forReport($state['usage']);
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $scanId);
        $this->store->writeJson('report-' . $safe . '.json', $report);
    }

    /** @param array<string,string> $first */
    private function stampFirstRequest(string $scanId, array $first): void {
        if ($first === []) {
            return;
        }
        $rows = $this->store->readJsonl('verdicts.jsonl');
        $changed = false;
        foreach ($rows as &$row) {
            if (($row['scan_id'] ?? '') !== $scanId) {
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            if ($path !== '' && isset($first[$path]) && ($row['first_request_at'] ?? '') === '') {
                $row['first_request_at'] = $first[$path];
                $changed = true;
            }
        }
        unset($row);
        if (!$changed) {
            return;
        }
        $this->store->remove('verdicts.jsonl');
        foreach ($rows as $row) {
            $this->store->appendJsonl('verdicts.jsonl', $row);
        }
    }

    /** @return array<string,array<int,array>> */
    private function hitsByPath(string $scanId, string $root): array {
        $threats = $this->loadThreats($scanId) ?? [];
        $map = [];
        foreach ($threats as $row) {
            if (($row['source'] ?? '') !== 'file') {
                continue;
            }
            $rel = ltrim(AiPaths::rel((string) ($row['path'] ?? $row['file'] ?? ''), $root), '/');
            if ($rel === '') {
                continue;
            }
            $map[$rel][] = $row;
        }
        return $map;
    }

    private function queueCount(string $scanId): int {
        $n = 0;
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId) {
                $n++;
            }
        }
        return $n;
    }

    /** @return string[] */
    private function queuedPaths(string $scanId): array {
        $paths = [];
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId && ($row['path'] ?? '') !== '') {
                $paths[] = (string) $row['path'];
            }
        }
        return $paths;
    }

    /**
     * Read host logs once for paths that do not have lines yet.
     * A missing access log is not an error. The phase still finishes.
     *
     * @param array $state
     */
    private function attachLogsToState(array &$state): void {
        if (($state['logs'] ?? '') !== 'checked') {
            return;
        }
        $access = (string) ($state['access_log'] ?? '');
        if ($access === '') {
            return;
        }
        $scanId = (string) ($state['scan_id'] ?? '');
        $have = is_array($state['log_lines'] ?? null) ? $state['log_lines'] : [];
        $missing = [];
        foreach ($this->queuedPaths($scanId) as $path) {
            if (!array_key_exists($path, $have)) {
                $missing[] = $path;
            }
        }
        if ($missing === []) {
            return;
        }
        $policy = $this->policyFromState($state);
        $this->fillLogLines($state, $missing, $policy->includeQueryStrings(), ['access_log' => $access]);
        $this->stampFirstRequest($scanId, is_array($state['first_request'] ?? null) ? $state['first_request'] : []);
    }

    /**
     * @param array $state
     * @param string[] $paths
     * @param array<string,mixed> $located
     */
    private function fillLogLines(array &$state, array $paths, bool $includeQuery, array $located): void {
        $have = is_array($state['log_lines'] ?? null) ? $state['log_lines'] : [];
        $first = is_array($state['first_request'] ?? null) ? $state['first_request'] : [];
        $access = (string) ($located['access_log'] ?? '');
        if ($access !== '' && is_readable($access)) {
            $indexed = AiHostLogs::indexAccessLog($access, $paths, $includeQuery);
            foreach ($paths as $path) {
                $rows = $indexed[$path] ?? [];
                $lines = AiHostLogs::displayLines($rows);
                $when = AiHostLogs::firstRequest($rows);
                if ($when !== '' && !isset($first[$path])) {
                    $first[$path] = $when;
                }
                $have[$path] = $lines;
            }
        }
        foreach ($paths as $path) {
            if (!array_key_exists($path, $have)) {
                $have[$path] = [];
            }
        }
        $state['log_lines'] = $have;
        $state['first_request'] = $first;
    }

    /** @return array|null */
    private function loadThreats(string $scanId): ?array {
        $dir = defined('CLEAN_SWEEP_PROGRESS_DIR') ? CLEAN_SWEEP_PROGRESS_DIR : dirname(__DIR__, 2) . '/logs/';
        $file = rtrim($dir, '/') . '/scan_' . $scanId . '_threats.jsonl';
        if (!is_file($file)) {
            $alt = $this->store->path('threats-' . $scanId . '.jsonl');
            if (is_file($alt)) {
                $file = $alt;
            } else {
                // A finished signature pass with no hits never creates the file.
                return [];
            }
        }
        if (!is_readable($file)) {
            return null;
        }
        $out = [];
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode(trim($line), true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        fclose($fh);
        return $out;
    }

    /** @return array{status:string,counters:array} */
    private function readMalwareStatus(string $scanId): array {
        if (!class_exists('CleanSweep_Scanner')) {
            $scanner = defined('CLEAN_SWEEP_ROOT')
                ? CLEAN_SWEEP_ROOT . 'features/security/scan/Scanner.php'
                : '';
            if ($scanner !== '' && is_file($scanner)) {
                require_once $scanner;
            }
        }
        if (!class_exists('CleanSweep_Scanner')) {
            return ['status' => 'not_found', 'counters' => []];
        }
        $status = CleanSweep_Scanner::create($scanId)->status($scanId);
        return is_array($status) ? $status : ['status' => 'not_found', 'counters' => []];
    }

    /**
     * Queued files that had at least one kept request line.
     *
     * @param array $state
     */
    private function matchedLogCount(array $state): int {
        $lines = is_array($state['log_lines'] ?? null) ? $state['log_lines'] : [];
        $matched = 0;
        foreach ($lines as $rows) {
            if (is_array($rows) && $rows !== []) {
                $matched++;
            }
        }
        return $matched;
    }

    /** @param array $report */
    private function dangerousRequestCount(array $report): int {
        $rows = is_array($report['dangerous'] ?? null) ? $report['dangerous'] : [];
        $hits = 0;
        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['first_request_at'] ?? '')) !== '') {
                $hits++;
            }
        }
        return $hits;
    }

    /**
     * The plugin-log sentence, from the lines kept so far.
     *
     * @param array $state
     */
    private function logsNote(array $state): string {
        $lines = is_array($state['log_lines'] ?? null) ? $state['log_lines'] : [];
        return AiHostLogs::checkedNote(
            (string) ($state['access_log'] ?? ''),
            count($lines),
            $this->matchedLogCount($state)
        );
    }

    private function logHostLogs(string $note): void {
        if ($note !== '' && function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message('AI scan: ' . $note, 'info');
        }
    }

    /** @return array{host:string,access_log:?string,status:string} */
    private function locateLogs(): array {
        $root = $this->siteRoot();
        $home = getenv('HOME');
        return AiHostLogs::locate(
            $_SERVER,
            $root,
            is_string($home) ? $home : null,
            static function (string $path): bool {
                return is_readable($path);
            }
        );
    }

    /** @return array<int,array{login:string,registered:string}> */
    private function loadAdmins(): array {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return [];
        }
        $wpdb = $GLOBALS['wpdb'];
        $cap = $wpdb->prefix . 'capabilities';
        $sql = $wpdb->prepare(
            "SELECT u.user_login, u.user_registered FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
             WHERE m.meta_value LIKE %s",
            $cap,
            '%administrator%'
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'login' => (string) ($row['user_login'] ?? ''),
                'registered' => (string) ($row['user_registered'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Add each provider reply onto the scan totals.
     * Test-button calls do not come through here.
     *
     * @param callable $inner
     * @param array $state
     */
    private function countingComplete(callable $inner, array $state): callable {
        $model = (string) ($this->config->raw()['model'] ?? '');
        $current = is_array($state['usage'] ?? null) ? $state['usage'] : AiUsage::blank($model);
        if (($current['model'] ?? '') === '' && $model !== '') {
            $current['model'] = $model;
        }
        $this->usageScratch = $current;
        return function (string $user, int $maxTokens = 1024, string $system = '') use ($inner): array {
            $reply = self::invokeComplete($inner, $user, $maxTokens, $system);
            $base = is_array($this->usageScratch) ? $this->usageScratch : AiUsage::blank('');
            $phase = $system === AiSpread::SYSTEM ? 'spread' : 'review';
            $this->usageScratch = AiUsage::add($base, $reply, $phase);
            return $reply;
        };
    }

    /** @param array $state */
    private function keepUsage(array &$state): void {
        if (is_array($this->usageScratch)) {
            $state['usage'] = $this->usageScratch;
            $this->usageScratch = null;
        }
    }

    /** @param callable $inner */
    private static function invokeComplete(callable $inner, string $user, int $maxTokens, string $system): array {
        $argc = 3;
        try {
            if ($inner instanceof Closure) {
                $argc = (new ReflectionFunction($inner))->getNumberOfParameters();
            } elseif (is_string($inner) && function_exists($inner)) {
                $argc = (new ReflectionFunction($inner))->getNumberOfParameters();
            } elseif (is_array($inner) && count($inner) === 2) {
                $argc = (new ReflectionMethod($inner[0], (string) $inner[1]))->getNumberOfParameters();
            } elseif (is_object($inner) && method_exists($inner, '__invoke')) {
                $argc = (new ReflectionMethod($inner, '__invoke'))->getNumberOfParameters();
            }
        } catch (Throwable $e) {
            $argc = 3;
        }
        if ($argc >= 3) {
            $reply = $inner($user, $maxTokens, $system);
        } elseif ($argc === 2) {
            $reply = $inner($user, $maxTokens);
        } else {
            $reply = $inner($user);
        }
        if (!is_array($reply)) {
            return ['ok' => false, 'content' => '', 'http' => 0, 'error' => 'The provider did not return a completion.'];
        }
        return $reply;
    }

    /**
     * Copy a provider-reported context window and list price onto the scan.
     * A price already stored on this scan is left as it was.
     * Does nothing when this scan has no usage block.
     *
     * @param array $state
     */
    private function attachUsageMeta(array &$state): void {
        if (!is_array($state['usage'] ?? null)) {
            return;
        }
        $model = (string) ($this->config->raw()['model'] ?? '');
        if (($state['usage']['model'] ?? '') === '' && $model !== '') {
            $state['usage']['model'] = $model;
        }
        $id = (string) ($state['usage']['model'] ?? $model);
        if ((int) ($state['usage']['context_window'] ?? 0) < 1 && $id !== '') {
            $windows = $this->config->contextWindows();
            if (isset($windows[$id])) {
                $state['usage']['context_window'] = (int) $windows[$id];
            }
        }
        if ($id !== '' && (!isset($state['usage']['price']) || !is_array($state['usage']['price']))) {
            $price = $this->config->priceFor($id);
            if ($price !== null) {
                $state['usage']['price'] = $price;
            }
        }
    }

    /**
     * One model-list read when the window or the list price is still unknown.
     * A failure leaves that fact unknown and does not fail the scan.
     *
     * @param array $state
     */
    private function fetchWindow(array &$state): void {
        $this->attachUsageMeta($state);
        if (!is_array($state['usage'] ?? null)) {
            return;
        }
        $needWindow = (int) ($state['usage']['context_window'] ?? 0) < 1 && empty($state['window_checked']);
        $needPrice = empty($state['usage']['price']) && empty($state['price_checked']);
        if (!$needWindow && !$needPrice) {
            return;
        }
        $state['window_checked'] = true;
        $state['price_checked'] = true;
        $raw = $this->config->raw();
        if ($raw['key'] === '' || $this->config->baseUrl() === '') {
            return;
        }
        try {
            $client = new AiChatClient($this->config->baseUrl(), (string) $raw['key'], AiChatClient::timeoutForHost());
            $listed = $client->listModels();
        } catch (Throwable $e) {
            return;
        }
        if (!empty($listed['windows']) && is_array($listed['windows'])) {
            $this->config->rememberWindows($listed['windows']);
        }
        if (!empty($listed['prices']) && is_array($listed['prices'])) {
            $this->config->rememberPrices($listed['prices']);
        }
        $this->attachUsageMeta($state);
    }

    /** @return callable */
    private function defaultComplete(): callable {
        $raw = $this->config->raw();
        $client = new AiChatClient($this->config->baseUrl(), (string) $raw['key'], AiChatClient::timeoutForHost());
        $model = (string) $raw['model'];
        return static function (string $user, int $maxTokens, string $system = '') use ($client, $model) {
            if ($system === '') {
                $system = AiPrompt::SYSTEM;
            }
            return $client->complete($model, $system, $user, $maxTokens);
        };
    }

    /**
     * @param array<int,array> $prior
     * @param array<int,array> $added
     * @return array<int,array{path:string,reason:string}>
     */
    private function mergeNested(array $prior, array $added): array {
        $by = [];
        foreach (array_merge($prior, $added) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim(AiPaths::norm((string) ($row['path'] ?? '')), '/');
            if ($path === '' || isset($by[$path])) {
                continue;
            }
            $reason = trim((string) ($row['reason'] ?? ''));
            $by[$path] = [
                'path' => $path,
                'reason' => $reason !== '' ? $reason : AiPaths::NESTED_REASON,
            ];
        }
        ksort($by);
        return array_values($by);
    }

    /** @param array $state */
    private function rememberNested(array &$state, string $rel): void {
        $rel = trim(AiPaths::norm($rel), '/');
        if ($rel === '') {
            return;
        }
        $state['nested_sites'] = $this->mergeNested(
            is_array($state['nested_sites'] ?? null) ? $state['nested_sites'] : [],
            [['path' => $rel, 'reason' => AiPaths::NESTED_REASON]]
        );
    }

    private function policyFromState(array $state): AiPolicy {
        $snap = is_array($state['policy'] ?? null) ? $state['policy'] : [];
        $list = new AiExcludeList($this->store, $this->siteRoot());
        if (isset($snap['prefixes']) && is_array($snap['prefixes'])) {
            $list->usePrefixes($snap['prefixes']);
        }
        return AiPolicy::fromSnapshot($snap, $list);
    }

    private function siteRoot(): string {
        if ($this->rootOverride !== null) {
            return $this->rootOverride;
        }
        return AiPaths::siteRoot();
    }

    private function state(): array {
        return $this->store->readJson('state.json', [
            'scan_id' => '',
            'phase' => 'idle',
            'ai_status' => 'idle',
            'done' => 0,
            'total' => 0,
            'error' => '',
            'policy' => [],
            'logs' => '',
            'files_sent_after' => 0,
        ]);
    }

    private function writeState(array $state): void {
        $this->store->writeJson('state.json', $state);
    }
}
