<?php

namespace App\GP247\Plugins\DataImport\Domain;

/**
 * What the review table says a staged row will do.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-change-review
 */
final class RowAction
{
    public const PENDING = 'pending';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const SKIP = 'skip';
    public const ERROR = 'error';
    public const CONFLICT = 'conflict';

    /** A further product row of an order: written with the order on its first row, not counted on its own. */
    public const PART = 'part';

    /** Actions that write when the run is approved. */
    public const WRITES = [self::CREATE, self::UPDATE];

    /** Every action shown on the review summary. */
    public const ALL = [self::CREATE, self::UPDATE, self::SKIP, self::ERROR, self::CONFLICT];
}
