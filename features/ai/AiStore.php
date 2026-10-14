<?php
/**
 * JSON and JSONL files under backups/ai.
 */
final class AiStore {

    private string $dir;

    public function __construct(?string $dir = null) {
        if ($dir === null) {
            $root = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/';
            $dir = rtrim(str_replace('\\', '/', $root), '/') . '/backups/ai';
        }
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
    }

    public function dir(): string {
        return $this->dir;
    }

    public function ensure(): void {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        $cache = $this->dir . '/cache';
        if (!is_dir($cache)) {
            @mkdir($cache, 0700, true);
        }
        $this->ensureHtaccess();
    }

    public function path(string $name): string {
        return $this->dir . '/' . ltrim($name, '/');
    }

    public function readJson(string $name, array $default): array {
        $file = $this->path($name);
        if (!is_readable($file)) {
            return $default;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : $default;
    }

    public function writeJson(string $name, array $data): void {
        $this->ensure();
        $file = $this->path($name);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $file);
        @chmod($file, 0600);
    }

    public function appendJsonl(string $name, array $row): void {
        $this->ensure();
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $file = $this->path($name);
        @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
    }

    /** @return array<int,array> */
    public function readJsonl(string $name): array {
        $file = $this->path($name);
        if (!is_readable($file)) {
            return [];
        }
        $out = [];
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        fclose($fh);
        return $out;
    }

    public function remove(string $name): void {
        $file = $this->path($name);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function removeReports(): void {
        foreach (glob($this->dir . '/report-*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function ensureHtaccess(): void {
        $backups = dirname($this->dir);
        $ht = $backups . '/.htaccess';
        if (is_file($ht)) {
            return;
        }
        if (!is_dir($backups)) {
            @mkdir($backups, 0700, true);
        }
        @file_put_contents($ht, "Require all denied\n");
    }
}
