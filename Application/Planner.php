<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\IdMapEntry;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use GP247\Shop\Services\Catalog\WriteContext;

/**
 * Planning step: decides for every staged row whether it creates, updates, skips (nothing to
 * change), fails a rule (with the same messages as the admin screen — the shop write service
 * runs in check mode) or conflicts (several records match). Keeps the diff the review shows and
 * the fingerprint of the target record, so a change made after the review is caught at write time.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-staging-validation, US-data-import-change-review, US-data-import-conflict-guard, US-data-import-id-mapping,
 *              US-data-import-order-history
 */
final class Planner
{
    /**
     * Plan up to $limit rows, in file order.
     *
     * @param ImportJob $job
     * @param int $limit
     * @return bool True when every row is planned.
     */
    public function step(ImportJob $job, int $limit = 200): bool
    {
        $handler = Handlers::for($job->entity);
        $ctx = new WriteContext($job->store_id, WriteContext::ACTOR_IMPORT);
        $rows = StagedRow::where('job_id', $job->id)->where('action', RowAction::PENDING)->orderBy('line_no')->limit($limit)->get();

        foreach ($rows as $row) {
            $this->plan($job, $handler, $ctx, $row);
        }

        return $rows->count() < $limit;
    }

    /**
     * Plan one row.
     *
     * @param ImportJob $job
     * @param Entity\EntityHandler $handler
     * @param WriteContext $ctx
     * @param StagedRow $row
     * @return void
     */
    private function plan(ImportJob $job, Entity\EntityHandler $handler, WriteContext $ctx, StagedRow $row): void
    {
        $values = (array) $row->row_values;
        $key = (string) $row->source_key;
        if ($key === '') {
            $this->mark($row, RowAction::ERROR, errors: ['key' => [self::message('error.no_key')]]);

            return;
        }
        if ($handler->groupsRows()) {
            $head = RowGroup::earlierLine($job, $row);
            if ($head !== null) {
                $this->mark($row, RowAction::PART, warnings: ['part_of' => self::message('review.part_of', ['line' => (string) $head])]);

                return;
            }
            $group = RowGroup::values($job, $handler, $row);
            // WHY: the fingerprint of the review must cover every product row of the order.
            $row->content_hash = $group['hash'];
            if ($group['too_many']) {
                $this->mark($row, RowAction::ERROR, errors: ['key' => [self::message('error.too_many_lines', ['max' => (string) RowGroup::MAX_ROWS])]]);

                return;
            }
            $values = $group['values'];
        } elseif ($this->duplicateInFile($job, $row)) {
            $this->mark($row, RowAction::ERROR, errors: ['key' => [self::message('error.duplicate_key', ['value' => $key])]]);

            return;
        }

        $targetId = $this->target($job, $handler, $key, $values);
        if ($targetId === false) {
            $this->mark($row, RowAction::CONFLICT, errors: ['key' => [self::message('error.several_matches', ['value' => $key])]]);

            return;
        }
        if ($targetId !== null && !$handler->updatable()) {
            $this->mark($row, RowAction::SKIP, $targetId);

            return;
        }
        [$flat, $errors] = $handler->resolve($values, $job, $targetId === null, $this->earlierKeys($job, $row));
        if ($errors !== []) {
            $this->mark($row, RowAction::ERROR, errors: $errors);

            return;
        }

        $diff = [];
        $version = null;
        $action = RowAction::CREATE;
        if ($targetId !== null) {
            $current = (array) $handler->snapshot($job->store_id, $targetId, array_keys($flat));
            foreach ($flat as $field => $value) {
                if ((string) ($current[$field] ?? '') !== (string) $value) {
                    $diff[$field] = ['before' => $current[$field] ?? '', 'after' => $value];
                }
            }
            if ($diff === [] || !$job->option('update_existing', true)) {
                $this->mark($row, RowAction::SKIP, $targetId);

                return;
            }
            $action = RowAction::UPDATE;
            // Only the changed fields are written; the fingerprint covers exactly those
            // (the write step recomputes it over the same keys).
            $flat = array_intersect_key($flat, $diff);
            $version = $handler->version($job->store_id, $targetId, array_keys($flat));
        } else {
            foreach ($flat as $field => $value) {
                $diff[$field] = ['before' => null, 'after' => $value];
            }
        }

        $errors = $handler->validate($ctx, $handler->payload($flat), $targetId);
        if ($errors !== []) {
            $this->mark($row, RowAction::ERROR, $targetId, errors: $errors);

            return;
        }
        $this->mark($row, $action, $targetId, $version, self::withoutSecrets($diff), warnings: $handler->warnings($flat, $job) ?: null);
    }

    /**
     * The record the row targets: from the id map, else by natural key.
     *
     * @param ImportJob $job
     * @param Entity\EntityHandler $handler
     * @param string $key
     * @param array<string, string> $values
     * @return string|null|false Id, null for a new record, false when several records match.
     */
    private function target(ImportJob $job, Entity\EntityHandler $handler, string $key, array $values)
    {
        $entry = IdMapEntry::findByHash(IdMapEntry::keyHash($job->store_id, $job->source, $job->entity, $key));
        if ($entry !== null && $handler->snapshot($job->store_id, (string) $entry->target_id, []) !== null) {
            return (string) $entry->target_id;
        }
        [$natural] = $handler->resolve(array_intersect_key($values, array_flip([$handler->naturalKeyField(), 'name', 'alias'])), $job, true);
        $natural += [$handler->naturalKeyField() => $key];
        $matches = $handler->naturalMatches($job->store_id, $natural);
        if (count($matches) > 1) {
            return false;
        }

        return $matches[0] ?? null;
    }

    /**
     * Whether an earlier row of the run has the same source key (byte for byte).
     *
     * @param ImportJob $job
     * @param StagedRow $row
     * @return bool
     */
    private function duplicateInFile(ImportJob $job, StagedRow $row): bool
    {
        return StagedRow::where('job_id', $job->id)->where('line_no', '<', $row->line_no)->where('source_key', $row->source_key)
            ->pluck('source_key')->contains(static fn ($k) => hash_equals((string) $k, (string) $row->source_key));
    }

    /**
     * Source keys of the earlier rows of the run (a child may name a parent written just before it).
     *
     * @param ImportJob $job
     * @param StagedRow $row
     * @return array<int, string>
     */
    private function earlierKeys(ImportJob $job, StagedRow $row): array
    {
        $values = (array) $row->row_values;
        if (!isset($values['parent']) || $values['parent'] === '') {
            return [];
        }

        return StagedRow::where('job_id', $job->id)->where('line_no', '<', $row->line_no)->where('source_key', $values['parent'])
            ->whereIn('action', RowAction::WRITES)->pluck('source_key')
            ->filter(static fn ($k) => hash_equals((string) $k, (string) $values['parent']))->values()->all();
    }

    /**
     * Save the planned action of a row.
     *
     * @param StagedRow $row
     * @param string $action
     * @param string|null $targetId
     * @param string|null $version
     * @param array<string, mixed>|null $diff
     * @param array<string, array<int, string>>|null $errors
     * @param array<string, string>|null $warnings Remarks that do not stop the row.
     * @return void
     */
    private function mark(StagedRow $row, string $action, ?string $targetId = null, ?string $version = null, ?array $diff = null, ?array $errors = null, ?array $warnings = null): void
    {
        $row->forceFill([
            'action' => $action, 'target_id' => $targetId, 'target_version' => $version, 'diff' => $diff, 'errors' => $errors, 'warnings' => $warnings,
        ])->save();
    }

    /**
     * The diff without the password hash (never shown, never kept).
     *
     * @param array<string, mixed> $diff
     * @return array<string, mixed>
     */
    private static function withoutSecrets(array $diff): array
    {
        if (isset($diff['password_hash'])) {
            $diff['password_hash'] = ['before' => '', 'after' => '••••'];
        }

        return $diff;
    }

    /**
     * @param string $key
     * @param array<string, string> $replace
     * @return string
     */
    private static function message(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/DataImport::lang.' . $key, $replace);
    }
}
