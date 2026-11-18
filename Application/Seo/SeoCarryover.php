<?php

namespace App\GP247\Plugins\DataImport\Application\Seo;

use App\GP247\Plugins\DataImport\Application\Presets;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Library\SeoUrlLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only way the import reaches the SEO data of the base (gp247/front): SEO title and
 * description per language and the "hide from search engines" flags go through
 * SeoContentMeta, the 301 from the previous platform's address through SeoUrlLifecycle.
 * Both are optional: on a front without them the SEO fields simply do not exist.
 *
 * In a row's flat values the SEO fields are `seo_title:<lang>`, `seo_description:<lang>`,
 * `seo_noindex`, `seo_nofollow` — so the review diff, the record fingerprint and the undo
 * log cover them like any other field. The old address is not a field of the record: it
 * is planned and written on its own (legacy*()).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-seo-meta, US-data-import-legacy-url-redirect
 * @aidlc-adr data-import_seo-carryover
 */
final class SeoCarryover
{
    /** Multilingual SEO texts (target `field` or `field:<lang>`). */
    public const TEXT_FIELDS = ['seo_title', 'seo_description'];

    /** Flag targets. */
    public const FLAG_FIELDS = ['seo_noindex', 'seo_nofollow', 'seo_robots'];

    /** Target holding the old address. */
    public const LEGACY_FIELD = 'legacy_url';

    /** Key of the SEO values inside a write-service payload. */
    private const PAYLOAD_KEY = '_seo';

    /** Separator a `%%sep%%` variable becomes. */
    private const SEPARATOR = '-';

    /**
     * @param string|null $type SEO content type of the entity (null = none).
     * @return bool Whether the front can store SEO values for that type.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta, US-data-import-column-mapping
     */
    public static function supported(?string $type): bool
    {
        return $type !== null
            && class_exists(SeoContentMeta::class)
            && SeoContentMeta::isRegistered($type)
            && SeoContentMeta::tableReady();
    }

    /**
     * @param string|null $type
     * @return bool Whether the front can write a 301 from an old address of that type.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-legacy-url-redirect, US-data-import-column-mapping
     */
    public static function legacySupported(?string $type): bool
    {
        return $type !== null
            && class_exists(SeoContentMeta::class)
            && class_exists(SeoUrlLifecycle::class)
            && method_exists(SeoUrlLifecycle::class, 'redirectLegacyPath')
            && method_exists(SeoUrlLifecycle::class, 'removeImportedRedirect')
            && (string) (SeoContentMeta::types()[$type]['route'] ?? '') !== '';
    }

    /**
     * Targets a column can be mapped to.
     *
     * @param string|null        $type
     * @param array<int, string> $languages Active language codes.
     * @return array<int, string>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-column-mapping
     */
    public static function targets(?string $type, array $languages): array
    {
        $targets = [];
        if (self::supported($type)) {
            foreach (self::TEXT_FIELDS as $field) {
                $targets[] = $field;
                foreach ($languages as $code) {
                    $targets[] = $field . ':' . $code;
                }
            }
            array_push($targets, ...self::FLAG_FIELDS);
        }
        if (self::legacySupported($type)) {
            $targets[] = self::LEGACY_FIELD;
        }

        return $targets;
    }

    /**
     * Add the SEO values of a row to its flat values.
     *
     * @param string|null           $type
     * @param array<string, string> $values    Mapped values of the row.
     * @param array<string, string> $flat      Flat values resolved so far (names for `%%title%%`).
     * @param ImportJob             $job
     * @param array<int, string>    $languages Active languages, the default first.
     * @return array{0: array<string, string>, 1: array<string, string>} [flat, warnings code => message]
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function resolve(?string $type, array $values, array $flat, ImportJob $job, array $languages): array
    {
        if (!self::supported($type)) {
            return [$flat, []];
        }
        $warnings = [];
        $default = (string) $job->option('language', $languages[0] ?? 'en');
        $site = (string) gp247_store_info('name', null, $job->store_id);
        foreach (self::TEXT_FIELDS as $field) {
            foreach (self::cellsOf($values, $field, $default, $languages) as $lang => $cell) {
                $title = (string) ($flat['name:' . $lang] ?? $flat['title:' . $lang] ?? $flat['name'] ?? $values['name'] ?? '');
                [$kind, $text, $unknown] = SeoTemplateValue::resolve($cell, ['title' => $title, 'sitename' => $site, 'sep' => self::SEPARATOR, 'excerpt' => '']);
                if ($kind === SeoTemplateValue::TEMPLATE_ONLY) {
                    $warnings['seo_template'] = self::message('warning.seo_template');
                } elseif ($kind === SeoTemplateValue::UNKNOWN_VARIABLE) {
                    $warnings['seo_unknown_var'] = self::message('warning.seo_unknown_var', ['name' => (string) $unknown]);
                } elseif ($text !== '') {
                    $flat[$field . ':' . $lang] = $text;
                }
            }
        }
        $flags = RobotsFlags::read($values['seo_noindex'] ?? null, $values['seo_nofollow'] ?? null, $values['seo_robots'] ?? null);
        foreach (['noindex', 'nofollow'] as $flag) {
            if ($flags[$flag] !== null) {
                $flat['seo_' . $flag] = $flags[$flag] ? '1' : '0';
            }
        }

        return [$flat, $warnings];
    }

    /**
     * Current SEO values of a record for the requested keys.
     *
     * @param string|null        $type
     * @param string             $id
     * @param array<int, string> $keys Flat keys (non-SEO keys are ignored).
     * @return array<string, string>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta, US-data-import-change-review
     */
    public static function snapshot(?string $type, string $id, array $keys): array
    {
        $wanted = array_values(array_filter($keys, [self::class, 'isSeoKey']));
        if ($wanted === [] || !self::supported($type)) {
            return [];
        }
        $state = SeoContentMeta::formState((string) $type, $id);
        $out = [];
        foreach ($wanted as $key) {
            [$field, $lang] = array_pad(explode(':', $key, 2), 2, null);
            $out[$key] = match ($field) {
                'seo_noindex' => !empty($state['noindex']) ? '1' : '0',
                'seo_nofollow' => !empty($state['nofollow']) ? '1' : '0',
                'seo_title' => (string) ($state['desc'][$lang]['title'] ?? ''),
                'seo_description' => (string) ($state['desc'][$lang]['description'] ?? ''),
                default => '',
            };
        }

        return $out;
    }

    /**
     * @param string $key Flat key.
     * @return bool
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function isSeoKey(string $key): bool
    {
        return in_array(explode(':', $key, 2)[0], ['seo_title', 'seo_description', 'seo_noindex', 'seo_nofollow'], true);
    }

    /**
     * Flat values without the SEO keys (what the shop write service receives).
     *
     * @param array<string, string> $flat
     * @return array<string, string>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function withoutSeo(array $flat): array
    {
        return array_filter($flat, static fn ($key) => !self::isSeoKey((string) $key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Carry the SEO values of $flat inside a write-service payload.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $flat
     * @return array<string, mixed>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function attach(array $payload, array $flat): array
    {
        $seo = array_filter($flat, static fn ($key) => self::isSeoKey((string) $key), ARRAY_FILTER_USE_KEY);
        if ($seo !== []) {
            $payload[self::PAYLOAD_KEY] = $seo;
        }

        return $payload;
    }

    /**
     * Split a payload into what the shop service receives and the SEO values.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function take(array $payload): array
    {
        $seo = (array) ($payload[self::PAYLOAD_KEY] ?? []);
        unset($payload[self::PAYLOAD_KEY]);

        return [$payload, $seo];
    }

    /**
     * Whether a payload asks the shop service for nothing (a row that only changes SEO).
     *
     * @param array<string, mixed> $payload Payload without the SEO values.
     * @return bool
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function isEmptyPayload(array $payload): bool
    {
        foreach ($payload as $part) {
            if ($part !== [] && $part !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check the SEO values with the rules of the SEO screen.
     *
     * @param string|null           $type
     * @param array<string, string> $seo SEO flat values.
     * @param string|null           $id  Record id (merged with its current values).
     * @return array<string, array<int, string>> Errors by flat key.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function validate(?string $type, array $seo, ?string $id): array
    {
        if ($seo === [] || !self::supported($type)) {
            return [];
        }
        $validator = Validator::make(self::merged((string) $type, $id, $seo), SeoContentMeta::rules(''));
        if (!$validator->fails()) {
            return [];
        }
        $errors = [];
        foreach ($validator->errors()->messages() as $key => $messages) {
            $errors[self::flatKey($key)] = $messages;
        }

        return $errors;
    }

    /**
     * Store the SEO values of a written record (inside the row's transaction).
     *
     * @param string|null           $type
     * @param Model                 $model
     * @param array<string, string> $seo
     * @param string                $storeId
     * @return void
     * @throws ValidationException When a value breaks the rules of the SEO screen.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public static function write(?string $type, Model $model, array $seo, string $storeId): void
    {
        if ($seo === [] || !self::supported($type)) {
            return;
        }
        $id = (string) $model->getKey();
        SeoContentMeta::save((string) $type, $id, self::merged((string) $type, $id, $seo), $storeId);
    }

    /**
     * The old address of a row: the "old URL" column, else the preset's pattern.
     *
     * @param array<string, string> $values Mapped values of the row.
     * @param ImportJob             $job
     * @return string Path, '' when none.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-legacy-url-redirect
     */
    public static function legacyPath(array $values, ImportJob $job): string
    {
        $cell = trim((string) ($values[self::LEGACY_FIELD] ?? ''));
        if ($cell !== '') {
            return LegacyPath::fromCell($cell);
        }
        $pattern = Presets::legacyPath((string) $job->option('preset', ''), (string) $job->entity);

        return $pattern === '' ? '' : LegacyPath::fromPattern($pattern, $values);
    }

    /**
     * @param string|null $type
     * @param string      $storeId
     * @param string      $path
     * @param string      $alias Alias of the record on this site ('' when not known yet).
     * @return string SeoUrlLifecycle::LEGACY_* reason.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-legacy-url-redirect, US-data-import-change-review
     */
    public static function checkLegacy(?string $type, string $storeId, string $path, string $alias): string
    {
        return SeoUrlLifecycle::checkLegacyPath($storeId, $path, (string) $type, $alias);
    }

    /**
     * Write the 301 of an old address (inside the row's transaction).
     *
     * @param string|null $type
     * @param string      $storeId
     * @param string      $path
     * @param string      $alias
     * @return array{id: int, to: string}|null Null when skipped.
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-legacy-url-redirect
     */
    public static function redirect(?string $type, string $storeId, string $path, string $alias): ?array
    {
        $id = SeoUrlLifecycle::redirectLegacyPath($storeId, $path, (string) $type, $alias);
        if ($id === null) {
            return null;
        }
        $paths = SeoUrlLifecycle::pathsFor((string) $type, $alias);
        $lang = (string) gp247_store_info('language', null, $storeId);

        return ['id' => $id, 'to' => (string) ($paths[$lang] ?? $paths['_'] ?? reset($paths))];
    }

    /**
     * Remove a 301 an import wrote, unless it was changed since.
     *
     * @param int                $id
     * @param string             $to
     * @param \DateTimeInterface $since
     * @return bool
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-undo, US-data-import-legacy-url-redirect
     */
    public static function removeRedirect(int $id, string $to, \DateTimeInterface $since): bool
    {
        return class_exists(SeoUrlLifecycle::class) && method_exists(SeoUrlLifecycle::class, 'removeImportedRedirect')
            && SeoUrlLifecycle::removeImportedRedirect($id, $to, $since);
    }

    /**
     * Current SEO values of a record with $seo laid over them: an empty cell never clears a
     * value, and what the import does not carry (canonical, share image) stays.
     *
     * @param string                $type
     * @param string|null           $id
     * @param array<string, string> $seo
     * @return array<string, mixed>
     */
    private static function merged(string $type, ?string $id, array $seo): array
    {
        $state = SeoContentMeta::formState($type, $id);
        foreach ($seo as $key => $value) {
            [$field, $lang] = array_pad(explode(':', (string) $key, 2), 2, null);
            if ($field === 'seo_noindex' || $field === 'seo_nofollow') {
                $state[substr($field, 4)] = $value === '1';
            } elseif ($lang !== null && isset($state['desc'][$lang])) {
                $state['desc'][$lang][$field === 'seo_title' ? 'title' : 'description'] = (string) $value;
            }
        }

        return $state;
    }

    /**
     * Cells of a multilingual SEO field by language (plain `field` = the run's language).
     *
     * @param array<string, string> $values
     * @param string                $field
     * @param string                $default
     * @param array<int, string>    $languages
     * @return array<string, string>
     */
    private static function cellsOf(array $values, string $field, string $default, array $languages): array
    {
        $cells = [];
        if (trim((string) ($values[$field] ?? '')) !== '') {
            $cells[$default] = (string) $values[$field];
        }
        foreach ($languages as $code) {
            if (trim((string) ($values[$field . ':' . $code] ?? '')) !== '') {
                $cells[$code] = (string) $values[$field . ':' . $code];
            }
        }

        return $cells;
    }

    /**
     * @param string $key Validator key (`desc.vi.title`, `noindex`).
     * @return string Flat key (`seo_title:vi`, `seo_noindex`).
     */
    private static function flatKey(string $key): string
    {
        if (preg_match('/^desc\.([^.]+)\.(title|description)$/', $key, $m)) {
            return 'seo_' . $m[2] . ':' . $m[1];
        }

        return 'seo_' . $key;
    }

    /**
     * @param string                $key
     * @param array<string, string> $replace
     * @return string
     */
    private static function message(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/DataImport::lang.' . $key, $replace);
    }
}
