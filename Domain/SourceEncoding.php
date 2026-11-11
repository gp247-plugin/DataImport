<?php

namespace App\GP247\Plugins\DataImport\Domain;

/**
 * Character sets a source file may use, and how to turn one cell into UTF-8 with the
 * extensions every PHP host has (mbstring, iconv) — no third-party library.
 *
 * Every allowed set keeps the CSV syntax bytes (`,` `;` TAB `"` CR LF) out of its multi-byte
 * sequences — CP932 trail bytes are 0x40–0xFC, EUC-JP bytes are ≥ 0xA1, UTF-8 continuation
 * bytes are ≥ 0x80 — so the file can be split into cells on its raw bytes (exact byte offsets
 * for the cursor) and each cell decoded afterwards. `|` (0x7C) is NOT safe in CP932 and is not
 * offered as a delimiter. No backslash escaping is ever applied (0x5C is the second byte of ソ, 表…).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-encoding-detection
 * @aidlc-adr data-import_file-formats
 */
final class SourceEncoding
{
    public const UTF8 = 'UTF-8';

    /** Allowed sets => [extension, name the extension knows it by]. */
    private const SETS = [
        'UTF-8' => ['mb', 'UTF-8'],
        'CP932' => ['mb', 'SJIS-win'],
        'EUC-JP' => ['mb', 'eucJP-win'],
        'Windows-1258' => ['iconv', 'WINDOWS-1258'],
        'Windows-1252' => ['mb', 'Windows-1252'],
        'ISO-8859-1' => ['mb', 'ISO-8859-1'],
    ];

    /** Delimiters that are safe in every allowed set. */
    public const DELIMITERS = [',', ';', "\t"];

    /**
     * @return array<int, string> Allowed set names.
     */
    public static function names(): array
    {
        return array_keys(self::SETS);
    }

    /**
     * Whether $name is an allowed set.
     *
     * @param string $name
     * @return bool
     */
    public static function allowed(string $name): bool
    {
        return array_key_exists($name, self::SETS);
    }

    /**
     * Decode one cell to UTF-8.
     *
     * @param string $bytes
     * @param string $encoding An allowed set name.
     * @return string|null UTF-8 text, or null when the bytes are not valid in that set.
     */
    public static function toUtf8(string $bytes, string $encoding): ?string
    {
        [$ext, $name] = self::SETS[$encoding] ?? [null, null];
        if ($ext === null) {
            return null;
        }
        if ($bytes === '' || ($encoding !== self::UTF8 && preg_match('/^[\x00-\x7F]*$/', $bytes))) {
            return $bytes;
        }
        if ($ext === 'mb') {
            if (!mb_check_encoding($bytes, $name)) {
                return null;
            }
            $text = $encoding === self::UTF8 ? $bytes : mb_convert_encoding($bytes, 'UTF-8', $name);
        } else {
            if (!function_exists('iconv')) {
                return null;
            }
            $text = @iconv($name, 'UTF-8', $bytes);
            if ($text === false) {
                return null;
            }
        }

        return str_contains($text, "\u{FFFD}") ? null : $text;
    }
}
