<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Application\Entity\OrderHandler;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Domain\SourceEncoding;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\WriteActivity;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopPaymentStatus;
use GP247\Shop\Models\ShopShippingStatus;

/**
 * The run's life cycle as the admin screen and the CLI drive it: create a run from a file
 * (checked, copied into the run's folder, character set detected, header read), approve the
 * reviewed plan (fingerprint, pre-change snapshot, no other bulk write running), cancel,
 * retry a stalled run, undo a written run.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source, US-data-import-encoding-detection, US-data-import-column-mapping,
 *              US-data-import-change-review, US-data-import-pre-change-snapshot, US-data-import-undo, US-data-import-order-status-mapping
 * @aidlc-adr data-import_staged-review-commit
 */
final class ImportService
{
    /** Distinct values listed per status column on the mapping step. */
    private const MAX_STATUS_VALUES = 200;

    /**
     * Create a run from a CSV file the caller already holds (an upload or a CLI path) and set its
     * column mapping in one go: open() + configure().
     *
     * @param string $storeId Target store.
     * @param string $entity One of Handlers::entities().
     * @param string $source Name of the source system (key of the id map), e.g. "woocommerce".
     * @param string $path File to import (copied; the original is not touched).
     * @param array<string, string> $columns Source header => target field; empty = headers that equal a target name.
     * @param array<string, mixed> $options update_existing, emit_events, language, statuses (orders).
     * @param string|null $createdBy Admin id.
     * @param string|null $encoding Override of the detected character set.
     * @param string $delimiter
     * @param string|null $originalName Name shown to the user (cleaned).
     * @return ImportJob
     * @throws ImportRejected When the file or the settings cannot be used (no run is left behind).
     */
    public function create(string $storeId, string $entity, string $source, string $path, array $columns = [], array $options = [],
        ?string $createdBy = null, ?string $encoding = null, string $delimiter = ',', ?string $originalName = null): ImportJob
    {
        $job = $this->open($storeId, $entity, $source, $path, $createdBy, $encoding, $delimiter, $originalName);
        try {
            $this->configure($job, $columns, $options);
        } catch (\Throwable $e) {
            JobFiles::purge($job->id);
            $job->delete();
            throw $e;
        }

        return $job;
    }

    /**
     * Create a run from a file: checked, copied into the run's folder, character set detected,
     * header read. The run waits for its column mapping (phase uploaded).
     *
     * @param string $storeId
     * @param string $entity
     * @param string $source
     * @param string $path
     * @param string|null $createdBy
     * @param string|null $encoding
     * @param string $delimiter
     * @param string|null $originalName
     * @return ImportJob
     * @throws ImportRejected
     */
    public function open(string $storeId, string $entity, string $source, string $path, ?string $createdBy = null,
        ?string $encoding = null, string $delimiter = ',', ?string $originalName = null): ImportJob
    {
        $job = $this->newJob($storeId, $entity, $source, $originalName ?? basename($path), $createdBy);
        $size = is_file($path) ? (int) filesize($path) : -1;
        if ($size <= 0 || $size > self::maxBytes()) {
            throw new ImportRejected('error.file_size');
        }
        if (!@copy($path, JobFiles::source($job))) {
            JobFiles::purge($job->id);
            throw new ImportRejected('error.file_unreadable');
        }
        try {
            $this->prepare($job, $encoding, $delimiter);
        } catch (\Throwable $e) {
            JobFiles::purge($job->id);
            throw $e;
        }

        return $job;
    }

    /**
     * A run not saved yet, with its target checked (used by open() and the chunked upload).
     *
     * @param string $storeId
     * @param string $entity
     * @param string $source
     * @param string $originalName
     * @param string|null $createdBy
     * @return ImportJob
     * @throws ImportRejected
     */
    public function newJob(string $storeId, string $entity, string $source, string $originalName, ?string $createdBy): ImportJob
    {
        if (!in_array($entity, Handlers::entities(), true)) {
            throw new ImportRejected('error.unknown_entity');
        }
        $source = trim($source);
        if (!preg_match('/^[A-Za-z0-9 _.\-]{1,100}$/', $source)) {
            throw new ImportRejected('error.bad_source_name');
        }
        // WHY: only the base name is shown, with anything but letters, digits, spaces, dots, dashes and underscores replaced.
        $name = basename(str_replace('\\', '/', $originalName));

        return new ImportJob([
            'id' => ImportJob::newId(),
            'store_id' => $storeId,
            'entity' => $entity,
            'source' => $source,
            'phase' => JobPhase::UPLOADING,
            'mapping' => [],
            'options' => [],
            'cursor' => [],
            'counts' => [],
            'original_name' => mb_substr((string) preg_replace('/[^\pL\pN _.\-]/u', '_', $name), 0, 255),
            'purge_after' => now()->addDays((int) config('Plugins/DataImport.purge_days', 7)),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Check the run's source file (text, character set, header) and save the run as uploaded.
     *
     * @param ImportJob $job Its source file is in place.
     * @param string|null $encoding Override of the detected character set.
     * @param string $delimiter
     * @return void
     * @throws ImportRejected
     */
    public function prepare(ImportJob $job, ?string $encoding = null, string $delimiter = ','): void
    {
        if (!in_array($delimiter, SourceEncoding::DELIMITERS, true)) {
            throw new ImportRejected('error.bad_delimiter');
        }
        $path = JobFiles::source($job);
        [$detected, $bom] = $this->inspect($path);
        $encoding ??= $detected;
        if ($encoding === null || !SourceEncoding::allowed($encoding)) {
            throw new ImportRejected('error.unknown_encoding');
        }
        $header = (new CsvStreamReader($path, $encoding, $delimiter, $bom))->next();
        if ($header === null || $header['error'] !== null || array_filter($header['cells'], 'strlen') === []) {
            throw new ImportRejected('error.no_header');
        }

        $job->forceFill([
            'phase' => JobPhase::UPLOADED,
            'encoding' => $encoding,
            'delimiter' => $delimiter,
            'mapping' => ['headers' => array_map(static fn ($h) => trim((string) $h), $header['cells'])],
            'cursor' => ['byte' => $header['end'], 'line' => 1],
            'file_size' => (int) filesize($path),
            'file_sha256' => hash_file('sha256', $path),
        ])->save();
    }

    /**
     * The first records after the header, decoded with the run's character set (preview).
     *
     * @param ImportJob $job
     * @param int $limit
     * @return array<int, array<int, string>> Cells per record (an unreadable record is empty).
     */
    public function preview(ImportJob $job, int $limit = 20): array
    {
        $reader = new CsvStreamReader(JobFiles::source($job), (string) $job->encoding, (string) $job->delimiter, (int) (((array) $job->cursor)['byte'] ?? 0));
        $rows = [];
        while (count($rows) < $limit && ($record = $reader->next()) !== null) {
            $rows[] = $record['error'] === null ? $record['cells'] : [];
        }

        return $rows;
    }

    /**
     * Set the column mapping and options of an uploaded run (optionally with another character
     * set or separator) and start reading the file.
     *
     * @param ImportJob $job
     * @param array<string, string> $columns Source header => target field; empty = headers that equal a target name.
     * @param array<string, mixed> $options update_existing, emit_events, language, statuses (orders).
     * @param string|null $encoding
     * @param string|null $delimiter
     * @return void
     * @throws ImportRejected When a field is unknown or a required field is not mapped.
     */
    public function configure(ImportJob $job, array $columns, array $options = [], ?string $encoding = null, ?string $delimiter = null): void
    {
        if ($job->phase !== JobPhase::UPLOADED) {
            throw new ImportRejected('error.not_uploaded');
        }
        if (($encoding !== null && $encoding !== $job->encoding) || ($delimiter !== null && $delimiter !== $job->delimiter)) {
            $this->prepare($job, $encoding ?? (string) $job->encoding, $delimiter ?? (string) $job->delimiter);
        }
        $headers = (array) (((array) $job->mapping)['headers'] ?? []);
        $mapping = ['headers' => $headers, 'columns' => $this->columns($job->entity, $headers, $columns)];
        if (isset($options['statuses']) && is_array($options['statuses'])) {
            $mapping['statuses'] = $this->statusMap($options['statuses']);
        }
        $job->forceFill([
            'phase' => JobPhase::STAGING,
            'mapping' => $mapping,
            'options' => array_merge(['update_existing' => true, 'emit_events' => false], array_intersect_key($options, array_flip(['update_existing', 'emit_events', 'language']))),
            'attempts' => 0,
        ])->save();
    }

    /**
     * Every value of the status columns of a staged order run, with the number of orders that
     * have it (the first row of each order number counts) — what the mapping step lists.
     *
     * @param ImportJob $job
     * @return array<string, array<string, int>> kind => value => orders
     */
    public function statusValues(ImportJob $job): array
    {
        $values = array_fill_keys(array_keys(OrderHandler::STATUS_KINDS), []);
        $seen = [];
        StagedRow::where('job_id', $job->id)->where('action', RowAction::PENDING)->orderBy('line_no')
            ->select(['id', 'line_no', 'source_key', 'row_values'])
            ->chunk(1000, function ($rows) use (&$values, &$seen): void {
                foreach ($rows as $row) {
                    $key = sha1((string) $row->source_key);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    foreach (array_keys($values) as $kind) {
                        $value = trim((string) (((array) $row->row_values)[$kind] ?? ''));
                        if (isset($values[$kind][$value]) || count($values[$kind]) < self::MAX_STATUS_VALUES) {
                            $values[$kind][$value] = ($values[$kind][$value] ?? 0) + 1;
                        }
                    }
                }
            });

        return $values;
    }

    /**
     * Save the status mapping of a staged order run and plan it.
     *
     * @param ImportJob $job
     * @param array<string, array<string, int|string|null>> $statuses kind => source value => GP247 status id
     * @return void
     * @throws ImportRejected When the run does not wait for it or an id is not a GP247 status.
     */
    public function mapStatuses(ImportJob $job, array $statuses): void
    {
        if ($job->phase !== JobPhase::STAGED || $job->entity !== 'order') {
            throw new ImportRejected('error.not_mapping_step');
        }
        $mapping = (array) $job->mapping;
        $mapping['statuses'] = $this->statusMap($statuses);
        $job->forceFill(['mapping' => $mapping, 'phase' => JobPhase::PLANNING, 'attempts' => 0])->save();
    }

    /**
     * Approve the plan the user reviewed and start writing.
     *
     * @param ImportJob $job
     * @param string $fingerprint Fingerprint shown on the review (from the screen; checked, not trusted).
     * @param string|null $approvedBy Admin id.
     * @param bool $ignorePreChange Go on although a pre-change check asked to stop (the user confirmed).
     * @return void
     * @throws ImportRejected When the plan changed, a pre-change check stops it or another bulk write runs.
     */
    public function approve(ImportJob $job, string $fingerprint, ?string $approvedBy = null, bool $ignorePreChange = false): void
    {
        if ($job->phase !== JobPhase::REVIEWED) {
            throw new ImportRejected('error.not_reviewed');
        }
        $current = (new Review)->fingerprint($job);
        if (!hash_equals($current, (string) $job->review_fingerprint) || !hash_equals($current, $fingerprint)) {
            throw new ImportRejected('error.plan_changed');
        }
        $this->guardWrite($job, $ignorePreChange);
        $job->forceFill(['phase' => JobPhase::COMMITTING, 'approved_by' => $approvedBy, 'approved_at' => now(), 'cursor' => [], 'attempts' => 0])->save();
    }

    /**
     * Undo a written run (within its undo period).
     *
     * @param ImportJob $job
     * @param bool $ignorePreChange
     * @return void
     * @throws ImportRejected
     */
    public function undo(ImportJob $job, bool $ignorePreChange = false): void
    {
        if ($job->phase !== JobPhase::COMMITTED || $job->undo_until === null || $job->undo_until->isPast()) {
            throw new ImportRejected('error.cannot_undo');
        }
        $this->guardWrite($job, $ignorePreChange);
        $job->forceFill(['phase' => JobPhase::UNDOING, 'cursor' => [], 'attempts' => 0])->save();
    }

    /**
     * Cancel a run that has written nothing; its staged data and files are removed.
     *
     * @param ImportJob $job
     * @return void
     * @throws ImportRejected
     */
    public function cancel(ImportJob $job): void
    {
        if (!in_array($job->phase, JobPhase::CANCELLABLE, true) || self::stalledWhileWriting($job)) {
            throw new ImportRejected('error.cannot_cancel');
        }
        $job->forceFill(['phase' => JobPhase::CANCELLED])->save();
        StagedRow::where('job_id', $job->id)->delete();
        JobFiles::purge($job->id);
    }

    /**
     * Whether the run stalled while writing or undoing: it has written shop data, so cancelling
     * it would drop its staged rows and leave what it wrote without an undo.
     *
     * @param ImportJob $job
     * @return bool
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review, US-data-import-undo
     */
    public static function stalledWhileWriting(ImportJob $job): bool
    {
        return $job->phase === JobPhase::STALLED
            && in_array((string) (((array) $job->cursor)['stalled_from'] ?? ''), [JobPhase::COMMITTING, JobPhase::UNDOING], true);
    }

    /**
     * Resume a stalled run in the phase it stalled in.
     *
     * @param ImportJob $job
     * @return void
     */
    public function retry(ImportJob $job): void
    {
        if ($job->phase !== JobPhase::STALLED) {
            return;
        }
        $cursor = (array) $job->cursor;
        $phase = (string) ($cursor['stalled_from'] ?? JobPhase::STAGING);
        unset($cursor['stalled_from']);
        $job->forceFill(['phase' => $phase, 'cursor' => $cursor, 'attempts' => 0, 'last_error' => null])->save();
    }

    /**
     * Before writing: the core's pre-change check (a backup registrant snapshots the data the
     * run touches) and no other bulk write (backup, restore) running.
     *
     * @param ImportJob $job
     * @param bool $ignorePreChange
     * @return void
     * @throws ImportRejected
     */
    private function guardWrite(ImportJob $job, bool $ignorePreChange): void
    {
        $other = WriteActivity::busy(SliceRunner::KEY);
        if ($other !== null) {
            throw new ImportRejected('error.other_write_running', ['name' => $other['label']]);
        }
        $outcome = gp247_pre_change(PreChange::DATA_IMPORT, [
            'groups' => Handlers::backupGroups($job->entity), 'source' => $job->source, 'job' => $job->id,
        ]);
        if (!$outcome->proceed() && !$ignorePreChange) {
            throw new ImportRejected('error.pre_change_stopped', ['reasons' => implode('; ', array_map('strval', $outcome->reasons()))]);
        }
    }

    /**
     * A status mapping limited to known kinds and to statuses GP247 has.
     *
     * @param array<string, mixed> $statuses
     * @return array<string, array<string, int>>
     * @throws ImportRejected
     */
    private function statusMap(array $statuses): array
    {
        $known = [
            'status' => ShopOrderStatus::getIdAll(),
            'shipping_status' => ShopShippingStatus::getIdAll(),
            'payment_status' => ShopPaymentStatus::getIdAll(),
        ];
        $out = [];
        foreach ($known as $kind => $ids) {
            foreach ((array) ($statuses[$kind] ?? []) as $value => $id) {
                if ($id === null || $id === '') {
                    continue;
                }
                if (!array_key_exists((int) $id, $ids)) {
                    throw new ImportRejected('error.unknown_status', ['value' => (string) $id]);
                }
                $out[$kind][mb_substr(trim((string) $value), 0, 191)] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * @return int Largest accepted file, in bytes.
     */
    public static function maxBytes(): int
    {
        return (int) config('Plugins/DataImport.max_file_bytes', 104857600);
    }

    /**
     * Detect the character set and refuse files that are not text.
     *
     * @param string $path
     * @return array{0: string|null, 1: int} [encoding, BOM length]
     * @throws ImportRejected
     */
    private function inspect(string $path): array
    {
        $sample = (string) file_get_contents($path, false, null, 0, EncodingDetector::SAMPLE_BYTES);
        if (str_contains($sample, "\0") && !str_starts_with($sample, "\xFF\xFE") && !str_starts_with($sample, "\xFE\xFF")) {
            throw new ImportRejected('error.not_text');
        }
        $control = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sample);
        if ($sample !== '' && $control / strlen($sample) > 0.01) {
            throw new ImportRejected('error.not_text');
        }
        $detected = (new EncodingDetector)->detect($sample);
        if ($detected['error'] === 'utf16') {
            throw new ImportRejected('error.utf16');
        }

        return [$detected['encoding'], $detected['bom']];
    }

    /**
     * The column mapping, limited to real target fields of the entity.
     *
     * @param string $entity
     * @param array<int, string> $headers
     * @param array<string, string> $columns
     * @return array<string, string>
     * @throws ImportRejected On a mapping to an unknown field.
     */
    private function columns(string $entity, array $headers, array $columns): array
    {
        $targets = Handlers::for($entity)->targets();
        if ($columns === []) {
            foreach ($headers as $header) {
                if (in_array(strtolower($header), $targets, true)) {
                    $columns[$header] = strtolower($header);
                }
            }
        }
        foreach ($columns as $header => $field) {
            if (!in_array($field, $targets, true)) {
                throw new ImportRejected('error.unknown_field', ['value' => (string) $field]);
            }
            if (!in_array((string) $header, $headers, true)) {
                unset($columns[$header]);
            }
        }
        $mapped = array_map(static fn ($f) => explode(':', (string) $f)[0], array_values($columns));
        foreach (Handlers::for($entity)->requiredFields() as $alternatives) {
            if (array_intersect($alternatives, $mapped) === []) {
                throw new ImportRejected('error.required_unmapped', ['fields' => implode(' / ', $alternatives)]);
            }
        }

        return $columns;
    }
}
