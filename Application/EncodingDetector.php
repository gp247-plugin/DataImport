<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\SourceEncoding;

/**
 * Guesses the character set of a source file from its first 64 KB: a UTF-8 BOM or valid
 * UTF-8 wins; otherwise the candidates are ranked by how much of the decoded text falls in
 * the script they are meant for (kana / kanji for the Japanese sets, Vietnamese letters for
 * Windows-1258). The user can always pick another set on the preview.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-encoding-detection
 * @aidlc-adr data-import_file-formats
 */
final class EncodingDetector
{
    public const SAMPLE_BYTES = 65536;

    /**
     * @param string $sample First bytes of the file.
     * @return array{encoding: string|null, bom: int, candidates: array<int, string>, error: string|null}
     *         `bom` = bytes to skip; `error` = 'utf16' when the file is UTF-16 (not supported).
     */
    public function detect(string $sample): array
    {
        if (str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF")) {
            return ['encoding' => null, 'bom' => 0, 'candidates' => [], 'error' => 'utf16'];
        }
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return ['encoding' => SourceEncoding::UTF8, 'bom' => 3, 'candidates' => [SourceEncoding::UTF8], 'error' => null];
        }
        if (mb_check_encoding($this->cutUtf8Tail($sample), 'UTF-8')) {
            return ['encoding' => SourceEncoding::UTF8, 'bom' => 0, 'candidates' => [SourceEncoding::UTF8], 'error' => null];
        }

        $scores = [];
        foreach (['CP932', 'EUC-JP', 'Windows-1258', 'Windows-1252'] as $candidate) {
            $score = $this->score($sample, $candidate);
            if ($score !== null) {
                $scores[$candidate] = $score;
            }
        }
        arsort($scores);
        $ranked = array_keys($scores);

        return ['encoding' => $ranked[0] ?? null, 'bom' => 0, 'candidates' => $ranked, 'error' => null];
    }

    /**
     * Share of decoded characters in the script the set is meant for; null when the sample is
     * not valid in the set.
     *
     * @param string $sample
     * @param string $encoding
     * @return float|null
     */
    private function score(string $sample, string $encoding): ?float
    {
        // A sample cut mid-character fails every multi-byte set: drop the last line instead.
        $cut = strrpos($sample, "\n");
        $text = SourceEncoding::toUtf8($cut !== false ? substr($sample, 0, $cut) : $sample, $encoding);
        if ($text === null || $text === '') {
            return null;
        }
        $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $text);
        if ($nonAscii === 0) {
            return 0.0;
        }
        $pattern = match ($encoding) {
            'CP932', 'EUC-JP' => '/[\p{Hiragana}\p{Katakana}\p{Han}ー]/u',
            'Windows-1258' => '/[ăâđêôơưĂÂĐÊÔƠƯ\x{0300}\x{0301}\x{0303}\x{0309}\x{0323}]/u',
            default => '/[\p{Latin}]/u',
        };
        $hits = preg_match_all($pattern, $text);

        // Windows-1252 decodes almost anything: rank it last unless nothing else fits.
        return ($hits / $nonAscii) - ($encoding === 'Windows-1252' ? 0.5 : 0.0);
    }

    /**
     * Drop a trailing incomplete UTF-8 sequence (the sample may end mid-character).
     *
     * @param string $bytes
     * @return string
     */
    private function cutUtf8Tail(string $bytes): string
    {
        for ($i = 1; $i <= 3 && $i <= strlen($bytes); $i++) {
            $byte = ord($bytes[strlen($bytes) - $i]);
            if (($byte & 0xC0) === 0xC0) {
                return substr($bytes, 0, strlen($bytes) - $i);
            }
            if (($byte & 0x80) === 0) {
                break;
            }
        }

        return $bytes;
    }
}
