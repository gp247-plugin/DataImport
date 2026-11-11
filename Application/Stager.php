<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;

/**
 * Staging step: reads the next records of the file from the cursor, applies the column
 * mapping, normalises the cells and stores them as staged rows — nothing is written to shop
 * data. Unicode NFC needs ext-intl (skipped, with a notice on the run, when the host lacks it);
 * code and number cells get full-width characters folded to half-width.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-staging-validation, US-data-import-encoding-detection, US-data-import-column-mapping
 */
final class Stager
{
    /** Rows inserted per statement. */
    private const BATCH = 200;

    /**
     * Stage up to $limit records.
     *
     * @param ImportJob $job
     * @param int $limit
     * @return bool True when the end of the file was reached.
     */
    public function step(ImportJob $job, int $limit = 400): bool
    {
        $cursor = (array) $job->cursor;
        $handler = Handlers::for($job->entity);
        $mapping = (array) (($job->mapping ?? [])['columns'] ?? []);
        $headers = (array) (($job->mapping ?? [])['headers'] ?? []);
        $codes = array_flip($handler->codeFields());
        $targets = array_flip($handler->targets());
        $reader = new CsvStreamReader(JobFiles::source($job), (string) $job->encoding, (string) $job->delimiter, (int) ($cursor['byte'] ?? 0));
        $line = (int) ($cursor['line'] ?? 1);
        $batch = [];
        $done = false;

        for ($i = 0; $i < $limit; $i++) {
            $record = $reader->next();
            if ($record === null) {
                $done = true;
                break;
            }
            $line++;
            $cursor['byte'] = $record['end'];
            if ($record['error'] === null && implode('', $record['cells']) === '') {
                continue;
            }
            $batch[] = $this->row($job, $line, $record, $headers, $mapping, $targets, $codes, $handler);
            if (count($batch) >= self::BATCH) {
                StagedRow::insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            StagedRow::insert($batch);
        }
        $cursor['line'] = $line;
        $job->cursor = $cursor;
        $job->save();

        return $done;
    }

    /**
     * One staged row for the insert.
     *
     * @param ImportJob $job
     * @param int $line
     * @param array{cells: array<int, string>, end: int, error: string|null} $record
     * @param array<int, string> $headers
     * @param array<string, string> $mapping header => target field
     * @param array<string, int> $targets
     * @param array<string, int> $codes
     * @param Entity\EntityHandler $handler
     * @return array<string, mixed>
     */
    private function row(ImportJob $job, int $line, array $record, array $headers, array $mapping, array $targets, array $codes, Entity\EntityHandler $handler): array
    {
        $values = [];
        $errors = [];
        if ($record['error'] !== null) {
            $errors['_row'] = [(string) trans('Plugins/DataImport::lang.error.' . $record['error'])];
        } else {
            foreach ($record['cells'] as $index => $cell) {
                $field = $mapping[$headers[$index] ?? ''] ?? null;
                if ($field === null || !isset($targets[$field])) {
                    continue;
                }
                $values[$field] = $this->normalise($cell, isset($codes[explode(':', $field)[0]]));
            }
        }
        $json = json_encode($values, JSON_UNESCAPED_UNICODE);

        return [
            'job_id' => $job->id,
            'line_no' => $line,
            'row_values' => $json,
            'content_hash' => sha1((string) $json),
            'source_key' => mb_substr($handler->sourceKey($values), 0, 191),
            'action' => $errors === [] ? RowAction::PENDING : RowAction::ERROR,
            'errors' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
            'status' => StagedRow::STATUS_PENDING,
        ];
    }

    /**
     * NFC (when ext-intl is there) and, for code / number cells, full-width → half-width.
     *
     * @param string $cell
     * @param bool $code
     * @return string
     */
    private function normalise(string $cell, bool $code): string
    {
        if (class_exists(\Normalizer::class) && !\Normalizer::isNormalized($cell, \Normalizer::FORM_C)) {
            $cell = (string) \Normalizer::normalize($cell, \Normalizer::FORM_C);
        }
        if ($code && preg_match('/[^\x00-\x7F]/', $cell)) {
            $cell = mb_convert_kana($cell, 'as', 'UTF-8');
        }

        return trim($cell);
    }
}
