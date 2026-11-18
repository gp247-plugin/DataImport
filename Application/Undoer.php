<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Application\Seo\SeoCarryover;
use App\GP247\Plugins\DataImport\Models\IdMapEntry;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\UndoRecord;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Services\ShopEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo step: walks a run's undo log backwards. A record the run created is removed unless
 * something outside the run now uses it (kept, with the reason); a record the run changed gets
 * its previous values back unless it was changed again after the import (kept) — SEO values
 * included; a 301 the run wrote from an old address is removed unless it was changed since.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-undo, US-data-import-legacy-url-redirect, US-data-import-seo-meta
 */
final class Undoer
{
    /**
     * Undo up to $limit records.
     *
     * @param ImportJob $job
     * @param int $limit
     * @return bool True when the log is done.
     */
    public function step(ImportJob $job, int $limit = 100): bool
    {
        $work = function () use ($job, $limit): bool {
            $handler = Handlers::for($job->entity);
            $ctx = new WriteContext($job->store_id, WriteContext::ACTOR_IMPORT);
            $records = UndoRecord::where('job_id', $job->id)->where('status', UndoRecord::STATUS_PENDING)->orderByDesc('id')->limit($limit)->get();
            foreach ($records as $record) {
                $this->undo($job, $handler, $ctx, $record);
            }

            return $records->count() < $limit;
        };

        return $job->option('emit_events', false) ? $work() : ShopEvents::muted($work);
    }

    /**
     * Undo one record.
     *
     * @param ImportJob $job
     * @param Entity\EntityHandler $handler
     * @param WriteContext $ctx
     * @param UndoRecord $record
     * @return void
     */
    private function undo(ImportJob $job, Entity\EntityHandler $handler, WriteContext $ctx, UndoRecord $record): void
    {
        try {
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $handler, $ctx, $record): void {
                if ($record->kind === UndoRecord::KIND_REDIRECT) {
                    $since = $job->approved_at ?? $job->created_at;
                    if (!SeoCarryover::removeRedirect((int) $record->target_id, (string) (((array) $record->before)['to'] ?? ''), $since)) {
                        $record->forceFill(['status' => UndoRecord::STATUS_KEPT, 'note' => (string) trans('Plugins/DataImport::lang.undo.kept_redirect')])->save();

                        return;
                    }
                } elseif ($record->kind === UndoRecord::KIND_CREATED) {
                    $keys = $handler->createdVersionKeys();
                    if ($keys !== null && $record->after_version !== null
                        && $handler->version($job->store_id, (string) $record->target_id, $keys) !== $record->after_version) {
                        $record->forceFill(['status' => UndoRecord::STATUS_KEPT, 'note' => (string) trans('Plugins/DataImport::lang.undo.kept_changed')])->save();

                        return;
                    }
                    $kept = $handler->delete($job->store_id, (string) $record->target_id);
                    if ($kept !== null) {
                        $record->forceFill(['status' => UndoRecord::STATUS_KEPT, 'note' => mb_substr($kept, 0, 255)])->save();

                        return;
                    }
                    if ($record->map_key_hash !== null) {
                        IdMapEntry::where('key_hash', $record->map_key_hash)->where('target_id', $record->target_id)->delete();
                    }
                } else {
                    $before = (array) $record->before;
                    if ($handler->version($job->store_id, (string) $record->target_id, array_keys($before)) !== $record->after_version) {
                        $record->forceFill(['status' => UndoRecord::STATUS_KEPT, 'note' => (string) trans('Plugins/DataImport::lang.undo.kept_changed')])->save();

                        return;
                    }
                    $handler->write($ctx, $handler->writePayload($before), (string) $record->target_id);
                }
                $record->forceFill(['status' => UndoRecord::STATUS_DONE])->save();
            });
        } catch (ValidationException $e) {
            $record->forceFill(['status' => UndoRecord::STATUS_KEPT, 'note' => mb_substr(implode(' ', array_merge(...array_values($e->errors()))), 0, 255)])->save();
        }
    }
}
