<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use GP247\Core\Backup\Application\SliceBudget;
use GP247\Core\Support\WriteActivity;
use Illuminate\Support\Str;

/**
 * Works a run forward in time-boxed slices, so every phase finishes on a shared host whose
 * request time limit is short: one slice holds a lease on the run (an atomic UPDATE — two
 * browser tabs or a tab plus the CLI never work the same run at once), does small steps until
 * the budget is spent, saves the cursor after each step and releases the lease.
 *
 * A slice that throws counts an attempt (only the exception class is kept — no cell values);
 * three failed slices in a row stall the run until the user retries. Before a writing slice the
 * core's WriteActivity is asked whether a backup / restore is running.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-shared-host-run
 * @aidlc-adr data-import_sliced-runner
 */
final class SliceRunner
{
    public const KEY = 'data-import';
    public const MAX_ATTEMPTS = 3;

    public const RAN = 'running';
    public const DONE = 'done';
    public const IDLE = 'idle';
    public const BUSY = 'busy';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';

    /**
     * Run one slice of a run.
     *
     * @param string $jobId
     * @param float|null $budget Seconds; null = the host's safe slice ({@see SliceBudget::seconds()}).
     * @return array{state: string, phase: string|null, blocked_by: string|null}
     */
    public function run(string $jobId, ?float $budget = null): array
    {
        $budget ??= SliceBudget::seconds();
        $owner = (string) Str::uuid();
        if (!$this->acquire($jobId, $owner, $budget)) {
            return ['state' => self::BUSY, 'phase' => null, 'blocked_by' => null];
        }
        $job = ImportJob::findOrFail($jobId);
        try {
            if (!array_key_exists($job->phase, JobPhase::SLICED)) {
                // Nothing to do for this run: a good moment for the lazy clean-up.
                (new Retention)->sweepIfDue();

                return ['state' => self::IDLE, 'phase' => $job->phase, 'blocked_by' => null];
            }
            if ($job->isWriting()) {
                $other = WriteActivity::busy(self::KEY);
                if ($other !== null) {
                    return ['state' => self::BLOCKED, 'phase' => $job->phase, 'blocked_by' => $other['label']];
                }
            }

            $deadline = microtime(true) + $budget;
            $done = false;
            do {
                $done = $this->step($job);
            } while (!$done && microtime(true) < $deadline);

            $job->attempts = 0;
            $job->last_error = null;
            if ($done) {
                $this->finish($job);
            }
            $job->save();

            return ['state' => $done ? self::DONE : self::RAN, 'phase' => $job->phase, 'blocked_by' => null];
        } catch (\Throwable $e) {
            $job->refresh();
            $job->attempts = (int) $job->attempts + 1;
            $job->last_error = mb_substr(get_class($e), 0, 255);
            if ($job->attempts >= self::MAX_ATTEMPTS) {
                $job->cursor = array_merge((array) $job->cursor, ['stalled_from' => $job->phase]);
                $job->phase = JobPhase::STALLED;
            }
            $job->save();
            report($e);

            return ['state' => self::FAILED, 'phase' => $job->phase, 'blocked_by' => null];
        } finally {
            ImportJob::whereKey($jobId)->where('lease_owner', $owner)->update(['lease_owner' => null, 'lease_until' => null]);
        }
    }

    /**
     * Take the lease when nobody holds a live one.
     *
     * @param string $jobId
     * @param string $owner
     * @param float $budget
     * @return bool
     */
    private function acquire(string $jobId, string $owner, float $budget): bool
    {
        $now = now();

        return ImportJob::whereKey($jobId)
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->update(['lease_owner' => $owner, 'lease_until' => $now->copy()->addSeconds((int) ceil(min($budget, 3600)) + 60)]) === 1;
    }

    /**
     * One small step of the current phase.
     *
     * @param ImportJob $job
     * @return bool True when the phase is complete.
     */
    private function step(ImportJob $job): bool
    {
        return match ($job->phase) {
            JobPhase::STAGING => (new Stager)->step($job),
            JobPhase::PLANNING => (new Planner)->step($job),
            JobPhase::COMMITTING => (new Committer)->step($job),
            JobPhase::UNDOING => (new Undoer)->step($job),
        };
    }

    /**
     * Move a finished phase on, with what the next phase needs.
     *
     * @param ImportJob $job
     * @return void
     */
    private function finish(ImportJob $job): void
    {
        $finished = $job->phase;
        $job->phase = JobPhase::SLICED[$finished];
        if ($finished !== JobPhase::UNDOING) {
            // After the write phase the staged rows are gone; keep the counts they gave.
            $job->counts = (new Review)->counts($job);
        }
        if ($finished === JobPhase::STAGING) {
            // Staged rows are planned right away (the column mapping was chosen before staging),
            // unless source values still need mapping (order statuses): then the run waits.
            $job->phase = Handlers::for($job->entity)->waitsForValueMapping($job) ? JobPhase::STAGED : JobPhase::PLANNING;
        } elseif ($finished === JobPhase::PLANNING) {
            $job->review_fingerprint = (new Review)->fingerprint($job);
        } elseif ($finished === JobPhase::COMMITTING) {
            $job->undo_until = now()->addDays((int) config('Plugins/DataImport.undo_days', 30));
            // The staged rows held personal data; the undo log and the id map are all that stay.
            StagedRow::where('job_id', $job->id)->delete();
            JobFiles::purge($job->id);
        }
    }
}
