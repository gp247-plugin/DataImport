<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Application\Entity\CategoryHandler;
use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\IdMapEntry;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use App\GP247\Plugins\DataImport\Models\UndoRecord;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Services\ShopEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write step: each approved row is written in ONE transaction together with its id-map entry,
 * its undo record and its own "written" mark — so a run killed at any point resumes without
 * writing a record twice (the cursor is only an optimisation; the row status and the id map
 * are the truth). A record changed after the review is not overwritten: the row becomes a conflict.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-idempotent-commit, US-data-import-conflict-guard, US-data-import-id-mapping
 * @aidlc-adr data-import_id-map-exactly-once
 */
final class Committer
{
    /** Remarks of the write phase kept with their line for the report (the rest are only counted). */
    private const KEPT_REMARKS = 100;

    /**
     * Write up to $limit rows, in file order.
     *
     * @param ImportJob $job
     * @param int $limit
     * @return bool True when every approved row is done.
     */
    public function step(ImportJob $job, int $limit = 100): bool
    {
        $work = function () use ($job, $limit): bool {
            $handler = Handlers::for($job->entity);
            $ctx = new WriteContext($job->store_id, WriteContext::ACTOR_IMPORT, $job->approved_by !== null ? (string) $job->approved_by : null);
            $rows = StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)
                ->where('status', StagedRow::STATUS_PENDING)->orderBy('line_no')->limit($limit)->get();
            foreach ($rows as $row) {
                $this->writeRow($job, $handler, $ctx, $row);
            }

            return $rows->count() < $limit;
        };

        // D6: sync events stay off for an import unless the run asked for them.
        return $job->option('emit_events', false) ? $work() : ShopEvents::muted($work);
    }

    /**
     * Write one row (or mark it failed / conflicting).
     *
     * @param ImportJob $job
     * @param Entity\EntityHandler $handler
     * @param WriteContext $ctx
     * @param StagedRow $row
     * @return void
     */
    private function writeRow(ImportJob $job, Entity\EntityHandler $handler, WriteContext $ctx, StagedRow $row): void
    {
        $keyHash = IdMapEntry::keyHash($job->store_id, $job->source, $job->entity, (string) $row->source_key);
        // WHY outside the transaction: downloads (images) must not hold the row lock and the savepoint open.
        [$values, $remarks] = $handler->beforeWrite($job, RowGroup::values($job, $handler, $row)['values']);
        $this->remember($job, $row, $remarks);
        try {
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $handler, $ctx, $row, $keyHash, $values): void {
                $fresh = StagedRow::whereKey($row->id)->lockForUpdate()->first();
                if ($fresh === null || $fresh->status !== StagedRow::STATUS_PENDING) {
                    // Written by an earlier slice that died before saving its cursor.
                    return;
                }
                $entry = IdMapEntry::findByHash($keyHash, true);
                $targetId = $row->action === RowAction::UPDATE ? (string) $row->target_id : null;

                if ($targetId === null && $entry !== null && $handler->snapshot($job->store_id, (string) $entry->target_id, []) !== null) {
                    throw new RowConflict('created_meanwhile');
                }
                [$flat, $errors] = $handler->resolve($values, $job, $targetId === null);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $flat = $this->resolvePending($job, $flat);
                $before = null;
                if ($targetId !== null) {
                    $flat = array_intersect_key($flat, (array) $row->diff);
                    $keys = array_keys($flat);
                    if ($handler->version($job->store_id, $targetId, $keys) !== $row->target_version) {
                        throw new RowConflict('changed_since_review');
                    }
                    $before = $handler->snapshot($job->store_id, $targetId, $keys);
                    unset($before['updated_at'], $before['password_hash']);
                }

                $model = $handler->save($ctx, $handler->payload($flat), $targetId);
                $id = (string) $model->getKey();

                IdMapEntry::updateOrCreate(['key_hash' => $keyHash], [
                    'store_id' => $job->store_id, 'source' => $job->source, 'entity' => $job->entity,
                    'source_key' => (string) $row->source_key, 'target_id' => $id, 'content_hash' => $row->content_hash, 'job_id' => $job->id,
                ]);
                UndoRecord::create([
                    'job_id' => $job->id, 'entity' => $job->entity, 'target_id' => $id,
                    'kind' => $targetId === null ? UndoRecord::KIND_CREATED : UndoRecord::KIND_UPDATED,
                    'before' => $before, 'after_version' => $handler->version($job->store_id, $id, array_keys($before ?? $flat)),
                    'map_key_hash' => $targetId === null ? $keyHash : null, 'status' => UndoRecord::STATUS_PENDING,
                ]);
                $fresh->forceFill(['status' => StagedRow::STATUS_WRITTEN, 'target_id' => $id])->save();
            });
        } catch (ValidationException $e) {
            $row->forceFill(['status' => StagedRow::STATUS_FAILED, 'action' => RowAction::ERROR, 'errors' => $e->errors()])->save();
        } catch (RowConflict $e) {
            $row->forceFill([
                'status' => StagedRow::STATUS_FAILED, 'action' => RowAction::CONFLICT,
                'errors' => ['_row' => [(string) trans('Plugins/DataImport::lang.error.' . $e->getMessage())]],
            ])->save();
        }
    }

    /**
     * Count the remarks of a write (e.g. an image that could not be downloaded) on the run and
     * keep the first ones with their line, for the run's report.
     *
     * @param ImportJob $job
     * @param StagedRow $row
     * @param array<int, array{0: string, 1: string}> $remarks
     * @return void
     */
    private function remember(ImportJob $job, StagedRow $row, array $remarks): void
    {
        if ($remarks === []) {
            return;
        }
        $counts = (array) $job->counts;
        $cursor = (array) $job->cursor;
        $kept = (array) ($cursor['write_warnings'] ?? []);
        foreach ($remarks as [$code, $message]) {
            $counts[$code] = (int) ($counts[$code] ?? 0) + 1;
            if (count($kept) < self::KEPT_REMARKS) {
                $kept[] = ['line' => (int) $row->line_no, 'code' => $code, 'message' => mb_substr($message, 0, 255)];
            }
        }
        $cursor['write_warnings'] = $kept;
        $job->counts = $counts;
        $job->cursor = $cursor;
    }

    /**
     * Replace a parent written earlier in the same run by its new id.
     *
     * @param ImportJob $job
     * @param array<string, string> $flat
     * @return array<string, string>
     */
    private function resolvePending(ImportJob $job, array $flat): array
    {
        $parent = (string) ($flat['parent'] ?? '');
        if (str_starts_with($parent, CategoryHandler::PENDING_PREFIX)) {
            $entry = IdMapEntry::findByHash(IdMapEntry::keyHash($job->store_id, $job->source, $job->entity, substr($parent, strlen(CategoryHandler::PENDING_PREFIX))));
            if ($entry === null) {
                throw ValidationException::withMessages(['parent' => [(string) trans('Plugins/DataImport::lang.error.parent_not_written')]]);
            }
            $flat['parent'] = (string) $entry->target_id;
        }

        return $flat;
    }
}
