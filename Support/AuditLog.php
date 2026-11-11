<?php

namespace App\GP247\Plugins\DataImport\Support;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Core\Models\AdminLog;

/**
 * One admin_log row per run decision (created, approved, cancelled, undo): who, which run, which
 * kind of data, the cleaned file name and the counts — never a cell value of the file.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-access-control
 */
final class AuditLog
{
    /**
     * Write the audit row.
     *
     * @param string $action data_import_created | data_import_approved | data_import_cancelled | data_import_undo
     * @param ImportJob $job
     * @param array<string, bool|int|string> $extra Further non-personal facts (e.g. options of the approval).
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function write(string $action, ImportJob $job, array $extra = []): void
    {
        $request = request();
        $counts = [];
        foreach ((array) $job->counts as $key => $value) {
            if (is_numeric($value) && (int) $value !== 0) {
                $counts[(string) $key] = (int) $value;
            }
        }

        AdminLog::create([
            'user_id' => (string) (admin()->user()->id ?? ''),
            'path' => Access::base(),
            'method' => 'LIVEWIRE',
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'input' => json_encode([
                'action' => $action,
                'job' => $job->id,
                'store' => (string) $job->store_id,
                'entity' => $job->entity,
                'phase' => $job->phase,
                'file' => (string) $job->original_name,
                'counts' => $counts,
            ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
