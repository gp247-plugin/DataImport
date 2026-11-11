<?php

namespace App\GP247\Plugins\DataImport\Admin\Livewire;

use App\GP247\Plugins\DataImport\Admin\Livewire\Concerns\ReceivesFileParts;
use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\ImportService;
use App\GP247\Plugins\DataImport\Application\Retention;
use App\GP247\Plugins\DataImport\Application\UploadAssembler;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Support\Access;
use App\GP247\Plugins\DataImport\Support\AuditLog;
use App\GP247\Plugins\DataImport\Support\UploadLimits;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use Livewire\WithPagination;

/**
 * List of import runs (a store-admin sees the runs of its own store) and the start of a new run:
 * kind of data, source name, file — sent in parts.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-access-control, US-data-import-upload-source
 */
class ImportIndex extends GP247AdminComponent
{
    use ReceivesFileParts;
    use WithPagination;

    /**
     * Resolves to "<admin>/data-import" when no request path is available.
     *
     * @var string|null
     */
    protected ?string $permission = 'admin_data-import';

    /** Kind of data of the new run. */
    public string $entity = '';

    /** Name of the source system (key of the id map: the same name recognises earlier imports). */
    public string $source = 'csv';

    /** List filter by phase ('' = all). */
    public string $phase = '';

    /**
     * Authorize, choose the first importable kind of data and run the lazy clean-up.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control, US-data-import-data-retention
     */
    public function mount(): void
    {
        parent::mount();
        $this->entity = Access::importableEntities()[0] ?? '';
        (new Retention)->sweepIfDue();
    }

    /**
     * Announce a new file: create the run (store of the session) and answer how to send it.
     *
     * @param string $name Name of the file on the user's computer (cleaned, shown only).
     * @param int $size Bytes.
     * @return array{job: string, chunk: int, total: int, missing: array<int, int>}|array{error: string}
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source, US-data-import-access-control
     */
    public function startUpload(string $name, int $size): array
    {
        Access::authorize(Handlers::group($this->entity), 'new', 'startUpload');
        $chunk = UploadLimits::chunkBytes();
        try {
            $job = (new UploadAssembler)->start(Access::storeContext(), $this->entity, $this->source, $name, $size, $chunk, (string) (admin()->user()->id ?? ''));
        } catch (ImportRejected $e) {
            return $this->partRefused($e);
        }
        AuditLog::write('data_import_created', $job, ['size' => $size]);

        return $this->uploadAnswer($job, $chunk, range(0, (int) $job->options['upload']['chunks'] - 1));
    }

    /**
     * Whether the current admin may send file parts for a new run of the chosen kind of data.
     *
     * @return bool
     */
    protected function canReceiveParts(): bool
    {
        return in_array($this->entity, Access::importableEntities(), true);
    }

    /**
     * A new filter starts on the first page.
     *
     * @return void
     */
    public function updatedPhase(): void
    {
        $this->resetPage();
    }

    /**
     * Render the list.
     *
     * @return \Illuminate\Contracts\View\View
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public function render()
    {
        $l = 'Plugins/DataImport::lang.';
        $entities = [];
        foreach (Access::importableEntities() as $entity) {
            $entities[$entity] = gp247_language_render($l . 'entity.' . $entity);
        }
        $phases = array_keys(JobPhase::SLICED + array_flip([JobPhase::UPLOADING, JobPhase::UPLOADED, JobPhase::STAGED, JobPhase::REVIEWED, JobPhase::COMMITTED, JobPhase::UNDONE, JobPhase::CANCELLED, JobPhase::STALLED]));

        return view('Plugins/DataImport::Admin.index', [
            'jobs' => Access::jobs()->when($this->phase !== '', fn ($q) => $q->where('phase', $this->phase))->orderByDesc('id')->paginate(20),
            'entities' => $entities,
            'phases' => $phases,
            'showStore' => Access::isRoot() && function_exists('gp247_store_check_multi_domain_installed') && gp247_store_check_multi_domain_installed(),
            'maxBytes' => ImportService::maxBytes(),
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render($l . 'title')]);
    }
}
