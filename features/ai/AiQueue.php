<?php
/**
 * Decide which files are sent. Does not call the provider.
 */
final class AiQueue {

    private const CODE_EXT = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc', 'js', 'mjs',
    ];

    private const OFFICIAL_ROOT = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php',
        'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
        'wp-trackback.php', 'xmlrpc.php',
    ];

    private const DROPINS = [
        'db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php',
        '.htaccess', '.user.ini', 'php.ini',
    ];

    /** A file this much newer than the package entry was written after the package. */
    private const NEWER_SECONDS = 172800;

    /** wp-content children handled by their own walks, or too broad to treat as odd. */
    private const LOOSE_SKIP = [
        'plugins', 'themes', 'uploads', 'mu-plugins', 'languages', 'upgrade',
    ];

    private string $root;
    private array $packages;
    private array $threats;
    private AiPolicy $policy;
    private AiStore $store;
    private AiReadLog $log;
    private string $scanId;
    /** @var callable|null */
    private $imageSniff;
    /** @var array<int,array{slug:string,name:string,type:string,decision:string,queued:int}> */
    private array $packageNotes = [];
    /** @var array<string,string> */
    private array $nestedSites = [];

    /**
     * @param array<string,array> $packages
     * @param array<int,array> $threats
     * @param callable|null $imageSniff function(string $abs): bool
     */
    public function __construct(
        string $root,
        array $packages,
        array $threats,
        AiPolicy $policy,
        string $scanId,
        ?AiStore $store = null,
        $imageSniff = null
    ) {
        $this->root = rtrim(AiPaths::norm($root), '/') . '/';
        $this->packages = $packages;
        $this->threats = $threats;
        $this->policy = $policy;
        $this->scanId = $scanId;
        $this->store = $store ?: new AiStore();
        $this->log = new AiReadLog($this->store);
        $this->imageSniff = $imageSniff;
    }

    /**
     * Build until $deadline (microtime). Returns done when every stage finished.
     *
     * @param array{stage:string,index:int} $cursor
     * @return array{done:bool,added:int}
     */
    public function slice(array &$cursor, float $deadline): array {
        if (!isset($cursor['stage']) || $cursor['stage'] === '') {
            $cursor = ['stage' => 'hits', 'index' => 0, 'file' => 0, 'queued' => 0];
        }
        $added = 0;
        $known = $this->knownPaths();
        if ($cursor['stage'] === 'hits') {
            $added += $this->addSignatureHits($known);
            $this->advance($cursor, 'hits');
        }
        if ($cursor['stage'] === 'images') {
            if (microtime(true) > $deadline && $added > 0) {
                return ['done' => false, 'added' => $added];
            }
            $added += $this->addFlaggedImages($known);
            $this->advance($cursor, 'images');
        }
        if ($cursor['stage'] === 'risky') {
            if (microtime(true) > $deadline && $added > 0) {
                return ['done' => false, 'added' => $added];
            }
            $added += $this->walkRisky($known);
            $this->advance($cursor, 'risky');
        }
        if ($cursor['stage'] === 'core') {
            if (microtime(true) > $deadline && $added > 0) {
                return ['done' => false, 'added' => $added];
            }
            $added += $this->addCoreIntegrity($known);
            $this->advance($cursor, 'core');
        }
        if ($cursor['stage'] === 'packages') {
            $dirs = [];
            foreach ($this->packages as $pkg) {
                if (is_array($pkg)) {
                    $dirs[] = $pkg;
                }
            }
            $i = (int) ($cursor['index'] ?? 0);
            $n = count($dirs);
            $skip = (int) ($cursor['file'] ?? 0);
            $already = (int) ($cursor['queued'] ?? 0);
            for (; $i < $n; $i++) {
                if (microtime(true) > $deadline && ($added > 0 || $skip > 0 || $already > 0)) {
                    $cursor['index'] = $i;
                    $cursor['file'] = $skip;
                    $cursor['queued'] = $already;
                    return ['done' => false, 'added' => $added];
                }
                $step = $this->walkPackage($dirs[$i], $known, $deadline, $skip, $already);
                $added += $step['added'] - $already;
                if (!$step['done']) {
                    $cursor['stage'] = 'packages';
                    $cursor['index'] = $i;
                    $cursor['file'] = $step['skip'];
                    $cursor['queued'] = $step['added'];
                    return ['done' => false, 'added' => $added];
                }
                $skip = 0;
                $already = 0;
            }
            $this->advance($cursor, 'packages');
        }
        return ['done' => true, 'added' => $added];
    }

    /** @param array{stage:string,index:int,file?:int,queued?:int} $cursor */
    private function advance(array &$cursor, string $stage): void {
        $order = ['hits', 'images', 'risky', 'core', 'packages', 'done'];
        $at = array_search($stage, $order, true);
        $next = ($at === false) ? 'done' : ($order[$at + 1] ?? 'done');
        $cursor = ['stage' => $next, 'index' => 0, 'file' => 0, 'queued' => 0];
    }

    /** @return array<string,bool> */
    private function knownPaths(): array {
        $set = [];
        foreach ($this->store->readJsonl('queue.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $this->scanId && ($row['path'] ?? '') !== '') {
                $set[(string) $row['path']] = true;
            }
        }
        foreach ($this->store->readJsonl('read-log.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $this->scanId && ($row['path'] ?? '') !== '') {
                $set[(string) $row['path']] = true;
            }
        }
        return $set;
    }

    /**
     * Decisions for packages this slice finished walking.
     *
     * @return array<int,array{slug:string,name:string,type:string,decision:string,queued:int}>
     */
    public function packageNotes(): array {
        return $this->packageNotes;
    }

    /**
     * Nested WordPress directories this slice refused to enter.
     *
     * @return array<int,array{path:string,reason:string}>
     */
    public function nestedSites(): array {
        $rows = [];
        foreach ($this->nestedSites as $path => $reason) {
            $rows[] = ['path' => $path, 'reason' => $reason];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });
        return $rows;
    }

    /**
     * Queue candidates inside one package. A matching package contributes only
     * files that are not on its official list. An unverified package contributes
     * its entry file, config files, and files written after that entry.
     * A wordpress.org bulk divergence that is already annotated as a free/Pro
     * collision or a commercial build uses that same small set, plus PHP files
     * the checksum pass listed as absent from the free map.
     *
     * @param array<string,bool> $known
     * @return array{added:int,done:bool,skip:int}
     */
    private function walkPackage(array $pkg, array &$known, float $deadline, int $skip, int $already): array {
        $finished = static function (int $added) {
            return ['added' => $added, 'done' => true, 'skip' => 0];
        };
        $status = (string) ($pkg['status'] ?? '');
        $outcome = (string) ($pkg['outcome'] ?? '');
        $dir = (string) ($pkg['dir'] ?? '');
        if ($dir === '') {
            $this->notePackage($pkg, 'skipped', 0);
            return $finished(0);
        }
        $relDir = AiPaths::rel($dir, $this->root);
        if ($relDir === '' || AiSecretPaths::isBackupContainer($relDir) || $this->isToolkit($relDir)) {
            $this->notePackage($pkg, 'skipped', 0);
            return $finished(0);
        }
        if ($status === 'skipped' || $outcome === 'skipped') {
            $this->notePackage($pkg, 'skipped', 0);
            return $finished(0);
        }
        $ok = [];
        foreach (($pkg['official_ok'] ?? []) as $rel) {
            if (is_string($rel) && $rel !== '') {
                $ok[ltrim(AiPaths::norm($rel), '/')] = true;
            }
        }
        $match = ($status === 'match' || $status === 'verified' || $outcome === 'verified');
        $outliers = ($status === 'modified' && $outcome === 'outliers');
        $divergent = ($outcome === 'divergent' || $outcome === 'baseline_divergent');
        $unavailable = ($status === 'unavailable');
        $tight = $outcome === 'divergent' && $this->knownNonOrgBuild($pkg);
        if ($match && $ok === []) {
            $this->notePackage($pkg, 'matched', 0);
            return $finished(0);
        }
        if (!$match && !$outliers && !$divergent && !$unavailable) {
            $this->notePackage($pkg, 'skipped', 0);
            return $finished(0);
        }
        $abs = AiPaths::abs($relDir, $this->root);
        if (!is_dir($abs)) {
            $this->notePackage($pkg, 'missing', 0);
            return $finished(0);
        }
        if ($this->leaveNested($abs)) {
            $this->notePackage($pkg, 'skipped', 0);
            return $finished(0);
        }
        $container = strtolower(rtrim($relDir, '/'));
        if ($container === 'wp-content/plugins' || $container === 'wp-content/themes') {
            $added = $this->queueNamedEntries($pkg, $relDir, $known, $unavailable ? 'package_unavailable' : 'package_outlier');
            $this->notePackage($pkg, 'single', $added);
            return $finished($added);
        }
        $entryRels = $this->entryRels($pkg, $relDir);
        $entryMtime = $this->entryMtime($entryRels);
        if ($entryMtime === null && $unavailable) {
            $sole = $this->soleRootCode($abs, $relDir);
            if ($sole !== null) {
                $entryRels[] = $sole;
                $entryMtime = $this->entryMtime([$sole]);
            }
        }
        $reason = $match ? 'package_extra' : ($unavailable ? 'package_unavailable' : ($divergent ? 'package_divergent' : 'package_outlier'));
        $decision = $match ? 'matched' : ($unavailable ? 'unavailable' : ($divergent ? 'divergent' : 'outliers'));
        $added = $already;
        $prefix = rtrim($relDir, '/');
        $unmapped = $tight ? $this->unmappedPhp($pkg) : [];
        $index = 0;
        $iterator = $this->iterateFiles($abs);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($index < $skip) {
                $index++;
                continue;
            }
            if (microtime(true) > $deadline) {
                return ['added' => $added, 'done' => false, 'skip' => $index];
            }
            $index++;
            $full = AiPaths::norm($file->getPathname());
            $rel = AiPaths::rel($full, $this->root);
            if ($rel === '' || $this->isToolkit($rel)) {
                continue;
            }
            $code = $this->isCode($rel, (int) $file->getSize());
            $config = $this->isConfig($rel);
            if (!$code && !$config) {
                continue;
            }
            $inside = ltrim(substr($rel, strlen($prefix) + 1), '/');
            $official = isset($ok[$inside]);
            if ($match) {
                if ($official) {
                    continue;
                }
            } elseif ($unavailable || $tight) {
                $isEntry = in_array($rel, $entryRels, true);
                $planted = $code && $this->newerThanEntry($full, $entryMtime);
                $extraPhp = $tight && $code && isset($unmapped[$inside]);
                if (!$isEntry && !$config && !$planted && !$extraPhp) {
                    continue;
                }
            } elseif ($official) {
                continue;
            }
            if ($this->consider($rel, [$reason], false, $known)) {
                $added++;
            }
        }
        $this->notePackage($pkg, $decision, $added);
        return $finished($added);
    }

    /**
     * @param string[] $rels
     */
    private function entryMtime(array $rels): ?int {
        foreach ($rels as $rel) {
            $file = AiPaths::abs($rel, $this->root);
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false) {
                return (int) $mtime;
            }
        }
        return null;
    }

    private function newerThanEntry(string $abs, ?int $entryMtime): bool {
        if ($entryMtime === null || $entryMtime <= 0) {
            return false;
        }
        $mtime = @filemtime($abs);
        if ($mtime === false) {
            return false;
        }
        return (int) $mtime >= $entryMtime + self::NEWER_SECONDS;
    }

    /**
     * A free/Pro collision note or a commercial header is already on the checksum row.
     * A rewritten free plugin that still looks like the wordpress.org build has neither.
     */
    private function knownNonOrgBuild(array $pkg): bool {
        $notes = $pkg['annotations'] ?? null;
        if (!is_array($notes) || $notes === []) {
            return false;
        }
        $file = dirname(__DIR__) . '/security/scan/PackageAnnotations.php';
        if (!class_exists('CleanSweep_PackageAnnotations', false) && is_readable($file)) {
            require_once $file;
        }
        if (!class_exists('CleanSweep_PackageAnnotations', false)) {
            return false;
        }
        return CleanSweep_PackageAnnotations::known_non_org_build($notes);
    }

    /**
     * PHP paths the checksum pass found outside the free package map.
     *
     * @return array<string,bool>
     */
    private function unmappedPhp(array $pkg): array {
        $set = [];
        foreach (($pkg['unmapped_php'] ?? []) as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $set[ltrim(str_replace('\\', '/', $rel), '/')] = true;
        }
        return $set;
    }

    /** @return string[] */
    private function entryRels(array $pkg, string $relDir): array {
        $rels = [];
        $base = rtrim($relDir, '/');
        $single = (string) ($pkg['single_file'] ?? '');
        if ($single !== '') {
            $rels[] = $base . '/' . basename($single);
        }
        $slug = (string) ($pkg['slug'] ?? '');
        if ($slug !== '') {
            $rels[] = $base . '/' . $slug . '.php';
        }
        if ((string) ($pkg['type'] ?? '') === 'theme') {
            $rels[] = $base . '/functions.php';
        }
        return array_values(array_unique($rels));
    }

    private function soleRootCode(string $abs, string $relDir): ?string {
        $found = [];
        foreach (scandir($abs) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $file = $abs . '/' . $name;
            if (!is_file($file)) {
                continue;
            }
            $rel = rtrim($relDir, '/') . '/' . $name;
            if (!$this->isCode($rel, (int) @filesize($file))) {
                continue;
            }
            $found[] = $rel;
            if (count($found) > 1) {
                return null;
            }
        }
        return $found[0] ?? null;
    }

    /** @param array<string,bool> $known */
    private function queueNamedEntries(array $pkg, string $relDir, array &$known, string $reason): int {
        $added = 0;
        foreach ($this->entryRels($pkg, $relDir) as $rel) {
            $file = AiPaths::abs($rel, $this->root);
            if (!is_file($file) || !$this->isCode($rel, (int) @filesize($file))) {
                continue;
            }
            if ($this->consider($rel, [$reason], false, $known)) {
                $added++;
            }
        }
        return $added;
    }

    private function notePackage(array $pkg, string $decision, int $queued): void {
        $slug = trim((string) ($pkg['slug'] ?? ''));
        if ($slug === '') {
            $dir = rtrim(str_replace('\\', '/', (string) ($pkg['dir'] ?? '')), '/');
            $slug = $dir === '' ? '' : basename($dir);
        }
        $name = trim((string) ($pkg['name'] ?? ''));
        if ($name === '') {
            $name = $slug;
        }
        $this->packageNotes[] = [
            'slug' => $slug,
            'name' => $name,
            'type' => (string) ($pkg['type'] ?? ''),
            'decision' => $decision,
            'queued' => $queued,
        ];
    }

    /** @param array<string,bool> $known */
    private function walkRisky(array &$known): int {
        $added = $this->walkRootExtras($known);
        $content = $this->root . 'wp-content/';
        foreach (self::DROPINS as $name) {
            foreach ([$content . $name, $this->root . $name, $this->root . 'wp-admin/' . $name] as $file) {
                if (is_file($file) && $this->consider(AiPaths::rel($file, $this->root), ['dropin'], false, $known)) {
                    $added++;
                }
            }
        }
        foreach (glob($content . '*.php') ?: [] as $file) {
            if ($this->consider(AiPaths::rel($file, $this->root), ['wp_content_php'], false, $known)) {
                $added++;
            }
        }
        $added += $this->walkBesidePackages($known);
        $added += $this->walkLooseContent($known);
        $mu = $content . 'mu-plugins';
        if (is_dir($mu) && !$this->leaveNested($mu)) {
            $iterator = $this->iterateFiles($mu);
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = AiPaths::rel($file->getPathname(), $this->root);
                if (!$this->isCode($rel, (int) $file->getSize())) {
                    continue;
                }
                if ($this->consider($rel, ['mu_plugin'], false, $known)) {
                    $added++;
                }
            }
        }
        $uploads = $content . 'uploads';
        if (is_dir($uploads) && !$this->leaveNested($uploads)) {
            $iterator = $this->iterateFiles($uploads);
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = AiPaths::rel($file->getPathname(), $this->root);
                if (AiSecretPaths::isBackupContainer($rel) || $this->skipDirPart($rel)) {
                    continue;
                }
                if (!$this->isCode($rel, (int) $file->getSize())) {
                    continue;
                }
                if ($this->consider($rel, ['uploads'], false, $known)) {
                    $added++;
                }
            }
        }
        return $added;
    }

    /**
     * PHP and JavaScript placed directly in wp-content/plugins or wp-content/themes.
     * Package directories are not entered here.
     *
     * @param array<string,bool> $known
     */
    private function walkBesidePackages(array &$known): int {
        $added = 0;
        foreach (['wp-content/plugins', 'wp-content/themes'] as $relDir) {
            $abs = $this->root . $relDir;
            if (!is_dir($abs)) {
                continue;
            }
            $entries = scandir($abs) ?: [];
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $file = $abs . '/' . $name;
                if (!is_file($file)) {
                    continue;
                }
                $rel = $relDir . '/' . $name;
                if (!$this->isCode($rel, (int) @filesize($file))) {
                    continue;
                }
                if ($this->consider($rel, ['beside_package'], false, $known)) {
                    $added++;
                }
            }
        }
        return $added;
    }

    /** @param array<string,bool> $known */
    private function addCoreIntegrity(array &$known): int {
        $added = 0;
        foreach ($this->threats as $row) {
            $code = (string) ($row['signature_id'] ?? $row['pattern'] ?? '');
            if (!in_array($code, ['checksum_mismatch', 'unexpected_core_php', 'unexpected_core_js'], true)) {
                continue;
            }
            $rel = $this->threatRel($row);
            if ($rel === '' || !$this->isUnderCore($rel)) {
                continue;
            }
            if ($this->consider($rel, ['core_integrity'], false, $known)) {
                $added++;
            }
        }
        return $added;
    }

    /** @param array<string,bool> $known */
    private function addSignatureHits(array &$known): int {
        $added = 0;
        foreach ($this->threats as $row) {
            if (($row['source'] ?? '') !== 'file') {
                continue;
            }
            $rel = $this->threatRel($row);
            if ($rel === '') {
                continue;
            }
            if (isset($known[$rel])) {
                $this->markSignature($rel);
                continue;
            }
            if ($this->consider($rel, ['signature_hit'], true, $known)) {
                $added++;
            }
        }
        return $added;
    }

    /** @param array<string,bool> $known */
    private function addFlaggedImages(array &$known): int {
        $added = 0;
        foreach ($this->threats as $row) {
            if (($row['source'] ?? '') !== 'file') {
                continue;
            }
            $rel = $this->threatRel($row);
            if ($rel === '' || isset($known[$rel]) || $this->isCode($rel, 0)) {
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg'], true)) {
                continue;
            }
            $abs = AiPaths::abs($rel, $this->root);
            if (!is_file($abs) || !$this->imageLooksExecutable($abs)) {
                continue;
            }
            if ($this->consider($rel, ['signature_hit', 'disguise'], true, $known)) {
                $added++;
            }
        }
        return $added;
    }

    private function imageLooksExecutable(string $abs): bool {
        if ($this->imageSniff !== null) {
            return (bool) call_user_func($this->imageSniff, $abs);
        }
        if (!class_exists('CleanSweep_PhpDisguiseSniff') || !class_exists('CleanSweep_ScanProfile')) {
            return false;
        }
        try {
            $profile = CleanSweep_ScanProfile::create('standard');
            $info = CleanSweep_PhpDisguiseSniff::inspect($abs, $profile);
            return !empty($info['php_opener']) || !empty($info['mismatch']);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function markSignature(string $rel): void {
        $rows = $this->store->readJsonl('queue.jsonl');
        $changed = false;
        foreach ($rows as &$row) {
            if (($row['scan_id'] ?? '') !== $this->scanId || ($row['path'] ?? '') !== $rel) {
                continue;
            }
            $reasons = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
            if (!in_array('signature_hit', $reasons, true)) {
                $reasons[] = 'signature_hit';
                $row['reasons'] = $reasons;
                $row['signature_hit'] = true;
                $changed = true;
            }
        }
        unset($row);
        if (!$changed) {
            return;
        }
        $this->store->remove('queue.jsonl');
        foreach ($rows as $row) {
            $this->store->appendJsonl('queue.jsonl', $row);
        }
    }

    /**
     * @param string[] $reasons
     * @param array<string,bool> $known
     */
    private function consider(string $rel, array $reasons, bool $signature, array &$known): bool {
        $rel = ltrim(AiPaths::norm($rel), '/');
        if ($rel === '' || $this->isToolkit($rel) || isset($known[$rel])) {
            return false;
        }
        if ($this->leaveNested(AiPaths::abs($rel, $this->root))) {
            return false;
        }
        $known[$rel] = true;
        $abs = AiPaths::abs($rel, $this->root);
        if (!is_file($abs)) {
            $this->log->append($this->scanId, $rel, AiReadLog::NOT_MISSING);
            return false;
        }
        if ($this->policy->excludes()->matches($rel)) {
            $this->log->append($this->scanId, $rel, AiReadLog::NOT_SKIP);
            return false;
        }
        if (AiSecretPaths::isSecret($rel) && !$this->policy->fullPass()) {
            $this->log->append($this->scanId, $rel, AiReadLog::NOT_SECRET);
            return false;
        }
        $this->store->appendJsonl('queue.jsonl', [
            'scan_id' => $this->scanId,
            'subject' => 'file',
            'path' => $rel,
            'reasons' => $reasons,
            'signature_hit' => $signature || in_array('signature_hit', $reasons, true),
            'truncated' => false,
            'masked_heavy' => false,
        ]);
        return true;
    }

    private function threatRel(array $row): string {
        $path = (string) ($row['path'] ?? '');
        if ($path === '') {
            $path = (string) ($row['file'] ?? '');
        }
        return ltrim(AiPaths::rel($path, $this->root), '/');
    }

    private function isUnderCore(string $rel): bool {
        return strncasecmp($rel, 'wp-admin/', 9) === 0 || strncasecmp($rel, 'wp-includes/', 12) === 0;
    }

    private function isConfig(string $rel): bool {
        $base = strtolower(basename($rel));
        return in_array($base, ['.htaccess', '.user.ini', 'php.ini'], true);
    }

    /**
     * Code and config under wp-content that is not a plugin, theme, upload, or language pack.
     *
     * @param array<string,bool> $known
     */
    private function walkLooseContent(array &$known): int {
        $added = 0;
        $content = $this->root . 'wp-content';
        if (!is_dir($content)) {
            return 0;
        }
        foreach (scandir($content) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            if (in_array(strtolower($name), self::LOOSE_SKIP, true)) {
                continue;
            }
            $abs = $content . '/' . $name;
            $rel = 'wp-content/' . $name;
            if (AiSecretPaths::isBackupContainer($rel) || $this->isToolkit($rel)) {
                continue;
            }
            if (is_file($abs)) {
                if (($this->isConfig($rel) || $this->isCode($rel, (int) @filesize($abs)))
                    && $this->consider($rel, ['loose_content'], false, $known)) {
                    $added++;
                }
                continue;
            }
            if (!is_dir($abs) || $this->leaveNested($abs)) {
                continue;
            }
            $iterator = $this->iterateFiles($abs);
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $child = AiPaths::rel($file->getPathname(), $this->root);
                if ($child === '' || $this->isToolkit($child) || AiSecretPaths::isBackupContainer($child)) {
                    continue;
                }
                $code = $this->isCode($child, (int) $file->getSize());
                if (!$code && !$this->isConfig($child)) {
                    continue;
                }
                if ($this->consider($child, ['loose_content'], false, $known)) {
                    $added++;
                }
            }
        }
        return $added;
    }

    private function isCode(string $rel, int $size): bool {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, self::CODE_EXT, true)) {
            return false;
        }
        $base = strtolower(basename($rel));
        if (substr($base, -7) === '.min.js' && $size > 200 * 1024) {
            return false;
        }
        return true;
    }

    private function skipDirPart(string $rel): bool {
        return (bool) preg_match('#(^|/)(node_modules|vendor)(/|$)#', $rel);
    }

    /**
     * PHP and JavaScript in the site root, and in extra root folders.
     * Official checksummed core names are omitted. The clean-sweep toolkit is omitted.
     *
     * @param array<string,bool> $known
     */
    private function walkRootExtras(array &$known): int {
        $added = 0;
        $skipDirs = ['wp-admin', 'wp-includes', 'wp-content', 'clean-sweep'];
        $entries = scandir($this->root) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $this->root . $name;
            $lower = strtolower($name);
            if (is_dir($abs)) {
                if ($name[0] === '.' || in_array($lower, $skipDirs, true) || $this->leaveNested($abs)) {
                    continue;
                }
                $added += $this->walkCodeTree($abs, $known, 'extra_root');
                continue;
            }
            if (!is_file($abs) || in_array($lower, self::OFFICIAL_ROOT, true)) {
                continue;
            }
            $rel = AiPaths::rel($abs, $this->root);
            $size = (int) @filesize($abs);
            if (!$this->isCode($rel, $size)) {
                continue;
            }
            if ($this->consider($rel, ['extra_root'], false, $known)) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * @param array<string,bool> $known
     */
    private function walkCodeTree(string $absDir, array &$known, string $reason): int {
        $added = 0;
        if (!is_dir($absDir)) {
            return 0;
        }
        if ($this->leaveNested($absDir)) {
            return 0;
        }
        $iterator = $this->iterateFiles($absDir);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = AiPaths::rel($file->getPathname(), $this->root);
            if ($this->isToolkit($rel)) {
                continue;
            }
            if (!$this->isCode($rel, (int) $file->getSize())) {
                continue;
            }
            if ($this->consider($rel, [$reason], false, $known)) {
                $added++;
            }
        }
        return $added;
    }

    private function isToolkit(string $rel): bool {
        return (bool) preg_match('#(^|/)clean-sweep(/|$)#i', ltrim(AiPaths::norm($rel), '/'));
    }

    /**
     * Record a nested WordPress directory and tell the caller not to enter it.
     * A file path records the site that contains it.
     */
    private function leaveNested(string $abs): bool {
        $rel = trim(AiPaths::rel($abs, $this->root), '/');
        if ($rel === '') {
            return false;
        }
        $site = AiPaths::nestedSiteRel($rel, $this->root);
        if ($site === '') {
            return false;
        }
        $this->nestedSites[$site] = AiPaths::NESTED_REASON;
        return true;
    }

    private function iterateFiles(string $absDir): RecursiveIteratorIterator {
        $dir = new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dir, function ($current): bool {
            if ($current->isDir() && $this->leaveNested($current->getPathname())) {
                return false;
            }
            return true;
        });
        return new RecursiveIteratorIterator($filter);
    }
}
