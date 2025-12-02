<?php
/**
 * Clean Sweep - Minimal Filesystem Compatibility
 *
 * Provides WordPress-compatible filesystem functions without loading WordPress core.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_Filesystem {

    public $method = 'direct';

    public function rmdir($path, $recursive = false) {
        $path = clean_sweep_translate_path($path);
        if ($recursive) {
            return $this->recursive_delete($path);
        } else {
            return @rmdir($path);
        }
    }

    public function mkdir($path, $chmod = false) {
        $path = clean_sweep_translate_path($path);
        $chmod = $chmod ?: 0755;
        return @mkdir($path, $chmod, true);
    }

    public function delete($path, $recursive = false) {
        $path = clean_sweep_translate_path($path);
        if (is_dir($path)) {
            return $recursive ? $this->recursive_delete($path) : @rmdir($path);
        } else {
            return @unlink($path);
        }
    }

    public function exists($path) {
        $path = clean_sweep_translate_path($path);
        return file_exists($path);
    }

    public function is_dir($path) {
        $path = clean_sweep_translate_path($path);
        return is_dir($path);
    }

    public function is_file($path) {
        $path = clean_sweep_translate_path($path);
        return is_file($path);
    }

    public function put_contents($file, $contents, $mode = false) {
        $file = clean_sweep_translate_path($file);
        return file_put_contents($file, $contents);
    }

    public function get_contents($file) {
        $file = clean_sweep_translate_path($file);
        return file_get_contents($file);
    }

    public function copy($source, $destination, $overwrite = true) {
        $source = clean_sweep_translate_path($source);
        $destination = clean_sweep_translate_path($destination);
        if (!$overwrite && file_exists($destination)) {
            return false;
        }
        return copy($source, $destination);
    }

    public function move($source, $destination, $overwrite = true) {
        $source = clean_sweep_translate_path($source);
        $destination = clean_sweep_translate_path($destination);
        if (!$overwrite && file_exists($destination)) {
            return false;
        }
        return rename($source, $destination);
    }

    public function dirlist($path, $include_hidden = true, $recursive = false) {
        $path = clean_sweep_translate_path($path);
        if (!is_dir($path)) {
            return false;
        }

        $list = [];

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $relative_path = str_replace($path . '/', '', $file->getPathname());
                $list[$relative_path] = [
                    'name' => $file->getFilename(),
                    'type' => $file->isDir() ? 'd' : 'f',
                    'perms' => substr(sprintf('%o', $file->getPerms()), -4),
                    'permsn' => $file->getPerms(),
                    'number' => false, // Not implemented
                    'owner' => fileowner($file->getPathname()),
                    'group' => filegroup($file->getPathname()),
                    'size' => $file->getSize(),
                    'lastmodunix' => $file->getMTime(),
                    'lastmod' => date('M j', $file->getMTime()),
                    'time' => date('H:i', $file->getMTime()),
                    'files' => $file->isDir() ? iterator_count(new FilesystemIterator($file->getPathname())) : false
                ];
            }
        } else {
            $items = scandir($path);
            if ($items === false) {
                return false;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (!$include_hidden && substr($item, 0, 1) === '.') {
                    continue;
                }

                $full_path = $path . '/' . $item;
                $list[$item] = [
                    'name' => $item,
                    'type' => is_dir($full_path) ? 'd' : 'f',
                    'perms' => substr(sprintf('%o', fileperms($full_path)), -4),
                    'permsn' => fileperms($full_path),
                    'number' => false, // Not implemented
                    'owner' => fileowner($full_path),
                    'group' => filegroup($full_path),
                    'size' => filesize($full_path),
                    'lastmodunix' => filemtime($full_path),
                    'lastmod' => date('M j', filemtime($full_path)),
                    'time' => date('H:i', filemtime($full_path)),
                    'files' => is_dir($full_path) ? count(scandir($full_path)) - 2 : false
                ];
            }
        }

        return $list;
    }

    /**
     * Recursive directory deletion
     */
    private function recursive_delete($dir_path) {
        if (!is_dir($dir_path)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        return @rmdir($dir_path);
    }
}
