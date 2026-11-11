<?php

namespace App\GP247\Plugins\DataImport\Application;

/**
 * A row that must not be written because its target changed since the review (or was created
 * by someone else meanwhile). The message is a language key under `error.`.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-conflict-guard
 */
final class RowConflict extends \RuntimeException
{
}
