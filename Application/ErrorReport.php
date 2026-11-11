<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;

/**
 * The error report of a reviewed run: every refused or conflicting line with its line number,
 * key, what went wrong and its source cells (under the file's own column names), so the user
 * fixes the file and imports it again. CSV UTF-8 with a BOM (Excel opens it with the right
 * characters); a cell a spreadsheet would run as a formula is neutralised with a leading
 * apostrophe. Rows are read in batches (constant memory).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-change-review
 */
final class ErrorReport
{
    /** First characters that make a spreadsheet read a cell as a formula (OWASP CSV injection). */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * File name offered for the download.
     *
     * @param ImportJob $job
     * @return string
     */
    public function fileName(ImportJob $job): string
    {
        return 'data-import-' . $job->id . '-errors.csv';
    }

    /**
     * Write the report to a stream.
     *
     * @param ImportJob $job
     * @param resource $out
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review
     */
    public function write(ImportJob $job, $out): void
    {
        $columns = (array) (((array) $job->mapping)['columns'] ?? []);
        fwrite($out, "\xEF\xBB\xBF");
        $this->put($out, array_merge(['line', 'key', 'action', 'errors'], array_map('strval', array_keys($columns))));

        StagedRow::where('job_id', $job->id)->whereIn('action', [RowAction::ERROR, RowAction::CONFLICT])->orderBy('line_no')
            ->select(['id', 'line_no', 'source_key', 'action', 'row_values', 'errors'])
            ->chunk(500, function ($rows) use ($out, $columns): void {
                foreach ($rows as $row) {
                    $values = (array) $row->row_values;
                    $cells = [(string) $row->line_no, (string) $row->source_key, (string) $row->action, $this->messages((array) $row->errors)];
                    foreach ($columns as $field) {
                        $cells[] = is_scalar($values[$field] ?? null) ? (string) $values[$field] : '';
                    }
                    $this->put($out, $cells);
                }
            });
    }

    /**
     * A cell made safe for spreadsheets.
     *
     * @param string $value
     * @return string
     */
    public static function neutralise(string $value): string
    {
        // WHY numbers pass: a signed number (-5, +84…) is not a formula, and an apostrophe would come back as data on re-import.
        if ($value === '' || !in_array($value[0], self::FORMULA_START, true) || preg_match('/^[+-]?\d+([.,]\d+)?$/', $value)) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * The errors of a row as one line: "field: message; …".
     *
     * @param array<string, mixed> $errors
     * @return string
     */
    private function messages(array $errors): string
    {
        $parts = [];
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $parts[] = ($field === '_row' ? '' : $field . ': ') . (string) $message;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @param resource $out
     * @param array<int, string> $cells
     * @return void
     */
    private function put($out, array $cells): void
    {
        fputcsv($out, array_map(self::neutralise(...), $cells), ',', '"', '', "\r\n");
    }
}
