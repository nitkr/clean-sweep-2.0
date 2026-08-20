<?php
/**
 * Zip-slip-safe unzip. Writes via getFromIndex + file_put_contents after a prefix check.
 * Do not call ZipArchive::extractTo().
 * Walk / unsafe-name / DEST_TOOLKIT live here so utils.php callers inherit them.
 */

if (!function_exists('clean_sweep_upload_normalize_abs')) {
    function clean_sweep_upload_normalize_abs(string $path): string {
        $path = str_replace('\\', '/', $path);
        $is_abs = ($path !== '' && $path[0] === '/');
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (!empty($parts)) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $seg;
        }
        if ($is_abs) {
            return '/' . implode('/', $parts);
        }
        return implode('/', $parts);
    }
}

if (!function_exists('clean_sweep_upload_path_equals')) {
    function clean_sweep_upload_path_equals(string $a, string $b): bool {
        return rtrim(str_replace('\\', '/', $a), '/') === rtrim(str_replace('\\', '/', $b), '/');
    }
}

if (!function_exists('clean_sweep_upload_path_is_inside')) {
    function clean_sweep_upload_path_is_inside(string $inner, string $outer): bool {
        $inner = rtrim(str_replace('\\', '/', $inner), '/');
        $outer = rtrim(str_replace('\\', '/', $outer), '/');
        if ($inner === '' || $outer === '') {
            return false;
        }
        return strpos($inner, $outer . '/') === 0;
    }
}

if (!function_exists('clean_sweep_upload_path_equals_or_inside')) {
    function clean_sweep_upload_path_equals_or_inside(string $path, string $root): bool {
        return clean_sweep_upload_path_equals($path, $root) || clean_sweep_upload_path_is_inside($path, $root);
    }
}

if (!function_exists('clean_sweep_upload_toolkit_abs')) {
    function clean_sweep_upload_toolkit_abs($tk = null): string {
        if (is_string($tk) && $tk !== '') {
            $real = is_dir($tk) ? realpath($tk) : false;
            return $real ? str_replace('\\', '/', $real) : rtrim(str_replace('\\', '/', $tk), '/');
        }
        if (!defined('CLEAN_SWEEP_ROOT') || !CLEAN_SWEEP_ROOT) {
            return '';
        }
        $real = realpath(CLEAN_SWEEP_ROOT);
        return $real ? str_replace('\\', '/', $real) : '';
    }
}

if (!function_exists('clean_sweep_upload_dest_toolkit_collision')) {
    /**
     * DEST_TOOLKIT three-rule test. Does not reject dest merely because $tk lives under it.
     *
     * @return array{hit:bool,rule?:int,message?:string}
     */
    function clean_sweep_upload_dest_toolkit_collision(string $destAbs, string $slug = '', $tk = null): array {
        $tk_abs = clean_sweep_upload_toolkit_abs($tk);
        if ($tk_abs === '') {
            return ['hit' => false];
        }

        $dest_n = clean_sweep_upload_normalize_abs($destAbs);
        if (is_dir($destAbs)) {
            $real = realpath($destAbs);
            if ($real) {
                $dest_n = str_replace('\\', '/', $real);
            }
        }

        if (clean_sweep_upload_path_equals_or_inside($dest_n, $tk_abs)) {
            return [
                'hit' => true,
                'rule' => 1,
                'message' => 'Destination is inside the Clean Sweep toolkit',
            ];
        }

        $slug = trim(str_replace('\\', '/', $slug), '/');
        if ($slug === '') {
            return ['hit' => false];
        }

        $dest_slug = rtrim($dest_n, '/') . '/' . $slug;
        if (is_dir($dest_slug)) {
            $real_slug = realpath($dest_slug);
            if ($real_slug) {
                $dest_slug = str_replace('\\', '/', $real_slug);
            }
        } else {
            $dest_slug = clean_sweep_upload_normalize_abs($dest_slug);
        }

        if (clean_sweep_upload_path_equals_or_inside($dest_slug, $tk_abs)) {
            return [
                'hit' => true,
                'rule' => 2,
                'message' => 'Extract would write into the Clean Sweep toolkit',
            ];
        }

        if (clean_sweep_upload_path_equals_or_inside($tk_abs, $dest_slug)) {
            return [
                'hit' => true,
                'rule' => 3,
                'message' => 'Extract would replace the Clean Sweep toolkit directory',
            ];
        }

        return ['hit' => false];
    }
}

if (!function_exists('clean_sweep_upload_zip_entry_is_unsafe')) {
    function clean_sweep_upload_zip_entry_is_unsafe(string $name): bool {
        if ($name === '' || strpos($name, "\0") !== false) {
            return true;
        }
        $norm = str_replace('\\', '/', $name);
        if ($norm !== '' && $norm[0] === '/') {
            return true;
        }
        if (preg_match('/^[A-Za-z]:/', $norm)) {
            return true;
        }
        if (strpos($norm, '..') !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('clean_sweep_upload_entry_target')) {
    function clean_sweep_upload_entry_target(string $destAbs, string $name): string {
        $name = str_replace('\\', '/', $name);
        return clean_sweep_upload_normalize_abs(rtrim($destAbs, '/') . '/' . $name);
    }
}

if (!function_exists('clean_sweep_upload_entry_is_symlink')) {
    function clean_sweep_upload_entry_is_symlink($zip, int $index): bool {
        $opsys = 0;
        $extAttr = 0;
        if (!is_object($zip) || !method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }
        if (!$zip->getExternalAttributesIndex($index, $opsys, $extAttr)) {
            return false;
        }
        $unix = defined('ZipArchive::OPSYS_UNIX') ? ZipArchive::OPSYS_UNIX : 3;
        return ((int) $opsys === (int) $unix) && ((($extAttr >> 16) & 0120000) === 0120000);
    }
}

if (!function_exists('clean_sweep_upload_walk_zip_entries')) {
    /**
     * Walk every zip entry before any write.
     *
     * @return array{ok:bool,code?:string,message?:string,slug?:string}
     */
    function clean_sweep_upload_walk_zip_entries(string $zip_path, string $destAbs, $tk = null): array {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'code' => 'EXTRACT_ERROR', 'message' => 'ZipArchive is not available'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['ok' => false, 'code' => 'EXTRACT_ERROR', 'message' => 'Could not open ZIP'];
        }

        $dest_n = clean_sweep_upload_normalize_abs($destAbs);
        if (is_dir($destAbs)) {
            $real = realpath($destAbs);
            if ($real) {
                $dest_n = str_replace('\\', '/', $real);
            }
        }
        $tk_abs = clean_sweep_upload_toolkit_abs($tk);
        $tops = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                $zip->close();
                return ['ok' => false, 'code' => 'UNSAFE_ZIP', 'message' => 'ZIP contains an unreadable entry'];
            }
            if (clean_sweep_upload_zip_entry_is_unsafe($name)) {
                $zip->close();
                return ['ok' => false, 'code' => 'UNSAFE_ZIP', 'message' => 'ZIP contains an unsafe entry'];
            }
            $target = clean_sweep_upload_entry_target($dest_n, $name);
            if (!clean_sweep_upload_path_equals_or_inside($target, $dest_n)) {
                $zip->close();
                return ['ok' => false, 'code' => 'UNSAFE_ZIP', 'message' => 'ZIP entry would extract outside the destination'];
            }
            if ($tk_abs !== '' && clean_sweep_upload_path_equals_or_inside($target, $tk_abs)) {
                $zip->close();
                return ['ok' => false, 'code' => 'DEST_TOOLKIT', 'message' => 'ZIP entry would write into the Clean Sweep toolkit'];
            }
            $trimmed = trim(str_replace('\\', '/', $name), '/');
            if ($trimmed !== '') {
                $parts = explode('/', $trimmed);
                $tops[$parts[0]] = true;
            }
        }
        $zip->close();

        $slug = '';
        if (count($tops) === 1) {
            $slug = (string) array_key_first($tops);
            $hit = clean_sweep_upload_dest_toolkit_collision($dest_n, $slug, $tk_abs !== '' ? $tk_abs : null);
            if (!empty($hit['hit'])) {
                return [
                    'ok' => false,
                    'code' => 'DEST_TOOLKIT',
                    'message' => $hit['message'] ?? 'Destination collides with the Clean Sweep toolkit',
                ];
            }
        }

        return ['ok' => true, 'slug' => $slug];
    }
}

/**
 * Join $rel onto $dest_real. Returns null if the name escapes (., .. already rejected).
 */
function clean_sweep_safe_unzip_join(string $dest_real, string $rel) {
    $rel = str_replace('\\', '/', $rel);
    $rel = trim($rel, '/');
    if ($rel === '') {
        return $dest_real;
    }
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            return null;
        }
        $parts[] = $seg;
    }
    return $dest_real . '/' . implode('/', $parts);
}

/**
 * Extract a ZIP into $dest_abs without trusting entry names.
 *
 * @param string      $zip_path
 * @param string      $dest_abs
 * @param string|null $tk         Toolkit abs for DEST_TOOLKIT (optional)
 * @param string|null $site_root  When set, dest must stay under this root after mkdir
 * @return array{success:bool,code?:string,message?:string,extracted?:string[],skipped?:string[],errors?:string[],count?:int,dest?:string}
 */
function clean_sweep_safe_unzip($zip_path, $dest_abs, $tk = null, $site_root = null) {
    $dest_abs = rtrim(str_replace('\\', '/', (string) $dest_abs), '/');
    if ($dest_abs === '') {
        return [
            'success' => false,
            'code' => 'MISSING_DESTINATION',
            'message' => 'Destination is required',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    $skip_syms = [];
    if (function_exists('clean_sweep_inspect_uploaded_zip')) {
        $inspect = clean_sweep_inspect_uploaded_zip((string) $zip_path);
        if (empty($inspect['ok'])) {
            return [
                'success' => false,
                'code' => $inspect['code'] ?? 'UNSAFE_ZIP',
                'message' => $inspect['message'] ?? 'Unsafe ZIP',
                'extracted' => [],
                'skipped' => [],
                'errors' => [],
            ];
        }
        foreach ($inspect['symlink_names'] ?? [] as $sym) {
            $skip_syms[str_replace('\\', '/', (string) $sym)] = true;
        }
    }

    $walk = clean_sweep_upload_walk_zip_entries($zip_path, $dest_abs, $tk);
    if (empty($walk['ok'])) {
        return [
            'success' => false,
            'code' => $walk['code'] ?? 'UNSAFE_ZIP',
            'message' => $walk['message'] ?? 'Unsafe ZIP',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    if (!is_dir($dest_abs) && !@mkdir($dest_abs, 0755, true)) {
        return [
            'success' => false,
            'code' => 'DEST_NOT_WRITABLE',
            'message' => 'Could not create extract directory',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    if (is_string($site_root) && $site_root !== '' && function_exists('clean_sweep_upload_assert_under_site')) {
        $under = clean_sweep_upload_assert_under_site($dest_abs, $site_root);
        if (empty($under['ok'])) {
            return [
                'success' => false,
                'code' => $under['code'] ?? 'DEST_OUTSIDE_SITE',
                'message' => $under['message'] ?? 'Destination is outside the site',
                'extracted' => [],
                'skipped' => [],
                'errors' => [],
            ];
        }
        $dest_abs = $under['abs'];
    }

    if (!is_writable($dest_abs)) {
        return [
            'success' => false,
            'code' => 'DEST_NOT_WRITABLE',
            'message' => 'Extract directory is not writable',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    if (!class_exists('ZipArchive')) {
        return [
            'success' => false,
            'code' => 'EXTRACT_ERROR',
            'message' => 'ZipArchive is not available',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return [
            'success' => false,
            'code' => 'EXTRACT_ERROR',
            'message' => 'Could not open ZIP',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    $dest_real = realpath($dest_abs);
    if ($dest_real === false) {
        $zip->close();
        return [
            'success' => false,
            'code' => 'DEST_NOT_WRITABLE',
            'message' => 'Destination could not be resolved',
            'extracted' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }
    $dest_real = rtrim(str_replace('\\', '/', $dest_real), '/');

    $extracted = [];
    $skipped = [];
    $errors = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $norm = str_replace('\\', '/', $name);

        if (clean_sweep_upload_zip_entry_is_unsafe($name)) {
            $zip->close();
            return [
                'success' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'ZIP contains an unsafe entry',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $is_sym = isset($skip_syms[$norm]);
        if (!$is_sym) {
            $is_sym = clean_sweep_upload_entry_is_symlink($zip, $i);
        }
        if ($is_sym) {
            $skipped[] = $norm;
            continue;
        }

        $joined = clean_sweep_safe_unzip_join($dest_real, rtrim($norm, '/'));
        if ($joined === null || !clean_sweep_safe_unzip_under($joined, $dest_real)) {
            $zip->close();
            return [
                'success' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'ZIP entry would extract outside the destination',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $tk_abs = clean_sweep_upload_toolkit_abs($tk);
        if ($tk_abs !== '' && clean_sweep_upload_path_equals_or_inside($joined, $tk_abs)) {
            $zip->close();
            return [
                'success' => false,
                'code' => 'DEST_TOOLKIT',
                'message' => 'ZIP entry would write into the Clean Sweep toolkit',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $parent = dirname($joined);
        if (!clean_sweep_safe_unzip_under($parent, $dest_real)) {
            $zip->close();
            return [
                'success' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'ZIP entry would extract outside the destination',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $parent_gate = clean_sweep_safe_unzip_existing_target($parent, $dest_real);
        if (empty($parent_gate['ok'])) {
            $zip->close();
            return [
                'success' => false,
                'code' => $parent_gate['code'] ?? 'UNSAFE_ZIP',
                'message' => $parent_gate['message'] ?? 'Destination contains an outbound symlink',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
            $errors[] = $norm;
            continue;
        }
        $parent_real = realpath($parent);
        if ($parent_real === false || !clean_sweep_safe_unzip_under($parent_real, $dest_real)) {
            $zip->close();
            return [
                'success' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'ZIP entry would extract outside the destination',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        if (substr($norm, -1) === '/') {
            $dir_gate = clean_sweep_safe_unzip_existing_target($joined, $dest_real);
            if (empty($dir_gate['ok'])) {
                $zip->close();
                return [
                    'success' => false,
                    'code' => $dir_gate['code'] ?? 'UNSAFE_ZIP',
                    'message' => $dir_gate['message'] ?? 'Destination contains an outbound symlink',
                    'extracted' => $extracted,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ];
            }
            if (!is_dir($joined) && !@mkdir($joined, 0755, true)) {
                $errors[] = $norm;
            }
            continue;
        }

        $target_gate = clean_sweep_safe_unzip_existing_target($joined, $dest_real);
        if (empty($target_gate['ok'])) {
            $zip->close();
            return [
                'success' => false,
                'code' => $target_gate['code'] ?? 'UNSAFE_ZIP',
                'message' => $target_gate['message'] ?? 'Destination contains an outbound symlink',
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $bytes = $zip->getFromIndex($i);
        if ($bytes === false) {
            $errors[] = $norm;
            continue;
        }
        if (file_put_contents($joined, $bytes) === false) {
            $errors[] = $norm;
            continue;
        }
        $extracted[] = $norm;
    }
    $zip->close();

    $ok = empty($errors);
    return [
        'success' => $ok,
        'code' => $ok ? '' : 'EXTRACT_ERROR',
        'message' => $ok ? ('Extracted ' . count($extracted) . ' entries') : 'Extract failed',
        'extracted' => $extracted,
        'skipped' => $skipped,
        'errors' => $errors,
        'extracted_files' => $extracted,
        'count' => count($extracted),
        'dest' => $dest_abs,
    ];
}

function clean_sweep_safe_unzip_under($path, $root): bool {
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    if ($path === '' || $root === '') {
        return false;
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

/**
 * Do not let file_put_contents / mkdir follow a pre-existing outbound dest symlink.
 *
 * @return array{ok:bool,code?:string,message?:string}
 */
function clean_sweep_safe_unzip_existing_target(string $target, string $dest_real): array {
    if (is_link($target)) {
        $link_real = realpath($target);
        $under = ($link_real !== false && clean_sweep_safe_unzip_under($link_real, $dest_real));
        if (!$under) {
            @unlink($target);
            return [
                'ok' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'Destination contains an outbound symlink',
            ];
        }
        @unlink($target);
        return ['ok' => true];
    }
    if (file_exists($target)) {
        $existing_real = realpath($target);
        if ($existing_real !== false && !clean_sweep_safe_unzip_under($existing_real, $dest_real)) {
            return [
                'ok' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'Extract target is outside the destination',
            ];
        }
    }
    return ['ok' => true];
}
