<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ExtensionModel;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Receives a large CSV in chunks smaller than the host's upload limit: the run is created
 * first (sizes checked before anything touches the disk), each chunk is checked against the
 * announced layout and stored under a generated name in the run's folder (outside the web
 * root), chunks may come in any order and again (a lost one is sent again — the upload resumes),
 * and the assembled file goes through the same checks as any upload.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source
 * @aidlc-adr data-import_file-formats
 */
final class UploadAssembler
{
    /**
     * Announce an upload.
     *
     * @param string $storeId
     * @param string $entity
     * @param string $source
     * @param string $originalName Name shown to the user (cleaned).
     * @param int $totalBytes
     * @param int $chunkBytes Size of every chunk but the last.
     * @param string|null $createdBy
     * @return ImportJob In phase uploading.
     * @throws ImportRejected When a size is out of bounds.
     */
    public function start(string $storeId, string $entity, string $source, string $originalName, int $totalBytes, int $chunkBytes, ?string $createdBy = null): ImportJob
    {
        if ($totalBytes <= 0 || $totalBytes > ImportService::maxBytes() || $chunkBytes <= 0) {
            throw new ImportRejected('error.file_size');
        }
        $chunks = (int) ceil($totalBytes / $chunkBytes);
        if ($chunks > (int) config('Plugins/DataImport.max_chunks', 10000)) {
            throw new ImportRejected('error.too_many_chunks');
        }
        $job = (new ImportService)->newJob($storeId, $entity, $source, $originalName, $createdBy);
        $job->forceFill(['options' => ['upload' => ['total' => $totalBytes, 'chunk' => $chunkBytes, 'chunks' => $chunks]]])->save();

        return $job;
    }

    /**
     * Store one chunk (sending a chunk again replaces it).
     *
     * @param ImportJob $job
     * @param int $index 0-based.
     * @param string $path Temporary file of the chunk (an upload).
     * @return void
     * @throws ImportRejected
     */
    public function put(ImportJob $job, int $index, string $path): void
    {
        $layout = $this->layout($job);
        if ($index < 0 || $index >= $layout['chunks']) {
            throw new ImportRejected('error.chunk_index');
        }
        $expected = $index === $layout['chunks'] - 1 ? $layout['total'] - $layout['chunk'] * ($layout['chunks'] - 1) : $layout['chunk'];
        $size = is_file($path) ? (int) filesize($path) : -1;
        if ($size !== $expected) {
            throw new ImportRejected('error.chunk_size');
        }
        $target = $this->chunkDir($job) . DIRECTORY_SEPARATOR . $index;
        if (!@copy($path, $target)) {
            throw new ImportRejected('error.file_unreadable');
        }
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . ExtensionModel::TABLE_CHUNK);
        $table->updateOrInsert(['job_id' => $job->id, 'chunk_index' => $index], ['size' => $size, 'sha256' => hash_file('sha256', $target), 'created_at' => now()]);
        $job->touch();
    }

    /**
     * Chunks still missing (what to send after a lost connection).
     *
     * @param ImportJob $job
     * @return array<int, int>
     */
    public function missing(ImportJob $job): array
    {
        $layout = $this->layout($job);
        $have = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . ExtensionModel::TABLE_CHUNK)
            ->where('job_id', $job->id)->pluck('chunk_index')->map(static fn ($i) => (int) $i)->all();

        return array_values(array_diff(range(0, $layout['chunks'] - 1), $have));
    }

    /**
     * Join the chunks into the run's source file and check it like a single upload.
     *
     * @param ImportJob $job
     * @return void
     * @throws ImportRejected When chunks are missing or the file cannot be used (the run is then cancelled).
     */
    public function assemble(ImportJob $job): void
    {
        $layout = $this->layout($job);
        if ($this->missing($job) !== []) {
            throw new ImportRejected('error.upload_incomplete');
        }
        try {
            $this->join($job, $layout);
            (new ImportService)->prepare($job);
        } catch (ImportRejected $e) {
            $this->discard($job);
            throw $e;
        }
        $options = (array) $job->options;
        unset($options['upload']);
        $job->forceFill(['options' => $options])->save();
        File::deleteDirectory($this->chunkDir($job));
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . ExtensionModel::TABLE_CHUNK)->where('job_id', $job->id)->delete();
    }

    /**
     * Copy the chunks in order into source.csv, checking each one is still what was received.
     *
     * @param ImportJob $job
     * @param array{total: int, chunk: int, chunks: int} $layout
     * @return void
     * @throws ImportRejected
     */
    private function join(ImportJob $job, array $layout): void
    {
        $hashes = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . ExtensionModel::TABLE_CHUNK)
            ->where('job_id', $job->id)->pluck('sha256', 'chunk_index');
        $out = fopen(JobFiles::source($job), 'wb');
        if ($out === false) {
            throw new ImportRejected('error.file_unreadable');
        }
        try {
            for ($i = 0; $i < $layout['chunks']; $i++) {
                $part = $this->chunkDir($job) . DIRECTORY_SEPARATOR . $i;
                if (!is_file($part) || !hash_equals((string) ($hashes[$i] ?? ''), (string) hash_file('sha256', $part))) {
                    throw new ImportRejected('error.upload_incomplete');
                }
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        if ((int) filesize(JobFiles::source($job)) !== $layout['total']) {
            throw new ImportRejected('error.upload_incomplete');
        }
    }

    /**
     * Cancel a run whose file cannot be used and remove what it received.
     *
     * @param ImportJob $job
     * @return void
     */
    private function discard(ImportJob $job): void
    {
        $job->forceFill(['phase' => JobPhase::CANCELLED])->save();
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . ExtensionModel::TABLE_CHUNK)->where('job_id', $job->id)->delete();
        JobFiles::purge($job->id);
    }

    /**
     * The announced layout of an upload still in progress.
     *
     * @param ImportJob $job
     * @return array{total: int, chunk: int, chunks: int}
     * @throws ImportRejected When the run is not uploading.
     */
    private function layout(ImportJob $job): array
    {
        $upload = (array) (((array) $job->options)['upload'] ?? []);
        if ($job->phase !== JobPhase::UPLOADING || !isset($upload['total'], $upload['chunk'], $upload['chunks'])) {
            throw new ImportRejected('error.not_uploading');
        }

        return ['total' => (int) $upload['total'], 'chunk' => (int) $upload['chunk'], 'chunks' => (int) $upload['chunks']];
    }

    /**
     * @param ImportJob $job
     * @return string
     */
    private function chunkDir(ImportJob $job): string
    {
        $dir = JobFiles::dir($job->id) . DIRECTORY_SEPARATOR . 'chunks';
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0750, true);
        }

        return $dir;
    }
}
