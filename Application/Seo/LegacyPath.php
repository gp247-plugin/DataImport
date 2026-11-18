<?php

namespace App\GP247\Plugins\DataImport\Application\Seo;

/**
 * The address a record had on the previous platform, as a path: from an "old URL" column
 * (a full URL keeps only its path) or built from the preset's pattern (`/products/{key}`).
 * Whether the path is safe and free is decided by the base (SeoUrlLifecycle), not here.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-legacy-url-redirect
 * @aidlc-adr data-import_seo-carryover
 */
final class LegacyPath
{
    /**
     * @param string $cell Full URL (`https://old.example/products/a?x=1`) or path (`/products/a/`).
     * @return string Path (with `?query` when the old address had one), '' when the cell is empty. A value
     *                that is neither is returned as it is (the base rejects it, the review shows why).
     */
    public static function fromCell(string $cell): string
    {
        $cell = trim($cell);
        if ($cell === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $cell)) {
            $path = parse_url($cell, PHP_URL_PATH);
            $query = (string) parse_url($cell, PHP_URL_QUERY);
            $path = is_string($path) && $path !== '' ? $path : '/';

            // WHY keep the query: an address told apart by it (`/index.php?id=42`) cannot be redirected
            // by path; the caller reports it instead of sending every such product to one target.
            return $query === '' ? $path : $path . '?' . $query;
        }
        if ($cell[0] === '/') {
            // WHY not for `//host/…`: a scheme-relative URL is another site; keep it whole so the base refuses it.
            return str_starts_with($cell, '//') ? $cell : (string) strtok($cell, '#');
        }

        return str_contains($cell, ':') ? $cell : '/' . strtok($cell, '#');
    }

    /**
     * @param string                $pattern Preset pattern, `{field}` = mapped field of the row.
     * @param array<string, string> $values  Mapped values of the row.
     * @return string Path, '' when a field of the pattern is empty.
     */
    public static function fromPattern(string $pattern, array $values): string
    {
        $missing = false;
        $path = (string) preg_replace_callback('/\{([a-z_:]+)\}/', static function (array $m) use ($values, &$missing): string {
            $value = trim((string) ($values[$m[1]] ?? ''));
            if ($value === '') {
                $missing = true;
            }

            return rawurlencode($value);
        }, $pattern);

        return $missing ? '' : $path;
    }
}
