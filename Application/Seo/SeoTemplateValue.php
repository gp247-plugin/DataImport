<?php

namespace App\GP247\Plugins\DataImport\Application\Seo;

/**
 * An SEO title or description of WordPress SEO plugins: often a template with variables
 * (`%%title%% %%sep%% %%sitename%%` in Yoast, `%title% %sep% %sitename%` in Rank Math)
 * rather than words someone wrote. A value made only of variables is not imported (the
 * store's own title template does that job); known variables of a written value are
 * replaced; any other variable drops the value so `%%…%%` never reaches a search result.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-seo-meta
 * @aidlc-adr data-import_seo-carryover
 */
final class SeoTemplateValue
{
    /** No variable: imported as it is. */
    public const TEXT = 'text';

    /** Only variables and separators: not imported. */
    public const TEMPLATE_ONLY = 'template_only';

    /** Written words with known variables replaced. */
    public const RESOLVED = 'resolved';

    /** A variable that cannot be replaced: not imported, reported. */
    public const UNKNOWN_VARIABLE = 'unknown_var';

    /** Variables replaced by a value of the row or the store. */
    public const REPLACEABLE = ['title', 'sitename', 'sep', 'excerpt'];

    /**
     * Single-percent names read as Rank Math variables; any other `%word%` is plain text
     * ("50% off 20%" never is a variable).
     */
    private const RANK_MATH_NAMES = [
        'title', 'sitename', 'sep', 'excerpt', 'sitedesc', 'category', 'primary_category', 'categories',
        'term', 'term_description', 'tag', 'tags', 'page', 'currentyear', 'currentmonth', 'currentday',
        'currentdate', 'focuskw', 'keywords', 'name', 'post_author', 'seo_title', 'seo_description',
        'url', 'filename', 'date', 'modified', 'id', 'parent_title', 'org_name', 'wc_price', 'wc_sku',
        'wc_shortdesc', 'wc_brand', 'pt_single', 'pt_plural', 'pagenumber', 'pagetotal',
    ];

    /**
     * Classify a value and replace its known variables.
     *
     * @param string               $value        Cell value.
     * @param array<string, string> $replacements Variable name => text (title, sitename, sep, excerpt).
     * @return array{0: string, 1: string, 2: string|null} [kind, value to import ('' when none), first unknown variable]
     */
    public static function resolve(string $value, array $replacements): array
    {
        // WHY like the base's clean(): a trailing NBSP / BOM or a control character would show as a change on every re-import.
        $value = trim((string) preg_replace(['/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u'], '', $value));
        $names = self::variables($value);
        if ($names === []) {
            return [self::TEXT, $value, null];
        }
        $words = trim((string) preg_replace('/[\s\-–—|:·•»«,.\/]+/u', ' ', self::strip($value)));
        if ($words === '') {
            return [self::TEMPLATE_ONLY, '', null];
        }
        foreach ($names as $name) {
            if (!in_array($name, self::REPLACEABLE, true)) {
                return [self::UNKNOWN_VARIABLE, '', $name];
            }
        }
        $text = (string) preg_replace_callback(self::pattern(), static function (array $m) use ($replacements): string {
            $name = strtolower($m[1] !== '' ? $m[1] : (string) ($m[2] ?? ''));

            return (string) ($replacements[$name] ?? '');
        }, $value);
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', $text));
        // WHY: an empty replacement (no excerpt) may leave a separator at an end.
        $text = trim($text, " \t-–—|:·");

        return [$text === '' ? self::TEMPLATE_ONLY : self::RESOLVED, $text, null];
    }

    /**
     * @param string $value
     * @return array<int, string> Variable names found (lower case).
     */
    private static function variables(string $value): array
    {
        if (!preg_match_all(self::pattern(), $value, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (array $m) => strtolower($m[1] !== '' ? $m[1] : (string) ($m[2] ?? '')),
            $matches
        )));
    }

    /**
     * @param string $value
     * @return string The value without its variables.
     */
    private static function strip(string $value): string
    {
        return (string) preg_replace(self::pattern(), ' ', $value);
    }

    /**
     * Yoast `%%name%%` (any name) or Rank Math `%name%` (known names only).
     *
     * @return string
     */
    private static function pattern(): string
    {
        static $pattern = null;

        return $pattern ??= '/%%([a-z0-9_]+)%%|%(' . implode('|', self::RANK_MATH_NAMES) . ')%/i';
    }
}
