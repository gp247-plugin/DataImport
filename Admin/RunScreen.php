<?php

namespace App\GP247\Plugins\DataImport\Admin;

use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\ImportService;
use App\GP247\Plugins\DataImport\Application\Presets;
use App\GP247\Plugins\DataImport\Application\Review;
use App\GP247\Plugins\DataImport\Application\UploadAssembler;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Domain\SourceEncoding;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;
use App\GP247\Plugins\DataImport\Models\UndoRecord;
use App\GP247\Plugins\DataImport\Support\Access;
use GP247\Core\Models\AdminLanguage;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopPaymentStatus;
use GP247\Shop\Models\ShopShippingStatus;

/**
 * What the run screen shows for the run's phase: the wizard steps, the preview and mapping
 * form, the status mapping, the progress of a sliced phase, the review (counts with one
 * GROUP BY, rows paginated — never loaded whole) and the result. Rows and their values are only
 * given to an admin allowed to import the run's kind of data.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-change-review, US-data-import-column-mapping, US-data-import-order-status-mapping,
 *              US-data-import-encoding-detection, US-data-import-shared-host-run
 */
final class RunScreen
{
    /** Rows per review page. */
    private const PER_PAGE = 50;

    /** Lines of the preview. */
    private const PREVIEW_LINES = 20;

    /** Wizard steps and the phases each one covers. */
    private const STEPS = [
        'upload' => [JobPhase::UPLOADING],
        'mapping' => [JobPhase::UPLOADED],
        'reading' => [JobPhase::STAGING, JobPhase::STAGED, JobPhase::PLANNING],
        'review' => [JobPhase::REVIEWED],
        'write' => [JobPhase::COMMITTING, JobPhase::COMMITTED, JobPhase::UNDOING, JobPhase::UNDONE],
    ];

    /**
     * @param ImportJob $job
     */
    public function __construct(private readonly ImportJob $job)
    {
    }

    /**
     * View data of the run screen.
     *
     * @param string $filter Review filter (RowAction or '').
     * @param string $search Review search on the source key.
     * @param int|null $detailRowId Row of the detail panel.
     * @return array<string, mixed>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-change-review
     */
    public function data(string $filter, string $search, ?int $detailRowId): array
    {
        $job = $this->job;
        $canWork = Access::allows(Handlers::group($job->entity), $job->id);
        $data = [
            'canWork' => $canWork,
            'canUndo' => $job->phase === JobPhase::COMMITTED && $job->undo_until !== null && $job->undo_until->isFuture()
                && Access::allows(Access::UNDO, $job->id),
            'sliced' => array_key_exists($job->phase, JobPhase::SLICED),
            'steps' => $this->steps(),
            'counts' => $job->phase === JobPhase::REVIEWED ? (new Review)->counts($job) : (array) $job->counts,
            'progress' => $this->progress(),
            'missing' => [],
            'headers' => [],
            'preview' => [],
            'statusValues' => [],
            'rows' => null,
            'detailRow' => null,
        ];

        if ($job->phase === JobPhase::UPLOADING) {
            $data['missing'] = $this->missing();
        } elseif ($job->phase === JobPhase::UPLOADED && $canWork) {
            $data = array_replace($data, $this->mappingForm());
        } elseif ($job->phase === JobPhase::STAGED && $job->entity === 'order' && $canWork) {
            $data['statusValues'] = (new ImportService)->statusValues($job);
            $data['statusOptions'] = [
                'status' => ShopOrderStatus::getIdAll(),
                'shipping_status' => ShopShippingStatus::getIdAll(),
                'payment_status' => ShopPaymentStatus::getIdAll(),
            ];
        } elseif ($job->phase === JobPhase::REVIEWED && $canWork) {
            $data['rows'] = $this->rows($filter, $search);
            $data['detailRow'] = $detailRowId === null ? null : StagedRow::where('job_id', $job->id)->whereKey($detailRowId)->first();
        }
        $data['writeWarnings'] = (array) (((array) $job->cursor)['write_warnings'] ?? []);

        return $data;
    }

    /**
     * The steps with their state (done / current / later).
     *
     * @return array<string, string>
     */
    private function steps(): array
    {
        $current = null;
        foreach (self::STEPS as $step => $phases) {
            if (in_array($this->job->phase, $phases, true)) {
                $current = $step;
            }
        }
        $stalledFrom = (string) (((array) $this->job->cursor)['stalled_from'] ?? '');
        foreach (self::STEPS as $step => $phases) {
            if ($current === null && in_array($stalledFrom, $phases, true)) {
                $current = $step;
            }
        }
        $out = [];
        $reached = false;
        foreach (array_keys(self::STEPS) as $step) {
            $out[$step] = $step === $current ? 'current' : ($reached || $current === null ? 'later' : 'done');
            $reached = $reached || $step === $current;
        }

        return $out;
    }

    /**
     * Progress of a sliced phase: [done, total] (total 0 = unknown, e.g. lines still being read).
     *
     * @return array{done: int, total: int}
     */
    private function progress(): array
    {
        $job = $this->job;
        $rows = StagedRow::where('job_id', $job->id);

        return match ($job->phase) {
            JobPhase::STAGING => ['done' => max(0, (int) (((array) $job->cursor)['line'] ?? 1) - 1), 'total' => 0],
            JobPhase::PLANNING => ['done' => (clone $rows)->where('action', '!=', RowAction::PENDING)->count(), 'total' => (clone $rows)->count()],
            JobPhase::COMMITTING => $this->writeProgress(),
            JobPhase::UNDOING => [
                'done' => UndoRecord::where('job_id', $job->id)->where('status', '!=', UndoRecord::STATUS_PENDING)->count(),
                'total' => UndoRecord::where('job_id', $job->id)->count(),
            ],
            default => ['done' => 0, 'total' => 0],
        };
    }

    /**
     * Write progress: rows written or failed so far, out of those plus the rows still to write.
     *
     * @return array{done: int, total: int}
     */
    private function writeProgress(): array
    {
        $done = StagedRow::where('job_id', $this->job->id)->where('status', '!=', StagedRow::STATUS_PENDING)->count();
        $left = StagedRow::where('job_id', $this->job->id)->where('status', StagedRow::STATUS_PENDING)->whereIn('action', RowAction::WRITES)->count();

        return ['done' => $done, 'total' => $done + $left];
    }

    /**
     * Parts not received yet of an upload in progress.
     *
     * @return array<int, int>
     */
    private function missing(): array
    {
        try {
            return (new UploadAssembler)->missing($this->job);
        } catch (ImportRejected) {
            return [];
        }
    }

    /**
     * Preview and the choices of the mapping step.
     *
     * @return array<string, mixed>
     */
    private function mappingForm(): array
    {
        $job = $this->job;
        $handler = Handlers::for($job->entity);
        $presets = [];
        foreach (Presets::all() as $key => $preset) {
            if (isset($preset['entities'][$job->entity])) {
                $help = 'Plugins/DataImport::lang.preset_help.' . $key;
                $presets[$key] = ['name' => $preset['name'], 'help' => trans()->has($help) ? (string) trans($help) : $preset['help']];
            }
        }

        return [
            'headers' => array_map('strval', (array) (((array) $job->mapping)['headers'] ?? [])),
            'preview' => (new ImportService)->preview($job, self::PREVIEW_LINES),
            'targets' => $handler->targets(),
            'required' => array_map(static fn (array $alternatives) => implode(' / ', $alternatives), $handler->requiredFields()),
            'encodings' => SourceEncoding::names(),
            'delimiters' => [',' => ',', ';' => ';', "\t" => 'TAB'],
            'presets' => $presets,
            'languages' => array_map('strval', array_keys(AdminLanguage::getListActive()->all())),
        ];
    }

    /**
     * One page of the review table.
     *
     * @param string $filter
     * @param string $search
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function rows(string $filter, string $search)
    {
        $query = StagedRow::where('job_id', $this->job->id)
            ->whereIn('action', in_array($filter, RowAction::ALL, true) ? [$filter] : RowAction::ALL)
            ->select(['id', 'line_no', 'source_key', 'action', 'diff', 'errors', 'warnings'])
            ->orderBy('line_no');
        if ($search !== '') {
            $query->where('source_key', 'like', '%' . addcslashes($search, '\\%_') . '%');
        }

        return $query->paginate(self::PER_PAGE);
    }
}
