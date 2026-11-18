<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Services\Catalog\BrandWriter;
use GP247\Shop\Services\Catalog\NamedRecordWriter;

/**
 * Brands.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities
 */
class BrandHandler extends NamedRecordHandler
{
    public function entity(): string
    {
        return 'brand';
    }

    public function fields(): array
    {
        return ['name', 'alias', 'image', 'url', 'sort', 'status'];
    }

    /** @return string Brands have their own SEO values in the base. */
    public function seoType(): ?string
    {
        return 'brand';
    }

    protected function modelClass(): string
    {
        return ShopBrand::class;
    }

    protected function writer(): NamedRecordWriter
    {
        return new BrandWriter;
    }

    protected function productColumn(): string
    {
        return 'brand_id';
    }
}
