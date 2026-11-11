<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use App\GP247\Plugins\DataImport\Models\UndoRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lazy clean-up of personal data (no cron on a shared host): the source file, uploaded chunks
 * and staged rows of a written, cancelled or undone run; an abandoned run after its keep period
 * (cancelled first); undo data once its undo period is over. What stays is the run list and the
 * id map (source keys and GP247 ids — no cell values), so a later import never duplicates.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-data-retention
 */
final class Retention
{
    public const CACHE_KEY = 'data-import:retention-swept';

    /** Seconds between two lazy sweeps. */
    private const INTERVAL = 600;

    /** Runs handled per sweep (each sweep stays short; the next one goes on). */
    private const BATCH = 50;

    /**
     * Sweep unless a sweep ran recently.
     *
     * @return bool Whether a sweep ran.
     */
    public function sweepIfDue(): bool
    {
        if (!Cache::add(self::CACHE_KEY, 1, self::INTERVAL)) {
            return false;
        }
        $this->sweep();

        return true;
    }

    /**
     * Remove what is no longer needed.
     *
     * @return void
     */
    public function sweep(): void
    {
        $abandoned = ImportJob::whereIn('phase', JobPhase::CANCELLABLE)->where('purge_after', '<', now())
            ->whereNull('lease_until')->limit(self::BATCH)->get();
        foreach ($abandoned as $job) {
            $job->forceFill(['phase' => JobPhase::CANCELLED])->save();
            $this->purge($job);
        }

        $finished = ImportJob::whereIn('phase', JobPhase::FINISHED)->where('updated_at', '>', now()->subDays($this->lookbackDays()))
            ->limit(self::BATCH * 4)->get(['id']);
        foreach ($finished as $job) {
            $this->purge($job);
        }

        $expired = ImportJob::where('phase', JobPhase::COMMITTED)->whereNotNull('undo_until')->where('undo_until', '<', now())
            ->limit(self::BATCH)->pluck('id');
        foreach ($expired as $id) {
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($id): void {
                UndoRecord::where('job_id', $id)->delete();
                ImportJob::whereKey($id)->update(['undo_until' => null]);
            });
        }
    }

    /**
     * Staged rows, chunk records and files of a run.
     *
     * @param ImportJob $job
     * @return void
     */
    private function purge(ImportJob $job): void
    {
        StagedRow::where('job_id', $job->id)->delete();
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . \App\GP247\Plugins\DataImport\Models\ExtensionModel::TABLE_CHUNK)
            ->where('job_id', $job->id)->delete();
        JobFiles::purge((string) $job->id);
    }

    /**
     * How far back finished runs are re-checked for leftovers (a crash between the last write
     * and the clean-up); older ones were checked by earlier sweeps.
     *
     * @return int
     */
    private function lookbackDays(): int
    {
        return max(1, (int) config('Plugins/DataImport.purge_days', 7)) + 1;
    }
}
