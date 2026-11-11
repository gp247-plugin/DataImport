<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Application\Entity\EntityHandler;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;

/**
 * The values a staged row stands for. Most entities: the row itself. Orders (one file row per
 * product): the first row of an order number carries the order and gathers the product rows of
 * the same number under `_lines`; the later rows are parts of it.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-order-history
 */
final class RowGroup
{
    /** Most product rows one order may have (keeps memory bounded). */
    public const MAX_ROWS = 500;

    /**
     * Line number of an earlier row with the same source key (compared byte for byte), or null.
     *
     * @param ImportJob $job
     * @param StagedRow $row
     * @return int|null
     */
    public static function earlierLine(ImportJob $job, StagedRow $row): ?int
    {
        $line = StagedRow::where('job_id', $job->id)->where('line_no', '<', $row->line_no)->where('source_key', $row->source_key)
            ->orderBy('line_no')->get(['line_no', 'source_key'])
            ->first(static fn ($r) => hash_equals((string) $r->source_key, (string) $row->source_key));

        return $line !== null ? (int) $line->line_no : null;
    }

    /**
     * Values of $row for the handler, and the content hash that covers them.
     *
     * @param ImportJob $job
     * @param EntityHandler $handler
     * @param StagedRow $row The first row of its key when the handler groups rows.
     * @return array{values: array<string, mixed>, hash: string, too_many: bool}
     */
    public static function values(ImportJob $job, EntityHandler $handler, StagedRow $row): array
    {
        $values = (array) $row->row_values;
        if (!$handler->groupsRows()) {
            return ['values' => $values, 'hash' => (string) $row->content_hash, 'too_many' => false];
        }
        $rows = StagedRow::where('job_id', $job->id)->where('source_key', $row->source_key)->where('line_no', '>=', $row->line_no)
            ->orderBy('line_no')->limit(self::MAX_ROWS + 1)->get(['line_no', 'source_key', 'row_values', 'content_hash'])
            ->filter(static fn ($r) => hash_equals((string) $r->source_key, (string) $row->source_key))->values();
        $hash = hash_init('sha1');
        $lines = [];
        foreach ($rows as $member) {
            hash_update($hash, (string) $member->content_hash);
            $lines[] = (array) $member->row_values;
        }
        $values['_lines'] = $lines;

        return ['values' => $values, 'hash' => hash_final($hash), 'too_many' => $rows->count() > self::MAX_ROWS];
    }
}
