<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Models\IdMapEntry;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Core\Models\AdminLanguage;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * What the run needs to know about one kind of data: its target fields, how a source row
 * becomes a "flat" set of GP247 values (references resolved to ids, one text per language),
 * how to find an existing record, and how to hand the values to the shop write service.
 *
 * A flat set uses `field` for scalar values and `field:lang` for texts; it is what the review
 * diff, the undo log and the record fingerprint are built from.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities, US-data-import-customers, US-data-import-id-mapping
 * @aidlc-adr data-import_write-path
 */
abstract class EntityHandler
{
    /** Separator of a list of references in one cell (e.g. several categories). */
    public const LIST_SEPARATORS = '/[|,]/';

    /** @return string Entity name stored on the run. */
    abstract public function entity(): string;

    /** @return array<int, string> Scalar target fields a column can be mapped to. */
    abstract public function fields(): array;

    /** @return array<int, string> Multilingual target fields (mapped as `field` or `field:lang`). */
    public function textFields(): array
    {
        return [];
    }

    /** @return string Field that identifies a record in the store when it is not in the id map yet. */
    abstract public function naturalKeyField(): string;

    /** @return array<int, string> Fields whose cell is a code or a number (full-width → half-width). */
    public function codeFields(): array
    {
        return [];
    }

    /**
     * Fields the column mapping must cover before the file is read: each entry is a list of
     * alternatives, one of which must be mapped.
     *
     * @return array<int, array<int, string>>
     */
    public function requiredFields(): array
    {
        return [[$this->naturalKeyField(), 'key']];
    }

    /**
     * Whether several file rows with the same source key form one record (an order with one
     * row per product) instead of being duplicates.
     *
     * @return bool
     */
    public function groupsRows(): bool
    {
        return false;
    }

    /**
     * Whether a staged run must wait for the user to map source values (order statuses)
     * before it is planned.
     *
     * @param ImportJob $job
     * @return bool
     */
    public function waitsForValueMapping(ImportJob $job): bool
    {
        return false;
    }

    /**
     * Whether an existing record may be updated; false = a known key is skipped.
     *
     * @return bool
     */
    public function updatable(): bool
    {
        return true;
    }

    /**
     * Non-blocking remarks the review shows for a row about to be created / updated.
     *
     * @param array<string, string> $flat
     * @param ImportJob $job
     * @return array<string, string> code => message
     */
    public function warnings(array $flat, ImportJob $job): array
    {
        return [];
    }

    /**
     * Work that must happen outside the write transaction, right before a row is written
     * (downloading images): returns the values to write and remarks for the report.
     *
     * @param ImportJob $job
     * @param array<string, mixed> $values Staged values of the row.
     * @return array{0: array<string, mixed>, 1: array<int, array{0: string, 1: string}>} [values, [code, message] remarks]
     */
    public function beforeWrite(ImportJob $job, array $values): array
    {
        return [$values, []];
    }

    /**
     * Keys whose version is compared before a created record is removed by an undo; null =
     * the handler's delete() alone decides (dependants check).
     *
     * @return array<int, string>|null
     */
    public function createdVersionKeys(): ?array
    {
        return null;
    }

    /**
     * Turn mapped source values into flat GP247 values.
     *
     * @param array<string, string> $values Mapped values (target field => text).
     * @param ImportJob $job
     * @param bool $creating Missing languages get the default language's text on create.
     * @param array<int, string> $pendingKeys Source keys of earlier rows of the same run (not written yet).
     * @return array{0: array<string, string>, 1: array<string, array<int, string>>} [flat, errors]
     */
    abstract public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array;

    /**
     * Ids of records of the store whose natural key equals $flat's, compared byte for byte
     * (the column collation may say は = ば; this must not).
     *
     * @param string $store
     * @param array<string, string> $flat
     * @return array<int, string>
     */
    abstract public function naturalMatches(string $store, array $flat): array;

    /**
     * The record's current flat values for $keys; null when it no longer exists in $store.
     *
     * @param string $store
     * @param string $id
     * @param array<int, string> $keys
     * @return array<string, string>|null
     */
    abstract public function snapshot(string $store, string $id, array $keys): ?array;

    /**
     * Shop write-service payload for flat values.
     *
     * @param array<string, string> $flat
     * @return array<string, mixed>
     */
    abstract public function payload(array $flat): array;

    /**
     * Check without writing.
     *
     * @param WriteContext $ctx
     * @param array<string, mixed> $payload
     * @param string|null $id
     * @return array<string, array<int, string>>
     */
    abstract public function validate(WriteContext $ctx, array $payload, ?string $id): array;

    /**
     * Write through the shop service.
     *
     * @param WriteContext $ctx
     * @param array<string, mixed> $payload
     * @param string|null $id
     * @return Model
     * @throws \Illuminate\Validation\ValidationException
     */
    abstract public function save(WriteContext $ctx, array $payload, ?string $id): Model;

    /**
     * Remove a record the run created, unless something outside the run now depends on it.
     *
     * @param string $store
     * @param string $id
     * @return string|null Null when removed (or already gone); otherwise why it was kept.
     */
    abstract public function delete(string $store, string $id): ?string;

    /**
     * The source key of a row: the mapped `key` column, else the natural key.
     *
     * @param array<string, string> $values
     * @return string
     */
    public function sourceKey(array $values): string
    {
        $key = trim((string) ($values['key'] ?? ''));

        return $key !== '' ? $key : trim((string) ($values[$this->naturalKeyField()] ?? ''));
    }

    /**
     * Every field a column can be mapped to (texts as `field` and `field:<lang>`).
     *
     * @return array<int, string>
     */
    public function targets(): array
    {
        $targets = array_merge(['key'], $this->fields());
        foreach ($this->textFields() as $field) {
            $targets[] = $field;
            foreach ($this->languages() as $code) {
                $targets[] = $field . ':' . $code;
            }
        }

        return $targets;
    }

    /**
     * Fingerprint of a record for the keys the run writes: changes when any of them (or the
     * record's updated_at) changes after the review.
     *
     * @param string $store
     * @param string $id
     * @param array<int, string> $keys
     * @return string|null Null when the record is gone.
     */
    public function version(string $store, string $id, array $keys): ?string
    {
        $snapshot = $this->snapshot($store, $id, $keys);
        if ($snapshot === null) {
            return null;
        }
        ksort($snapshot);

        return sha1(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Active language codes; the first is the default.
     *
     * @return array<int, string>
     */
    public function languages(): array
    {
        return array_map('strval', array_keys(AdminLanguage::getListActive()->all()));
    }

    /**
     * Copy the texts of $values into $flat as `field:lang`: a plain `field` is the run's
     * language; on create, a language without text gets the default language's text.
     *
     * @param array<string, string> $values
     * @param array<string, string> $flat
     * @param ImportJob $job
     * @param bool $creating
     * @return array<string, string>
     */
    protected function texts(array $values, array $flat, ImportJob $job, bool $creating): array
    {
        $languages = $this->languages();
        $default = (string) $job->option('language', $languages[0] ?? 'en');
        foreach ($this->textFields() as $field) {
            if (array_key_exists($field, $values)) {
                $flat[$field . ':' . $default] = (string) $values[$field];
            }
            foreach ($languages as $code) {
                if (array_key_exists($field . ':' . $code, $values)) {
                    $flat[$field . ':' . $code] = (string) $values[$field . ':' . $code];
                }
            }
            if ($creating) {
                $fallback = $flat[$field . ':' . $default] ?? null;
                foreach ($languages as $code) {
                    if (!array_key_exists($field . ':' . $code, $flat)) {
                        $flat[$field . ':' . $code] = (string) ($fallback ?? '');
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Texts of $flat grouped as lang => field => value (the write services' `descriptions`).
     *
     * @param array<string, string> $flat
     * @return array<string, array<string, string>>
     */
    protected function descriptions(array $flat): array
    {
        $out = [];
        foreach ($flat as $key => $value) {
            if (str_contains($key, ':')) {
                [$field, $code] = explode(':', $key, 2);
                $out[$code][$field] = $value;
            }
        }

        return $out;
    }

    /**
     * Resolve the source key of another entity to its GP247 id: the id map of the run's
     * source first, then the natural key of that entity in the store.
     *
     * @param ImportJob $job
     * @param EntityHandler $target
     * @param string $sourceKey
     * @return string|null
     */
    protected function reference(ImportJob $job, EntityHandler $target, string $sourceKey): ?string
    {
        $entry = IdMapEntry::findByHash(IdMapEntry::keyHash($job->store_id, $job->source, $target->entity(), $sourceKey));
        if ($entry !== null && $target->snapshot($job->store_id, (string) $entry->target_id, []) !== null) {
            return (string) $entry->target_id;
        }
        $matches = $target->naturalMatches($job->store_id, [$target->naturalKeyField() => $sourceKey]);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Keep the ids whose value equals $value byte for byte.
     *
     * @param iterable<object> $rows Rows exposing `id` and $column.
     * @param string $column
     * @param string $value
     * @return array<int, string>
     */
    protected static function exact(iterable $rows, string $column, string $value): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (hash_equals((string) $row->{$column}, $value)) {
                $ids[] = (string) $row->id;
            }
        }

        return $ids;
    }

    /**
     * A validation message in the site language.
     *
     * @param string $key
     * @param array<string, string> $replace
     * @return string
     */
    protected static function message(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/DataImport::lang.' . $key, $replace);
    }
}
