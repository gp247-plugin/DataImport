<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Application\ImageFetcher;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Services\Catalog\CatalogWriter;
use GP247\Shop\Services\Catalog\ProductWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Simple products, identified by SKU; categories, brand and supplier are given by their
 * source keys (imported earlier, or present in the store under that alias).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities, US-data-import-image-fetch
 */
class ProductHandler extends EntityHandler
{
    /** Columns copied as they are. */
    private const SCALARS = ['sku', 'image', 'price', 'cost', 'stock', 'minimum', 'weight', 'status', 'sort', 'tags'];

    public function entity(): string
    {
        return 'product';
    }

    public function fields(): array
    {
        return array_merge(self::SCALARS, ['alias', 'categories', 'brand', 'supplier', 'images']);
    }

    public function textFields(): array
    {
        return ProductWriter::TEXT_FIELDS;
    }

    /** @return string Products have their own SEO values in the base. */
    public function seoType(): ?string
    {
        return 'product';
    }

    /**
     * The product itself, for a row that only changes SEO values.
     *
     * @param string $store
     * @param string $id
     * @return Model|null
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-seo-meta
     */
    public function find(string $store, string $id): ?Model
    {
        return ShopProduct::where('store_id', $store)->find($id);
    }

    public function naturalKeyField(): string
    {
        return 'sku';
    }

    public function codeFields(): array
    {
        return ['sku', 'price', 'cost', 'stock', 'minimum', 'weight', 'sort'];
    }

    public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array
    {
        $flat = [];
        $errors = [];
        foreach (self::SCALARS as $field) {
            if (array_key_exists($field, $values)) {
                $flat[$field] = trim((string) $values[$field]);
            }
        }
        $flat = $this->texts($values, $flat, $job, $creating);
        if (array_key_exists('images', $values)) {
            $images = array_map(fn ($item) => $this->imageRef($item), $this->imageList((string) $values['images']));
            $flat['image'] = (string) ($images[0] ?? '');
            $flat['sub_images'] = implode('|', array_slice($images, 1));
        }
        if ($creating || array_key_exists('alias', $values)) {
            $flat['alias'] = CatalogWriter::alias(trim((string) ($values['alias'] ?? '')), (string) ($flat['name:' . ($this->languages()[0] ?? 'en')] ?? ''));
        }
        if (array_key_exists('categories', $values)) {
            $ids = [];
            foreach (preg_split(self::LIST_SEPARATORS, (string) $values['categories']) as $key) {
                $key = trim($key);
                if ($key === '') {
                    continue;
                }
                $id = $this->reference($job, new CategoryHandler, $key);
                if ($id === null) {
                    $errors['categories'][] = self::message('error.unknown_reference', ['value' => $key]);
                } else {
                    $ids[$id] = $id;
                }
            }
            sort($ids);
            $flat['categories'] = implode(',', $ids);
        }
        foreach (['brand' => [new BrandHandler, 'brand_id'], 'supplier' => [new SupplierHandler, 'supplier_id']] as $field => [$handler, $column]) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $key = trim((string) $values[$field]);
            $id = $key === '' ? '' : $this->reference($job, $handler, $key);
            if ($id === null) {
                $errors[$field] = [self::message('error.unknown_reference', ['value' => $key])];
            } else {
                $flat[$column] = $id;
            }
        }

        return [$flat, $errors];
    }

    public function naturalMatches(string $store, array $flat): array
    {
        $sku = (string) ($flat['sku'] ?? '');
        if ($sku === '') {
            return [];
        }

        return self::exact(ShopProduct::where('store_id', $store)->where('sku', $sku)->get(['id', 'sku']), 'sku', $sku);
    }

    public function snapshot(string $store, string $id, array $keys): ?array
    {
        $product = ShopProduct::where('store_id', $store)->find($id);
        if ($product === null) {
            return null;
        }
        $texts = [];
        if (preg_grep('/:/', $keys)) {
            foreach (ShopProductDescription::where('product_id', $id)->get() as $row) {
                foreach ($this->textFields() as $field) {
                    $texts[$field . ':' . $row->lang] = (string) $row->{$field};
                }
            }
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match (true) {
                str_contains($key, ':') => $texts[$key] ?? '',
                $key === 'categories' => $product->categories()->pluck('category_id')->map(static fn ($v) => (string) $v)->sort()->implode(','),
                $key === 'tags' => $product->tags()->pluck('name')->implode(', '),
                $key === 'sub_images' => $product->images()->orderBy('id')->pluck('image')->implode('|'),
                in_array($key, ['price', 'cost', 'stock', 'minimum', 'weight'], true) => self::number($product->{$key}),
                default => (string) ($product->{$key} ?? ''),
            };
        }
        if ($keys !== []) {
            $out['updated_at'] = (string) $product->updated_at;
        }

        return $out;
    }

    public function payload(array $flat): array
    {
        $form = [];
        foreach (array_merge(self::SCALARS, ['alias', 'brand_id', 'supplier_id']) as $field) {
            if (array_key_exists($field, $flat)) {
                $form[$field] = $flat[$field];
            }
        }
        if (array_key_exists('categories', $flat)) {
            $form['category'] = array_values(array_filter(explode(',', $flat['categories'])));
        }
        $payload = ['form' => $form];
        if (array_key_exists('sub_images', $flat)) {
            $payload['images'] = array_values(array_filter(explode('|', $flat['sub_images']), 'strlen'));
        }
        $texts = $this->descriptions($flat);
        if ($texts !== []) {
            $payload['descriptions'] = $texts;
        }

        return $payload;
    }

    /**
     * Download the image URLs of the row (outside the write transaction); an image that fails
     * is left out and reported, the product is still written.
     *
     * @param ImportJob $job
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<int, array{0: string, 1: string}>}
     */
    public function beforeWrite(ImportJob $job, array $values): array
    {
        if (!isset($values['images']) || trim((string) $values['images']) === '') {
            return [$values, []];
        }
        $fetcher = new ImageFetcher;
        $kept = [];
        $remarks = [];
        foreach ($this->imageList((string) $values['images']) as $item) {
            if (!self::isUrl($item)) {
                $kept[] = $item;
                continue;
            }
            $result = $fetcher->fetch($item);
            if ($result['error'] === null) {
                $kept[] = (string) $result['path'];
            } else {
                $remarks[] = ['image_failed', self::message('warning.image_failed', ['url' => self::displayUrl($item), 'reason' => $result['error']])];
            }
        }
        $values['images'] = implode('|', $kept);

        return [$values, $remarks];
    }

    public function validate(WriteContext $ctx, array $payload, ?string $id): array
    {
        return (new ProductWriter)->validate($ctx, $payload, $id);
    }

    public function save(WriteContext $ctx, array $payload, ?string $id): Model
    {
        return (new ProductWriter)->save($ctx, $payload, $id);
    }

    public function delete(string $store, string $id): ?string
    {
        $product = ShopProduct::where('store_id', $store)->find($id);
        if ($product === null) {
            return null;
        }
        if (ShopOrderDetail::where('product_id', $id)->exists()) {
            return self::message('undo.kept_has_orders');
        }
        // ShopProduct::boot() removes its texts, images, attributes and links.
        $product->delete();

        return null;
    }

    /**
     * The entries of an images cell (URLs or paths, separated by | or ,), at most the configured number.
     *
     * @param string $cell
     * @return array<int, string>
     */
    private function imageList(string $cell): array
    {
        $items = array_values(array_filter(array_map('trim', preg_split(self::LIST_SEPARATORS, $cell) ?: []), 'strlen'));

        return array_slice($items, 0, max(1, (int) config('Plugins/DataImport.max_images', 10)));
    }

    /**
     * What the product will hold for an entry: the stored path of a URL downloaded before
     * (so importing the same file again changes nothing), else the entry itself.
     *
     * @param string $item
     * @return string
     */
    private function imageRef(string $item): string
    {
        return self::isUrl($item) ? ((new ImageFetcher)->known($item) ?? $item) : $item;
    }

    /**
     * A URL as the report shows it: host and path only — a query string may carry a signed token.
     *
     * @param string $url
     * @return string
     */
    private static function displayUrl(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return mb_substr((string) ($parts['host'] ?? '') . (string) ($parts['path'] ?? ''), 0, 150);
    }

    /**
     * @param string $item
     * @return bool
     */
    private static function isUrl(string $item): bool
    {
        return (bool) preg_match('#^https?://#i', $item);
    }

    /**
     * A stored number as the review shows it (no trailing zeros).
     *
     * @param mixed $value
     * @return string
     */
    private static function number($value): string
    {
        $text = (string) ($value ?? '0');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
