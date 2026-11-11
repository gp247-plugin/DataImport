<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\Catalog\CatalogWriter;
use GP247\Shop\Services\Catalog\NamedRecordWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Brands and suppliers: a name and an alias derived from it, identified in the store by alias.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities
 */
abstract class NamedRecordHandler extends EntityHandler
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return NamedRecordWriter */
    abstract protected function writer(): NamedRecordWriter;

    /** @return string Product column that references this record. */
    abstract protected function productColumn(): string;

    public function naturalKeyField(): string
    {
        return 'alias';
    }

    public function codeFields(): array
    {
        return ['alias', 'sort', 'phone'];
    }

    /** Identified by the key column, the alias, or the alias derived from the name. */
    public function requiredFields(): array
    {
        return [['key', 'alias', 'name']];
    }

    /**
     * The source key: the `key` column, else the alias (given or derived from the name).
     *
     * @param array<string, string> $values
     * @return string
     */
    public function sourceKey(array $values): string
    {
        $key = parent::sourceKey($values);

        return $key !== '' ? $key : CatalogWriter::alias('', (string) ($values['name'] ?? ''));
    }

    public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array
    {
        $flat = [];
        foreach ($this->fields() as $field) {
            if (array_key_exists($field, $values)) {
                $flat[$field] = trim((string) $values[$field]);
            }
        }
        if ($creating || array_key_exists('alias', $values)) {
            $flat['alias'] = CatalogWriter::alias((string) ($flat['alias'] ?? ''), (string) ($flat['name'] ?? ''));
        }
        if ($creating) {
            // The screens require an image and a sort order; an import may have neither.
            $flat += ['image' => '', 'sort' => '0'];
        }

        return [$flat, []];
    }

    public function naturalMatches(string $store, array $flat): array
    {
        $alias = (string) ($flat['alias'] ?? '');
        if ($alias === '') {
            return [];
        }

        return self::exact($this->modelClass()::where('store_id', $store)->where('alias', $alias)->get(['id', 'alias']), 'alias', $alias);
    }

    public function snapshot(string $store, string $id, array $keys): ?array
    {
        $record = $this->modelClass()::where('store_id', $store)->find($id);
        if ($record === null) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) ($record->{$key} ?? '');
        }
        if ($keys !== []) {
            $out['updated_at'] = (string) $record->updated_at;
        }

        return $out;
    }

    public function payload(array $flat): array
    {
        $form = array_intersect_key($flat, array_flip($this->fields()));
        if (($form['image'] ?? null) === '') {
            // WHY: the admin screen requires an image; an imported record without one gets the
            // shop's "no image" placeholder path instead of failing the row.
            $form['image'] = 'GP247/Core/images/no-image.jpg';
        }

        return ['form' => $form];
    }

    public function validate(WriteContext $ctx, array $payload, ?string $id): array
    {
        return $this->writer()->validate($ctx, $payload, $id);
    }

    public function save(WriteContext $ctx, array $payload, ?string $id): Model
    {
        return $this->writer()->save($ctx, $payload, $id);
    }

    public function delete(string $store, string $id): ?string
    {
        $record = $this->modelClass()::where('store_id', $store)->find($id);
        if ($record === null) {
            return null;
        }
        if (ShopProduct::where($this->productColumn(), $id)->exists()) {
            return self::message('undo.kept_in_use');
        }
        $record->delete();

        return null;
    }
}
