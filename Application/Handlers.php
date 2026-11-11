<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Application\Entity\BrandHandler;
use App\GP247\Plugins\DataImport\Application\Entity\CategoryHandler;
use App\GP247\Plugins\DataImport\Application\Entity\CustomerHandler;
use App\GP247\Plugins\DataImport\Application\Entity\EntityHandler;
use App\GP247\Plugins\DataImport\Application\Entity\OrderHandler;
use App\GP247\Plugins\DataImport\Application\Entity\ProductHandler;
use App\GP247\Plugins\DataImport\Application\Entity\SupplierHandler;

/**
 * The kinds of data a run can import, and the RBAC group each belongs to.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-catalog-entities, US-data-import-customers, US-data-import-order-history, US-data-import-access-control
 */
final class Handlers
{
    /** entity => [handler class, permission group]. */
    private const MAP = [
        'category' => [CategoryHandler::class, 'catalog'],
        'brand' => [BrandHandler::class, 'catalog'],
        'supplier' => [SupplierHandler::class, 'catalog'],
        'product' => [ProductHandler::class, 'catalog'],
        'customer' => [CustomerHandler::class, 'customers'],
        'order' => [OrderHandler::class, 'orders'],
    ];

    /**
     * @return array<int, string> Importable entities, in the order they should be imported.
     */
    public static function entities(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * @param string $entity
     * @return EntityHandler
     * @throws \InvalidArgumentException On an unknown entity.
     */
    public static function for(string $entity): EntityHandler
    {
        $class = self::MAP[$entity][0] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException('Unknown entity: ' . $entity);
        }

        return new $class;
    }

    /**
     * Permission group of an entity (the URI segment of its RBAC permission).
     *
     * @param string $entity
     * @return string
     */
    public static function group(string $entity): string
    {
        return self::MAP[$entity][1] ?? 'catalog';
    }

    /**
     * Data group(s) of the core backup that hold this entity (scope of the pre-change snapshot).
     *
     * @param string $entity
     * @return array<int, string>
     */
    public static function backupGroups(string $entity): array
    {
        return match (self::group($entity)) {
            'customers' => ['customers'],
            'orders' => ['orders'],
            default => ['catalog'],
        };
    }
}
