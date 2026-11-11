<?php

namespace App\GP247\Plugins\DataImport\Application;

/**
 * A run cannot be created or moved on. The message is shown in the site language; the key
 * (under `Plugins/DataImport::lang.`) is kept for callers that branch on it.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source, US-data-import-change-review
 */
final class ImportRejected extends \RuntimeException
{
    /**
     * @param string $key Language key, e.g. 'error.plan_changed'.
     * @param array<string, string> $replace
     */
    public function __construct(public readonly string $key, array $replace = [])
    {
        parent::__construct((string) trans('Plugins/DataImport::lang.' . $key, $replace));
    }
}
