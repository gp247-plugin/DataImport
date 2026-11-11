<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Services\Catalog\CatalogWriter;
use GP247\Shop\Services\Catalog\CategoryWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories: a tree whose parent is given by the parent's source key (an earlier row of the
 * same file, or a category already imported / present in the store).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities
 */
class CategoryHandler extends EntityHandler
{
    /** Placeholder prefix of a parent written earlier in the same run. */
    public const PENDING_PREFIX = '@';

    public function entity(): string
    {
        return 'category';
    }

    public function fields(): array
    {
        return ['alias', 'image', 'parent', 'sort', 'status', 'top'];
    }

    public function textFields(): array
    {
        return CategoryWriter::TEXT_FIELDS;
    }

    public function naturalKeyField(): string
    {
        return 'alias';
    }

    public function codeFields(): array
    {
        return ['alias', 'sort'];
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

        return $key !== '' ? $key : CatalogWriter::alias('', (string) ($values['name'] ?? $values['name:' . ($this->languages()[0] ?? 'en')] ?? ''));
    }

    public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array
    {
        $flat = [];
        $errors = [];
        foreach (['image', 'sort', 'status', 'top'] as $field) {
            if (array_key_exists($field, $values)) {
                $flat[$field] = trim((string) $values[$field]);
            }
        }
        $flat = $this->texts($values, $flat, $job, $creating);
        if (array_key_exists('alias', $values) || $creating) {
            $flat['alias'] = CatalogWriter::alias(trim((string) ($values['alias'] ?? '')), (string) ($flat['name:' . ($this->languages()[0] ?? 'en')] ?? ''));
        }
        if (array_key_exists('parent', $values)) {
            $parentKey = trim((string) $values['parent']);
            if ($parentKey === '') {
                $flat['parent'] = '';
            } elseif (in_array($parentKey, $pendingKeys, true)) {
                $flat['parent'] = self::PENDING_PREFIX . $parentKey;
            } else {
                $parentId = $this->reference($job, $this, $parentKey);
                if ($parentId === null) {
                    $errors['parent'] = [self::message('error.unknown_reference', ['value' => $parentKey])];
                } else {
                    $flat['parent'] = $parentId;
                }
            }
        }

        return [$flat, $errors];
    }

    public function naturalMatches(string $store, array $flat): array
    {
        $alias = (string) ($flat['alias'] ?? '');
        if ($alias === '') {
            return [];
        }

        return self::exact(ShopCategory::where('store_id', $store)->where('alias', $alias)->get(['id', 'alias']), 'alias', $alias);
    }

    public function snapshot(string $store, string $id, array $keys): ?array
    {
        $category = ShopCategory::where('store_id', $store)->find($id);
        if ($category === null) {
            return null;
        }
        $out = [];
        $texts = [];
        foreach (ShopCategoryDescription::where('category_id', $id)->get() as $row) {
            foreach ($this->textFields() as $field) {
                $texts[$field . ':' . $row->lang] = (string) $row->{$field};
            }
        }
        foreach ($keys as $key) {
            $out[$key] = str_contains($key, ':') ? ($texts[$key] ?? '') : (string) ($category->{$key} ?? '');
        }
        if ($keys !== []) {
            $out['updated_at'] = (string) $category->updated_at;
        }

        return $out;
    }

    public function payload(array $flat): array
    {
        $form = array_intersect_key($flat, array_flip($this->fields()));
        if (str_starts_with((string) ($form['parent'] ?? ''), self::PENDING_PREFIX)) {
            // Resolved at write time, once the parent row is written.
            unset($form['parent']);
        }
        $payload = ['form' => $form];
        $texts = $this->descriptions($flat);
        if ($texts !== []) {
            $payload['descriptions'] = $texts;
        }

        return $payload;
    }

    public function validate(WriteContext $ctx, array $payload, ?string $id): array
    {
        return (new CategoryWriter)->validate($ctx, $payload, $id);
    }

    public function save(WriteContext $ctx, array $payload, ?string $id): Model
    {
        return (new CategoryWriter)->save($ctx, $payload, $id);
    }

    public function delete(string $store, string $id): ?string
    {
        $category = ShopCategory::where('store_id', $store)->find($id);
        if ($category === null) {
            return null;
        }
        if (ShopCategory::where('parent', $id)->exists()) {
            return self::message('undo.kept_has_children');
        }
        if ($category->products()->exists()) {
            return self::message('undo.kept_in_use');
        }
        $category->delete();
        if (function_exists('gp247_cache_clear')) {
            gp247_cache_clear('cache_category');
        }

        return null;
    }
}
