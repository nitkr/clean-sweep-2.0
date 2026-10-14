<?php
/**
 * Fixed follow-up checks. A malware file's own targets are accepted in AiSpread.
 */
final class AiFollowUp {

    /**
     * PHP paths named by a cron array. The option body is not returned.
     *
     * @param mixed $cron
     * @return string[] site-relative paths
     */
    public static function cronPhpPaths($cron, string $root): array {
        $root = rtrim(AiPaths::norm($root), '/') . '/';
        $found = [];
        if (!is_array($cron)) {
            return [];
        }
        $walk = function ($node) use (&$walk, &$found, $root) {
            if (is_string($node)) {
                $rel = self::phpInside($node, $root);
                if ($rel !== null) {
                    $found[$rel] = true;
                }
                return;
            }
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($cron);
        return array_keys($found);
    }

    /**
     * @param array<int,array{login:string,registered:string}> $users
     * @param string[] $days Y-m-d
     * @return array<int,array{login:string,registered:string}>
     */
    public static function adminsOnDays(array $users, array $days): array {
        $want = array_fill_keys($days, true);
        $out = [];
        foreach ($users as $user) {
            $day = substr((string) ($user['registered'] ?? ''), 0, 10);
            $login = (string) ($user['login'] ?? '');
            if ($login === '' || $day === '' || !isset($want[$day])) {
                continue;
            }
            $out[] = ['login' => $login, 'registered' => $day];
        }
        return $out;
    }

    /**
     * Reason the follow-up must not read this path, or null when it may be queued.
     */
    public static function refusal(string $rel, AiPolicy $policy): ?string {
        $rel = ltrim(AiPaths::norm($rel), '/');
        if ($policy->excludes()->matches($rel)) {
            return 'On your skip list.';
        }
        if (AiSecretPaths::isSecret($rel) && !$policy->fullPass()) {
            return 'Would read a secret file. Full pass is off.';
        }
        return null;
    }

    /**
     * Other code files under the directory that holds $rel.
     * Vendor, node_modules, and the clean-sweep toolkit are not entered.
     * The walk stops after the code limit or the entry limit.
     *
     * @return array{paths:string[],truncated:bool,not_code:int}
     */
    public static function codeBeside(string $rel, string $root, int $codeLimit = 80, int $entryLimit = 400): array {
        $empty = ['paths' => [], 'truncated' => false, 'not_code' => 0];
        $rel = ltrim(AiPaths::norm($rel), '/');
        if ($rel === '' || self::isToolkit($rel)) {
            return $empty;
        }
        $dirRel = dirname($rel);
        if ($dirRel === '.' || $dirRel === '') {
            $dirRel = '';
        }
        if (self::isToolkit($dirRel) || AiPaths::nestedSiteRel($rel, $root) !== '') {
            return $empty;
        }
        $root = rtrim(AiPaths::norm($root), '/') . '/';
        $abs = $dirRel === '' ? $root : AiPaths::abs($dirRel, $root);
        if (!is_dir($abs)) {
            return $empty;
        }
        $paths = [];
        $notCode = 0;
        $truncated = false;
        $seen = 0;
        $stack = [$abs];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $names = scandir($dir) ?: [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $seen++;
                if ($seen > $entryLimit) {
                    $truncated = true;
                    break 2;
                }
                $child = $dir . '/' . $name;
                $childRel = ltrim(AiPaths::rel($child, $root), '/');
                if ($childRel === '' || !AiPaths::insideRoot($childRel, $root) || self::isToolkit($childRel)) {
                    continue;
                }
                if (is_dir($child)) {
                    if ($name[0] === '.' || self::skipDir($name) || AiPaths::isWordPressTree($child)) {
                        continue;
                    }
                    $stack[] = $child;
                    continue;
                }
                if (!is_file($child) || $childRel === $rel) {
                    continue;
                }
                $size = (int) @filesize($child);
                if (!self::isCode($childRel, $size) && !self::isConfigBeside($childRel)) {
                    $notCode++;
                    continue;
                }
                if (count($paths) >= $codeLimit) {
                    $truncated = true;
                    break 2;
                }
                $paths[] = $childRel;
            }
        }
        sort($paths);
        return ['paths' => $paths, 'truncated' => $truncated, 'not_code' => $notCode];
    }

    private static function isCode(string $rel, int $size): bool {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $code = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc', 'js', 'mjs'];
        if (!in_array($ext, $code, true)) {
            return false;
        }
        $base = strtolower(basename($rel));
        if (substr($base, -7) === '.min.js' && $size > 200 * 1024) {
            return false;
        }
        return true;
    }

    private static function isConfigBeside(string $rel): bool {
        $base = strtolower(basename($rel));
        return $base === '.htaccess' || $base === '.user.ini' || $base === 'php.ini';
    }

    private static function skipDir(string $name): bool {
        $name = strtolower($name);
        return $name === 'vendor' || $name === 'node_modules' || $name === 'clean-sweep';
    }

    private static function isToolkit(string $rel): bool {
        return (bool) preg_match('#(^|/)clean-sweep(/|$)#i', ltrim(AiPaths::norm($rel), '/'));
    }

    private static function phpInside(string $value, string $root): ?string {
        if (!preg_match('/[A-Za-z0-9_\/.\\\\-]+\.php\b/', $value, $m)) {
            return null;
        }
        $path = AiPaths::norm($m[0]);
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }
        $rel = ltrim(AiPaths::rel($path, $root), '/');
        if ($rel === '' || substr($rel, -4) !== '.php') {
            return null;
        }
        if (!AiPaths::insideRoot($rel, $root)) {
            return null;
        }
        return $rel;
    }
}
