<?php
/**
 * Probe PHP/filesystem features the visit engine needs.
 *
 * Shared hosts often disable functions or return false from stat().
 * Detection never requires mtime/ctime; those only rank writers.
 */
final class CleanSweep_VisitCapabilities {

    private static ?self $instance = null;

    /** @var array<string,bool> */
    private array $usable = [];

    public bool $hash_file = false;
    public bool $sha1_file = false;
    public bool $md5_file = false;
    public bool $hash = false;
    public bool $filemtime = false;
    public bool $filectime = false;
    public bool $filesize = false;
    public bool $fopen = false;
    public string $hash_algo = 'none';
    public bool $ctime_is_inode = false;
    public string $open_basedir = '';

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->probe();
        }
        return self::$instance;
    }

    public static function function_usable(string $name): bool {
        if ($name === '' || !function_exists($name)) {
            return false;
        }
        $disabled = self::disabled_list();
        return !in_array(strtolower($name), $disabled, true);
    }

    /** @return string[] lowercase names */
    public static function disabled_list(): array {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $raw = (string) ini_get('disable_functions');
        $suhosin = (string) ini_get('suhosin.executor.func.blacklist');
        $parts = array_merge(
            explode(',', $raw),
            explode(',', $suhosin)
        );
        $list = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') {
                $list[] = $p;
            }
        }
        return $list;
    }

    private function probe(): void {
        $this->hash_file = self::function_usable('hash_file');
        $this->sha1_file = self::function_usable('sha1_file');
        $this->md5_file = self::function_usable('md5_file');
        $this->hash = self::function_usable('hash');
        $this->filemtime = self::function_usable('filemtime');
        $this->filectime = self::function_usable('filectime');
        $this->filesize = self::function_usable('filesize');
        $this->fopen = self::function_usable('fopen') && self::function_usable('fread');
        $this->open_basedir = (string) ini_get('open_basedir');
        $this->ctime_is_inode = $this->filectime
            && (!defined('PHP_OS_FAMILY') || PHP_OS_FAMILY !== 'Windows');

        $probe = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'config.php'
            : dirname(__DIR__, 2) . '/config.php';

        if ($this->filemtime && is_readable($probe) && @filemtime($probe) === false) {
            $this->filemtime = false;
        }
        if ($this->filectime && is_readable($probe) && @filectime($probe) === false) {
            $this->filectime = false;
            $this->ctime_is_inode = false;
        }

        if ($this->hash_file && is_readable($probe) && @hash_file('sha256', $probe)) {
            $this->hash_algo = 'sha256';
        } elseif ($this->sha1_file && is_readable($probe) && @sha1_file($probe)) {
            $this->hash_algo = 'sha1';
        } elseif ($this->md5_file && is_readable($probe) && @md5_file($probe)) {
            $this->hash_algo = 'md5';
        } elseif ($this->hash && is_readable($probe)) {
            $this->hash_algo = 'sha256-memory';
        } else {
            $this->hash_algo = 'none';
        }
    }

    /**
     * Hash a file. Returns null if unreadable or no hash function works.
     */
    public function hash_path(string $path): ?string {
        if ($path === '' || !is_readable($path) || is_dir($path)) {
            return null;
        }
        if ($this->hash_algo === 'sha256' && $this->hash_file) {
            $h = @hash_file('sha256', $path);
            return is_string($h) && $h !== '' ? $h : null;
        }
        if ($this->hash_algo === 'sha1' && $this->sha1_file) {
            $h = @sha1_file($path);
            return is_string($h) && $h !== '' ? $h : null;
        }
        if ($this->hash_algo === 'md5' && $this->md5_file) {
            $h = @md5_file($path);
            return is_string($h) && $h !== '' ? $h : null;
        }
        if ($this->hash_algo === 'sha256-memory' && $this->hash) {
            $size = $this->size($path);
            if ($size === null || $size > 1048576) {
                return null;
            }
            $data = @file_get_contents($path);
            if (!is_string($data)) {
                return null;
            }
            return hash('sha256', $data);
        }
        return null;
    }

    public function mtime(string $path): ?int {
        if (!$this->filemtime || !is_readable($path)) {
            return null;
        }
        $t = @filemtime($path);
        return ($t === false) ? null : (int) $t;
    }

    public function ctime(string $path): ?int {
        if (!$this->filectime || !is_readable($path)) {
            return null;
        }
        $t = @filectime($path);
        return ($t === false) ? null : (int) $t;
    }

    public function size(string $path): ?int {
        if (!$this->filesize || !is_readable($path)) {
            return null;
        }
        $s = @filesize($path);
        return ($s === false) ? null : (int) $s;
    }

    /** One-line summary for diagnostics. */
    public function summary(): string {
        $ctime = !$this->filectime
            ? 'ctime off'
            : ($this->ctime_is_inode ? 'ctime ok' : 'ctime unreliable');
        $mtime = $this->filemtime ? 'mtime ok' : 'mtime off';
        return $mtime . ' · ' . $ctime . ' · hash ' . $this->hash_algo;
    }

    public function to_array(): array {
        return [
            'hash_algo' => $this->hash_algo,
            'filemtime' => $this->filemtime,
            'filectime' => $this->filectime,
            'ctime_is_inode' => $this->ctime_is_inode,
            'filesize' => $this->filesize,
            'fopen' => $this->fopen,
            'open_basedir' => $this->open_basedir !== '',
            'summary' => $this->summary(),
        ];
    }
}
