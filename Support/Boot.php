<?php

namespace App\GP247\Plugins\DataImport\Support;

use App\GP247\Plugins\DataImport\Application\SliceRunner;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ExtensionModel;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Core\Support\WriteActivity;

/**
 * Boot steps of the active plugin (kept out of Provider.php so they can be tested and so the
 * provider file stays a plain include).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-shared-host-run, US-data-import-install-lifecycle
 * @aidlc-adr data-import_sliced-runner
 */
final class Boot
{
    /**
     * Register the probe and the backup exclusion.
     *
     * @return void
     */
    public static function register(): void
    {
        if (class_exists(WriteActivity::class)) {
            WriteActivity::register(SliceRunner::KEY, [self::class, 'probe']);
        }

        $excluded = (array) config('gp247-config.backup.excluded_tables', []);
        config(['gp247-config.backup.excluded_tables' => array_values(array_unique(array_merge($excluded, ExtensionModel::TEMP_TABLES)))]);
    }

    /**
     * Write-activity probe: a run that is writing shop data and still holds a live lease, or
     * whose last slice ended less than a lease ago (the next slice is coming).
     *
     * @return array{label: string, until: \DateTimeInterface|null}|null
     */
    public static function probe(): ?array
    {
        $job = ImportJob::whereIn('phase', [JobPhase::COMMITTING, JobPhase::UNDOING])
            ->where('updated_at', '>', now()->subMinutes((int) config('Plugins/DataImport.idle_minutes', 15)))
            ->orderByDesc('updated_at')->first();
        if ($job === null) {
            return null;
        }

        return [
            'label' => (string) trans('Plugins/DataImport::lang.activity_label', ['id' => $job->id]),
            'until' => $job->lease_until,
        ];
    }
}
