<?php

namespace App\GP247\Plugins\DataImport\Admin\Livewire;

use App\GP247\Plugins\DataImport\Admin\Livewire\Concerns\DecidesRun;
use App\GP247\Plugins\DataImport\Admin\Livewire\Concerns\ReceivesFileParts;
use App\GP247\Plugins\DataImport\Admin\RunScreen;
use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\ImportService;
use App\GP247\Plugins\DataImport\Application\Presets;
use App\GP247\Plugins\DataImport\Application\SliceRunner;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Support\Access;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

/**
 * One import run, step by step: send missing parts → preview with the character set and
 * separator → column mapping (preset or by hand) → order status mapping → reading and planning
 * in slices (the screen pulls them, so no cron is needed) → review → approve and write → result,
 * undo. Every action re-authorizes the run's kind of data and store; the run id is locked.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source, US-data-import-encoding-detection, US-data-import-column-mapping,
 *              US-data-import-order-status-mapping, US-data-import-change-review, US-data-import-shared-host-run, US-data-import-undo
 */
class ImportDetail extends GP247AdminComponent
{
    use DecidesRun;
    use ReceivesFileParts;
    use WithPagination;

    /**
     * Resolves to "<admin>/data-import" when no request path is available.
     *
     * @var string|null
     */
    protected ?string $permission = 'admin_data-import';

    #[Locked]
    public string $jobId = '';

    public string $encoding = '';
    public string $delimiter = ',';

    /**
     * Target field per column of the file, by column position ('' = not imported).
     * WHY by position: a header may hold dots or spaces, which a Livewire property path cannot.
     *
     * @var array<int, string>
     */
    public array $columns = [];

    public string $preset = '';

    /** @var array<int, string> Columns of the chosen preset the file does not have. */
    public array $presetMissing = [];

    public bool $updateExisting = true;
    public string $language = '';

    /**
     * The status form of the mapping step: kind => position of the value in the list => status id.
     *
     * @var array<string, array<int, string>>
     */
    public array $statusChoice = [];

    /** Shown when the mapping cannot be used. */
    public string $mappingError = '';

    /** Another bulk write (backup, restore) holds the run. */
    public string $blockedBy = '';

    /**
     * Authorize, load the run of the session's store and fill the form from it.
     *
     * @param string $id
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public function mount(string $id = ''): void
    {
        parent::mount();
        $job = Access::find($id);
        $this->jobId = $job->id;
        $this->fillForm($job);
    }

    /**
     * Read the file again with another character set or separator (preview step).
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-encoding-detection
     */
    public function applyReading(): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'applyReading');
        $this->mappingError = '';
        try {
            if ($job->phase !== JobPhase::UPLOADED) {
                // WHY: prepare() resets the run to its first step; only a run still at that step may be read again.
                throw new ImportRejected('error.not_uploaded');
            }
            (new ImportService)->prepare($job, $this->encoding !== '' ? $this->encoding : null, $this->delimiter);
        } catch (ImportRejected $e) {
            $this->mappingError = $e->getMessage();

            return;
        }
        $this->columns = [];
        $this->fillForm($job->refresh());
    }

    /**
     * Fill the column mapping (and the order status mapping) from a preset.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-column-mapping, US-data-import-order-status-mapping
     */
    public function applyPreset(): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'applyPreset');
        if (!isset(Presets::all()[$this->preset])) {
            return;
        }
        $headers = $this->headers($job);
        $applied = Presets::apply($this->preset, $job->entity, $headers);
        $this->columns = array_map(static fn ($header) => (string) ($applied['columns'][$header] ?? ''), $headers);
        $this->presetMissing = $applied['missing'];
    }

    /**
     * Save the mapping and start reading the file.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-column-mapping
     */
    public function startReading(): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'startReading');
        $this->mappingError = '';
        $columns = [];
        foreach ($this->headers($job) as $i => $header) {
            $field = $this->columns[$i] ?? '';
            if (is_string($field) && $field !== '') {
                $columns[$header] = $field;
            }
        }
        $options = ['update_existing' => $this->updateExisting, 'emit_events' => false];
        if ($this->language !== '') {
            $options['language'] = $this->language;
        }
        if ($job->entity === 'order' && isset(Presets::all()[$this->preset])) {
            // WHY from the preset name: the statuses of a preset are data of the plugin, never values sent by the screen.
            $options['statuses'] = Presets::apply($this->preset, $job->entity, $this->headers($job))['statuses'];
        }
        try {
            if ($columns === []) {
                // WHY: configure() maps columns named like the fields when given none; an all-unmapped form must not fall back to that.
                throw new ImportRejected('error.required_unmapped', ['fields' => implode(' / ', Handlers::for($job->entity)->requiredFields()[0])]);
            }
            (new ImportService)->configure($job, $columns, $options, $this->encoding !== '' ? $this->encoding : null, $this->delimiter);
        } catch (ImportRejected $e) {
            $this->mappingError = $e->getMessage();
        }
    }

    /**
     * Save the order status mapping and plan the run.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-order-status-mapping
     */
    public function saveStatuses(): void
    {
        $job = $this->job();
        Access::authorizeJob($job, 'saveStatuses');
        $this->mappingError = '';
        $service = new ImportService;
        $map = [];
        foreach ($service->statusValues($job) as $kind => $values) {
            foreach (array_keys($values) as $i => $value) {
                $id = (string) ($this->statusChoice[$kind][$i] ?? '');
                if ($id !== '') {
                    $map[$kind][(string) $value] = $id;
                }
            }
        }
        try {
            $service->mapStatuses($job, $map);
        } catch (ImportRejected $e) {
            $this->mappingError = $e->getMessage();
        }
    }

    /**
     * Work one slice of a run that is reading, planning, writing or undoing (pulled by the
     * screen every 2 s, so a shared host without cron or queue gets through a large file).
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-shared-host-run
     * @aidlc-adr data-import_sliced-runner
     */
    public function dataImportSlice(): void
    {
        $job = $this->job();
        if (!array_key_exists($job->phase, JobPhase::SLICED)) {
            return;
        }
        Access::authorize($job->phase === JobPhase::UNDOING ? Access::UNDO : Handlers::group($job->entity), $job->id, 'dataImportSlice');
        $result = (new SliceRunner)->run($job->id);
        $this->blockedBy = $result['state'] === SliceRunner::BLOCKED ? (string) $result['blocked_by'] : '';
        if ($result['phase'] === JobPhase::STAGED) {
            $this->fillStatusChoice($job->refresh());
        }
    }

    /**
     * Render the run.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function render()
    {
        $job = $this->job();

        return view('Plugins/DataImport::Admin.detail', (new RunScreen($job))->data($this->filter, $this->searchSourceKey, $this->detailRowId) + [
            'job' => $job,
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('Plugins/DataImport::lang.title'),
        ]);
    }

    /**
     * The run, from the session's store (a store switch after mount is honoured).
     *
     * @return ImportJob
     */
    protected function job(): ImportJob
    {
        return Access::find($this->jobId);
    }

    /**
     * Headers of the run's file.
     *
     * @param ImportJob $job
     * @return array<int, string>
     */
    private function headers(ImportJob $job): array
    {
        return array_map('strval', (array) (((array) $job->mapping)['headers'] ?? []));
    }

    /**
     * Form values from the run: reading settings, the saved column mapping or, for a new run,
     * the columns named like a field.
     *
     * @param ImportJob $job
     * @return void
     */
    private function fillForm(ImportJob $job): void
    {
        $this->encoding = (string) $job->encoding;
        $this->delimiter = (string) ($job->delimiter ?: ',');
        $saved = (array) (((array) $job->mapping)['columns'] ?? []);
        $targets = Handlers::for($job->entity)->targets();
        foreach ($this->headers($job) as $i => $header) {
            $guess = in_array(mb_strtolower($header), $targets, true) ? mb_strtolower($header) : '';
            $this->columns[$i] ??= (string) ($saved[$header] ?? $guess);
        }
        if ($job->phase === JobPhase::STAGED) {
            $this->fillStatusChoice($job);
        }
    }

    /**
     * Pre-select the status form from the mapping saved with the run (the preset's statuses).
     *
     * @param ImportJob $job
     * @return void
     */
    private function fillStatusChoice(ImportJob $job): void
    {
        $saved = (array) (((array) $job->mapping)['statuses'] ?? []);
        foreach ((new ImportService)->statusValues($job) as $kind => $values) {
            foreach (array_keys($values) as $i => $value) {
                $this->statusChoice[$kind][$i] ??= (string) ($saved[$kind][(string) $value] ?? '');
            }
        }
    }

    /**
     * Whether the current admin may send file parts of this run.
     *
     * @return bool
     */
    protected function canReceiveParts(): bool
    {
        try {
            $job = $this->job();
        } catch (\Throwable) {
            return false;
        }

        return $job->phase === JobPhase::UPLOADING && Access::allows(Handlers::group($job->entity), $job->id);
    }
}
