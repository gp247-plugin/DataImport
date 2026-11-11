<?php

namespace App\GP247\Plugins\DataImport\Admin\Livewire\Concerns;

use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\UploadAssembler;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Support\Access;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Receiving a file in parts: the browser cuts the file (each part below the host's upload
 * limit), sends one part at a time through Livewire's temporary upload, and the server stores it
 * on the run; a lost part is asked again (what is missing is kept on the server), then the parts
 * are joined and checked. Every call re-authorizes the run (kind of data + store of the session).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source, US-data-import-access-control
 */
trait ReceivesFileParts
{
    use WithFileUploads {
        _startUpload as livewireStartUpload;
    }

    /** @var TemporaryUploadedFile|null The part just uploaded by the browser. */
    public $chunk = null;

    /** Run the parts belong to (set by startUpload / uploadState, read by the browser). */
    public string $uploadJobId = '';

    /** Size of every part but the last, in bytes. */
    public int $uploadChunkBytes = 0;

    /**
     * Livewire's upload start, limited to one part of a file into $chunk and to admins allowed
     * to import (a view-only admin cannot fill the temporary upload folder).
     *
     * @param string $name
     * @param array<int, array<string, mixed>> $fileInfo
     * @param bool $isMultiple
     * @return mixed
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source, US-data-import-access-control
     */
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        if ($name !== 'chunk' || $isMultiple || !$this->canReceiveParts()) {
            throw \GP247\Core\AdminShell\Domain\AuthorizationException::fromReason('data_import_upload_refused');
        }

        return $this->livewireStartUpload($name, $fileInfo, $isMultiple);
    }

    /**
     * Whether the current admin may send file parts on this screen.
     *
     * @return bool
     */
    abstract protected function canReceiveParts(): bool;

    /**
     * Where an upload in progress stands (to resume it after a lost connection).
     *
     * @param string $jobId
     * @return array{job: string, chunk: int, total: int, missing: array<int, int>}|array{error: string}
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source
     */
    public function uploadState(string $jobId): array
    {
        $job = Access::find($jobId);
        Access::authorizeJob($job, 'uploadState');
        $layout = (array) (((array) $job->options)['upload'] ?? []);
        try {
            $missing = (new UploadAssembler)->missing($job);
        } catch (ImportRejected $e) {
            return $this->partRefused($e);
        }

        return $this->uploadAnswer($job, (int) ($layout['chunk'] ?? 0), $missing);
    }

    /**
     * Store the part just uploaded as part $index of the run.
     *
     * @param string $jobId
     * @param int $index 0-based.
     * @return array{ok: bool, error?: string}
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source
     */
    public function dataImportPart(string $jobId, int $index): array
    {
        // WHY first: a refused call must not leave the uploaded file in the component state.
        $part = $this->chunk;
        $this->chunk = null;
        if (!$part instanceof TemporaryUploadedFile) {
            return ['ok' => false, 'error' => (string) trans('Plugins/DataImport::lang.error.upload_incomplete')];
        }
        $local = null;
        try {
            $job = Access::find($jobId);
            Access::authorizeJob($job, 'dataImportPart');
            // WHY a local copy: Livewire's temporary disk may not be local, so the part is read as a stream.
            $local = tempnam(sys_get_temp_dir(), 'gp247-di-');
            $in = $part->readStream();
            $out = fopen($local, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
            (new UploadAssembler)->put($job, $index, $local);
        } catch (ImportRejected $e) {
            return ['ok' => false] + $this->partRefused($e);
        } finally {
            if ($local !== null) {
                @unlink($local);
            }
            $part->delete();
        }

        return ['ok' => true];
    }

    /**
     * Join the parts and open the run on its preview.
     *
     * @param string $jobId
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-upload-source
     */
    public function finishUpload(string $jobId): void
    {
        $job = Access::find($jobId);
        Access::authorizeJob($job, 'finishUpload');
        try {
            (new UploadAssembler)->assemble($job);
        } catch (ImportRejected $e) {
            $this->partRefused($e);

            return;
        }
        $this->redirect(gp247_route_admin('admin_data_import.detail', ['id' => $job->id]));
    }

    /**
     * The answer the browser needs to send the parts.
     *
     * @param ImportJob $job
     * @param int $chunk
     * @param array<int, int> $missing
     * @return array{job: string, chunk: int, total: int, missing: array<int, int>}
     */
    protected function uploadAnswer(ImportJob $job, int $chunk, array $missing): array
    {
        $this->uploadJobId = $job->id;
        $this->uploadChunkBytes = $chunk;

        return [
            'job' => $job->id,
            'chunk' => $chunk,
            'total' => (int) (((array) $job->options)['upload']['total'] ?? 0),
            'missing' => array_values($missing),
        ];
    }

    /**
     * Toast a refusal and give its message to the browser.
     *
     * @param ImportRejected $e
     * @return array{error: string}
     */
    protected function partRefused(ImportRejected $e): array
    {
        $this->notify('error', $e->getMessage());

        return ['error' => $e->getMessage()];
    }
}
