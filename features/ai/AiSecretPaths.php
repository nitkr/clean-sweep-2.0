<?php
/**
 * Secret files are classified by path. The file body is not read.
 */
final class AiSecretPaths {

    private const BACKUP_PREFIXES = [
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/wpvividbackups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',
        'wp-content/uploads/backupbuddy_backups',
        'wp-content/uploads/pb_backupbuddy',
        'wp-content/uploads/backwpup',
        'wp-content/uploads/wp-migrate-db',
        'wp-content/uploads/wp-staging',
    ];

    public static function isSecret(string $rel): bool {
        $rel = ltrim(AiPaths::norm($rel), '/');
        $base = strtolower(basename($rel));
        if (self::isWpConfigName($base) || self::isEnvName($base)) {
            return true;
        }
        if (in_array($base, ['.htpasswd', 'auth.json', '.npmrc', '.netrc'], true)) {
            return true;
        }
        if ($base === 'id_rsa' || preg_match('/\.(pem|key|p12|pfx)$/', $base)) {
            return true;
        }
        if (preg_match('/(service-account|credentials).*\.json$/', $base)) {
            return true;
        }
        if (preg_match('/\.(sql|sql\.gz|sqlite)$/', $base)) {
            return true;
        }
        if (in_array($base, ['debug.log', 'error_log'], true)) {
            return true;
        }
        foreach (self::BACKUP_PREFIXES as $prefix) {
            if ($rel === $prefix || strncasecmp($rel, $prefix . '/', strlen($prefix) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function isBackupContainer(string $rel): bool {
        $rel = ltrim(AiPaths::norm($rel), '/');
        foreach (self::BACKUP_PREFIXES as $prefix) {
            if ($rel === $prefix || strncasecmp($rel, $prefix . '/', strlen($prefix) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function isWpConfigName(string $base): bool {
        if ($base === 'wp-config.php') {
            return true;
        }
        return (bool) preg_match('/^wp-config\.php\.(bak|old|save|orig|txt|swp)$/', $base)
            || (bool) preg_match('/^wp-config\.php~$/', $base)
            || $base === 'wp-config.bak.php';
    }

    private static function isEnvName(string $base): bool {
        return $base === '.env' || strncmp($base, '.env.', 5) === 0;
    }
}
