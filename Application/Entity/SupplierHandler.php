<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use GP247\Shop\Models\ShopSupplier;
use GP247\Shop\Services\Catalog\NamedRecordWriter;
use GP247\Shop\Services\Catalog\SupplierWriter;

/**
 * Suppliers.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities
 */
class SupplierHandler extends NamedRecordHandler
{
    public function entity(): string
    {
        return 'supplier';
    }

    public function fields(): array
    {
        return ['name', 'alias', 'image', 'url', 'email', 'phone', 'address', 'sort'];
    }

    protected function modelClass(): string
    {
        return ShopSupplier::class;
    }

    protected function writer(): NamedRecordWriter
    {
        return new SupplierWriter;
    }

    protected function productColumn(): string
    {
        return 'supplier_id';
    }
}
