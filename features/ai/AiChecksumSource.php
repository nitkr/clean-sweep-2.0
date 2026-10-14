<?php
/**
 * Read the package checksum file the signature pass already wrote.
 * Does not load the scanner checksum class.
 */
final class AiChecksumSource {

    /**
     * The file next to the AI store: backups/package_checksums_latest.json
     * when the store is backups/ai.
     */
    public static function pathForStore(string $aiDir): string {
        $aiDir = rtrim(str_replace('\\', '/', $aiDir), '/');
        return dirname($aiDir) . '/package_checksums_latest.json';
    }

    /**
     * Same path as CleanSweep_PackageChecksums::latest_status_path().
     */
    public static function path(): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? rtrim((string) CLEAN_SWEEP_ROOT, '/') . '/backups/'
            : dirname(__DIR__, 2) . '/backups/';
        return $dir . 'package_checksums_latest.json';
    }

    /**
     * Missing or malformed JSON is unread. An empty package list is a successful read.
     *
     * @return array{status:string,packages:array<string,mixed>}
     */
    public static function load(?string $file = null): array {
        $file = $file ?? self::path();
        if (!is_readable($file)) {
            return ['status' => 'unread', 'packages' => []];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['packages'] ?? null)) {
            return ['status' => 'unread', 'packages' => []];
        }
        return ['status' => 'read', 'packages' => $data['packages']];
    }
}
