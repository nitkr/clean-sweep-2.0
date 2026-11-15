<?php
/**
 * Cheap PHP-in-media disguise checks.
 *
 * Does not add image, audio, video, font, or PDF extensions to the
 * signature pack. Standard peeks the head for a PHP opener; Deep also
 * peeks the tail (appended polyglot) and flags extension/magic mismatch
 * when the file is not that format and is not PHP.
 */

class CleanSweep_PhpDisguiseSniff {

    const HEAD_BYTES = 4096;
    const TAIL_BYTES = 8192;

    /** @var array<string, array{php_opener:bool,mismatch:bool}> */
    private static $cache = [];

    /**
     * Whether this media file should be queued / signature-scanned.
     */
    public static function is_scan_candidate($path, CleanSweep_ScanProfile $profile) {
        $info = self::inspect($path, $profile);
        if (!empty($info['php_opener'])) {
            return true;
        }
        return !empty($info['mismatch']) && $profile->should_deep_inspect_media();
    }

    /**
     * @return array{php_opener:bool,mismatch:bool}
     */
    public static function inspect($path, CleanSweep_ScanProfile $profile) {
        $path = (string) $path;
        $empty = ['php_opener' => false, 'mismatch' => false];
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $empty;
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return $empty;
        }

        $mtime = (int) @filemtime($path);
        $deep = $profile->should_deep_inspect_media() ? 'd' : 's';
        $key = $path . '|' . $size . '|' . $mtime . '|' . $deep;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $head = self::read_window($path, 0, min(self::HEAD_BYTES, $size));
        $php_opener = self::has_php_opener($head);

        if (!$php_opener && $profile->should_deep_inspect_media() && $size > self::HEAD_BYTES) {
            $tail_len = min(self::TAIL_BYTES, $size);
            $php_opener = self::has_php_opener(self::read_window($path, $size - $tail_len, $tail_len));
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mismatch = false;
        if (!$php_opener && $profile->should_deep_inspect_media()) {
            $mismatch = self::magic_mismatch($ext, $head);
        }

        $info = ['php_opener' => $php_opener, 'mismatch' => $mismatch];
        if (count(self::$cache) > 4000) {
            self::$cache = [];
        }
        self::$cache[$key] = $info;
        return $info;
    }

    public static function reset_cache() {
        self::$cache = [];
    }

    /**
     * Peek the first 4KB for a PHP opener. Do not sniff on tokens like eval(.
     */
    public static function has_php_opener($bytes) {
        $bytes = (string) $bytes;
        if ($bytes === '') {
            return false;
        }
        if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) {
            $bytes = substr($bytes, 3);
        }
        return (bool) preg_match('/<\?(?:php|=)\b/i', $bytes);
    }

    /**
     * @param string $ext
     * @param string $head
     * @return bool True when the claimed type has known magic and $head does not match.
     *              jpg/jpeg/png/gif/webp/bmp/ico accept any of those image headers.
     *              Alias extensions share the container check (wma/asf, m4a/ftyp,
     *              mkv/EBML, opus/OggS). PDF looks for %PDF- in the first 1024 bytes.
     */
    public static function magic_mismatch($ext, $head) {
        $ext = strtolower((string) $ext);
        $head = (string) $head;
        if ($head === '') {
            return true;
        }

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
            case 'jpe':
            case 'jfif':
            case 'jif':
            case 'png':
            case 'gif':
            case 'webp':
            case 'bmp':
            case 'ico':
                return !self::is_known_image_head($head);
            case 'tif':
            case 'tiff':
                return strncmp($head, "II*\x00", 4) !== 0 && strncmp($head, "MM\x00*", 4) !== 0;
            case 'svg':
                $trim = ltrim($head);
                return strncasecmp($trim, '<svg', 4) !== 0 && strncasecmp($trim, '<?xml', 5) !== 0;
            case 'mp4':
            case 'm4v':
            case 'm4a':
            case 'm4b':
            case 'm4p':
            case 'm4r':
            case 'mov':
            case '3gp':
            case '3g2':
            case 'f4v':
            case 'f4a':
            case 'heic':
            case 'heif':
            case 'avif':
                return substr($head, 4, 4) !== 'ftyp';
            case 'webm':
            case 'mkv':
            case 'mka':
            case 'mk3d':
                return strncmp($head, "\x1A\x45\xDF\xA3", 4) !== 0;
            case 'ogv':
            case 'ogg':
            case 'oga':
            case 'opus':
            case 'ogx':
            case 'spx':
                return strncmp($head, 'OggS', 4) !== 0;
            case 'wmv':
            case 'asf':
            case 'wma':
            case 'wm':
                return strncmp($head, "\x30\x26\xB2\x75\x8E\x66\xCF\x11", 8) !== 0;
            case 'flac':
                // Native FLAC, or a FLAC with an ID3 tag in front.
                return strncmp($head, 'fLaC', 4) !== 0 && strncmp($head, 'ID3', 3) !== 0;
            case 'flv':
                return strncmp($head, "FLV\x01", 4) !== 0;
            case 'aif':
            case 'aiff':
                return strncmp($head, 'FORM', 4) !== 0
                    || (substr($head, 8, 4) !== 'AIFF' && substr($head, 8, 4) !== 'AIFC');
            case 'woff':
            case 'woff2':
                return strncmp($head, 'wOFF', 4) !== 0 && strncmp($head, 'wOF2', 4) !== 0;
            case 'pdf':
                return strpos(substr($head, 0, 1024), '%PDF-') === false;
            case 'avi':
                return strncmp($head, 'RIFF', 4) !== 0 || substr($head, 8, 3) !== 'AVI';
            case 'wav':
                return strncmp($head, 'RIFF', 4) !== 0 || substr($head, 8, 4) !== 'WAVE';
            case 'mp3':
                return strncmp($head, 'ID3', 3) !== 0
                    && (strlen($head) < 2 || (ord($head[0]) !== 0xFF || (ord($head[1]) & 0xE0) !== 0xE0));
            default:
                return false;
        }
    }

    /**
     * JPEG, PNG, GIF, WebP, BMP, or ICO. A different image type under one of
     * those extensions is still an image. SVG is not included.
     *
     * @param string $head
     * @return bool
     */
    private static function is_known_image_head($head) {
        if (strncmp($head, "\xFF\xD8", 2) === 0) {
            return true;
        }
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return true;
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return true;
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return true;
        }
        if (strncmp($head, 'BM', 2) === 0) {
            return true;
        }
        if (strncmp($head, "\x00\x00\x01\x00", 4) === 0 || strncmp($head, "\x00\x00\x02\x00", 4) === 0) {
            return true;
        }
        return false;
    }

    private static function read_window($path, $offset, $length) {
        $length = (int) $length;
        if ($length <= 0) {
            return '';
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return '';
        }
        if ($offset > 0 && fseek($handle, (int) $offset) !== 0) {
            fclose($handle);
            return '';
        }
        $data = fread($handle, $length);
        fclose($handle);
        return is_string($data) ? $data : '';
    }
}
