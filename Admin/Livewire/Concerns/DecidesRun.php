<?php

namespace App\GP247\Plugins\DataImport\Admin\Livewire\Concerns;

use App\GP247\Plugins\DataImport\Application\ErrorReport;
use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\ImportService;
use App\GP247\Plugins\DataImport\Application\Review;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use App\GP247\Plugins\DataImport\Support\Access;
use App\GP247\Plugins\DataImport\Support\AuditLog;
use Livewire\Attributes\Locked;

/**
 * The decisions on a planned run, from the review: look at the rows (filter, search, a row's
 * before → after), download the error report, approve and write the plan the screen showed
 * (error rows skipped only when the user says so), cancel, resume a stalled run, undo.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-change-review, US-data-import-undo, US-data-import-access-control
 * @aidlc-adr data-import_staged-review-commit
 */
trait DecidesRun
{
    /** Review table filter: one of RowAction::ALL, or '' for every row. */
    public string $filter = '';

    /**
     * Review table search on the source key.
     * WHY this name: it holds values of the file (an e-mail…); its name is in the core's
     * admin_log_except list, so the operation log never records it.
     */
    public string $searchSourceKey = '';

    /** Write the valid rows and skip the refused / conflicting ones (off by default). */
    public bool $skipInvalid = false;

    /** Send the shop's sync events while writing (off by default for an import). */
    public bool $emitEvents = false;

    /** Go on although a pre-change check (backup) asked to stop — shown only after such a stop. */
    public bool $confirmPreChange = false;

    /** A pre-change check stopped the last approval. */
    #[Locked]
    public bool $preChangeStopped = false;

    /** Why the last approval was refused. */
    public string $approveError = '';

    /** Row shown in the detail panel. */
    #[Locked]
    public ?int $detailRowId = null;

    /**
     * A new filter or search starts on the first page.
     *
     * @return void
     */
    public function updatedFilter(): void
    {
        if (!in_array($this->filter, RowAction::ALL, true)) {
            $this->filter = '';
        }
        $this->resetPage();
    }

    /**
     * @return void
     */
    public function updatedSearchSourceKey(): void
    {
        $this->searchSourceKey = mb_substr($this->searchSourceKey, 0, 191);
        $this->resetPage();
    }

    /**
     * Open the detail panel of a row (before → after per field, errors per column).
     *
     * @param int $rowId
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review
     */
    public function showRow(int $rowId): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'showRow');
        $this->detailRowId = (int) StagedRow::where('job_id', $job->id)->whereKey($rowId)->valueOrFail('id');
        $this->dispatch('open-modal', 'data-import-row');
    }

    /**
     * Download the refused and conflicting lines as CSV.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review
     */
    public function downloadErrors()
    {
        $job = $this->job();
        Access::authorizeJob($job, 'downloadErrors');
        $report = new ErrorReport;

        return response()->streamDownload(static function () use ($report, $job): void {
            $out = fopen('php://output', 'wb');
            $report->write($job, $out);
            fclose($out);
        }, $report->fileName($job), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Approve the plan the screen shows and start writing.
     *
     * @param string $fingerprint Fingerprint of the review the user looked at (checked on the server).
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review, US-data-import-pre-change-snapshot
     * @aidlc-adr data-import_staged-review-commit
     */
    public function approve(string $fingerprint): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'approve');
        $this->approveError = '';
        $counts = (new Review)->counts($job);
        if (($counts[RowAction::ERROR] ?? 0) + ($counts[RowAction::CONFLICT] ?? 0) > 0 && !$this->skipInvalid) {
            $this->approveError = (string) trans('Plugins/DataImport::lang.review.skip_needed');

            return;
        }
        try {
            (new ImportService)->approve($job, $fingerprint, (string) (admin()->user()->id ?? ''), $this->preChangeStopped && $this->confirmPreChange);
        } catch (ImportRejected $e) {
            $this->preChangeStopped = $e->key === 'error.pre_change_stopped';
            $this->approveError = $e->getMessage();

            return;
        }
        // WHY after approve(): only an approved run takes the option (the first slice comes with the next poll).
        $job->refresh()->forceFill(['options' => array_merge((array) $job->options, ['emit_events' => $this->emitEvents])])->save();
        $this->preChangeStopped = false;
        AuditLog::write('data_import_approved', $job->refresh(), ['skip_invalid' => $this->skipInvalid, 'emit_events' => $this->emitEvents]);
        $this->notify('success', (string) trans('Plugins/DataImport::lang.msg.approved'));
    }

    /**
     * Cancel a run that has written nothing (its file and staged rows are removed).
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review
     */
    public function cancel(): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'cancel');
        try {
            (new ImportService)->cancel($job);
        } catch (ImportRejected $e) {
            $this->notify('error', $e->getMessage());

            return;
        }
        AuditLog::write('data_import_cancelled', $job->refresh());
        $this->notify('success', (string) trans('Plugins/DataImport::lang.msg.cancelled'));
    }

    /**
     * Resume a stalled run in the phase it stalled in.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-shared-host-run
     */
    public function retry(): void
    {
        $job = $this->job();
        $from = (string) (((array) $job->cursor)['stalled_from'] ?? '');
        Access::authorize($from === JobPhase::UNDOING ? Access::UNDO : Handlers::group($job->entity), $job->id, 'retry');
        (new ImportService)->retry($job);
    }

    /**
     * Undo a written run (within its undo period).
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-undo, US-data-import-access-control
     */
    public function undo(): void
    {
        $job = $this->job();
        Access::authorize(Access::UNDO, $job->id, 'undo');
        try {
            (new ImportService)->undo($job, $this->preChangeStopped && $this->confirmPreChange);
        } catch (ImportRejected $e) {
            $this->preChangeStopped = $e->key === 'error.pre_change_stopped';
            $this->notify('error', $e->getMessage());

            return;
        }
        $this->preChangeStopped = false;
        AuditLog::write('data_import_undo', $job->refresh());
        $this->notify('success', (string) trans('Plugins/DataImport::lang.msg.undo_started'));
    }
}
