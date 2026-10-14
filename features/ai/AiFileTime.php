<?php
/**
 * Choose one labeled file time. Birth time is not read.
 */
final class AiFileTime {

    /**
     * @return array{file_time:string,file_time_basis:string}
     */
    public static function decide(?int $mtime, ?int $ctime, ?int $watch, bool $ctimeIsInode = true): array {
        if ($watch !== null && $watch > 0) {
            return [
                'file_time' => gmdate('c', $watch),
                'file_time_basis' => 'watch',
            ];
        }
        if ($mtime !== null && $ctime !== null && $mtime > 0 && $ctime > 0) {
            $delta = $ctime - $mtime;
            if ($delta >= -120 && $delta <= 120) {
                return [
                    'file_time' => gmdate('c', $mtime),
                    'file_time_basis' => 'write_agreed',
                ];
            }
            if ($ctimeIsInode && $delta > 86400 * 30) {
                return [
                    'file_time' => gmdate('c', $ctime),
                    'file_time_basis' => 'inode_change_mtime_set_back',
                ];
            }
        }
        return ['file_time' => '', 'file_time_basis' => 'unknown'];
    }
}
