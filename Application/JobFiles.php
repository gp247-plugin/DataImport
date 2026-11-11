<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use Illuminate\Support\Facades\File;

/**
 * Where a run keeps its files: `storage/app/data-import/<run id>/`, outside the web root, with
 * names the plugin chooses (never a path or name taken from a request).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-upload-source, US-data-import-data-retention
 */
final class JobFiles
{
    /**
     * Folder of a run (created on demand).
     *
     * @param string $jobId
     * @return string
     */
    public static function dir(string $jobId): string
    {
        if (!preg_match('/^[0-9a-z]{26}$/', $jobId)) {
            throw new \InvalidArgumentException('Invalid run id.');
        }
        $dir = storage_path('app/data-import/' . $jobId);
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0750, true);
        }

        return $dir;
    }

    /**
     * The source file of a run.
     *
     * @param ImportJob $job
     * @return string
     */
    public static function source(ImportJob $job): string
    {
        return self::dir($job->id) . DIRECTORY_SEPARATOR . 'source.csv';
    }

    /**
     * Remove every file of a run.
     *
     * @param string $jobId
     * @return void
     */
    public static function purge(string $jobId): void
    {
        if (!preg_match('/^[0-9a-z]{26}$/', $jobId)) {
            return;
        }
        File::deleteDirectory(storage_path('app/data-import/' . $jobId));
    }
}
