<?php

namespace App\GP247\Plugins\DataImport\Support;

/**
 * Size of the file parts the browser sends: below the host's upload_max_filesize, post_max_size
 * and Livewire's temporary-upload limit, with a margin, so a large file goes up on a shared host.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source
 */
final class UploadLimits
{
    /** Never send parts larger than this (a part is sent again whole after a lost connection). */
    private const MAX_PART = 8388608;

    /** Smallest part (tiny limits would need thousands of requests). */
    private const MIN_PART = 65536;

    /** Livewire's default temporary-upload limit, in kilobytes. */
    private const LIVEWIRE_DEFAULT_KB = 12288;

    /**
     * Bytes per part.
     *
     * @return int
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source
     */
    public static function chunkBytes(): int
    {
        $configured = (int) config('Plugins/DataImport.upload_chunk_bytes', 0);
        if ($configured > 0) {
            return $configured;
        }
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
            self::livewireBytes(),
        ], static fn (int $v) => $v > 0);
        $limit = $limits === [] ? 2097152 : min($limits);

        // WHY 80 %: the multipart envelope and Livewire's own fields travel in the same POST.
        return max(self::MIN_PART, min(self::MAX_PART, (int) floor($limit * 0.8)));
    }

    /**
     * A php.ini size ("2M", "512K", "1G", "0" = unlimited) in bytes.
     *
     * @param string $value
     * @return int 0 when unlimited or unknown.
     */
    public static function iniBytes(string $value): int
    {
        if (!preg_match('/^\s*(\d+)\s*([KMG]?)/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1073741824,
            'M' => 1048576,
            'K' => 1024,
            default => 1,
        };
    }

    /**
     * Livewire's temporary-upload size rule ("max:<kilobytes>") in bytes.
     *
     * @return int
     */
    private static function livewireBytes(): int
    {
        $rules = config('livewire.temporary_file_upload.rules');
        $rules = is_array($rules) ? implode('|', array_filter($rules, 'is_string')) : (string) $rules;
        $kb = preg_match('/max:(\d+)/', $rules, $m) ? (int) $m[1] : self::LIVEWIRE_DEFAULT_KB;

        return $kb * 1024;
    }
}
