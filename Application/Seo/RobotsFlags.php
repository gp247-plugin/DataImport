<?php

namespace App\GP247\Plugins\DataImport\Application\Seo;

/**
 * "Hide from search engines" flags of a row. Yoast exports `1` for noindex / nofollow
 * (`2` = explicitly indexed); Rank Math exports `rank_math_robots` as a serialized PHP
 * array — it is only searched for the words, never unserialized (the file is untrusted).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-seo-meta
 * @aidlc-adr data-import_seo-carryover
 */
final class RobotsFlags
{
    /** Cell values read as "yes". */
    private const YES = ['1', 'yes', 'true', 'on', 'noindex', 'nofollow'];

    /**
     * Cell values read as "no". WHY not `0`: in Yoast's columns `0` means "default of the post type", i.e. not set —
     * reading it as "no" would clear a noindex set on the new shop each time the old file is imported again.
     */
    private const NO = ['2', 'no', 'false', 'off', 'index', 'follow'];

    /**
     * @param string|null $noindex  Cell of `seo_noindex`.
     * @param string|null $nofollow Cell of `seo_nofollow`.
     * @param string|null $robots   Cell of `seo_robots` (Rank Math serialized list or "noindex, nofollow").
     * @return array{noindex: bool|null, nofollow: bool|null} null = keep the current value.
     */
    public static function read(?string $noindex, ?string $nofollow, ?string $robots): array
    {
        $flags = ['noindex' => null, 'nofollow' => null];
        $robots = strtolower(trim((string) $robots));
        if ($robots !== '') {
            $flags['noindex'] = self::word($robots, 'noindex') ? true : (self::word($robots, 'index') ? false : null);
            $flags['nofollow'] = self::word($robots, 'nofollow') ? true : (self::word($robots, 'follow') ? false : null);
        }
        // The dedicated columns win over the list.
        $flags['noindex'] = self::cell($noindex) ?? $flags['noindex'];
        $flags['nofollow'] = self::cell($nofollow) ?? $flags['nofollow'];

        return $flags;
    }

    /**
     * @param string|null $cell
     * @return bool|null
     */
    private static function cell(?string $cell): ?bool
    {
        $cell = strtolower(trim((string) $cell));

        return match (true) {
            in_array($cell, self::YES, true) => true,
            in_array($cell, self::NO, true) => false,
            default => null,
        };
    }

    /**
     * @param string $haystack
     * @param string $word
     * @return bool Whether $word appears as a whole word (`index` is not found inside `noindex`).
     */
    private static function word(string $haystack, string $word): bool
    {
        return (bool) preg_match('/(?<![a-z])' . $word . '(?![a-z])/', $haystack);
    }
}
