<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\RowAction;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use App\GP247\Plugins\DataImport\Models\StagedRow;

/**
 * The review of a planned run: counts per action (one GROUP BY, rows are never loaded) and
 * the fingerprint of what will be written — recomputed at approval, so an approval only
 * covers the plan the user actually saw.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-change-review, US-data-import-legacy-url-redirect
 * @aidlc-adr data-import_staged-review-commit
 */
final class Review
{
    /**
     * Rows per action, plus the written / failed counts of the write phase.
     *
     * @param ImportJob $job
     * @return array<string, int>
     */
    public function counts(ImportJob $job): array
    {
        $counts = array_fill_keys(RowAction::ALL, 0);
        foreach (StagedRow::where('job_id', $job->id)->groupBy('action')->selectRaw('action, COUNT(*) AS n')->pluck('n', 'action') as $action => $n) {
            $counts[(string) $action] = (int) $n;
        }
        $counts['written'] = StagedRow::where('job_id', $job->id)->where('status', StagedRow::STATUS_WRITTEN)->count();
        $counts['warned'] = StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)->whereNotNull('warnings')->count();
        // SEO carry-over (US-data-import-legacy-url-redirect, US-data-import-seo-meta): the diff / warning keys are fixed words.
        $counts['redirect_planned'] = StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)->where('diff', 'like', '%"legacy_url"%')->count();
        $counts['redirect_unplanned'] = StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)->where('warnings', 'like', '%"legacy_skipped"%')->count();
        $counts['seo_template'] = StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)->where('warnings', 'like', '%"seo_template"%')->count();

        return array_merge((array) $job->counts, $counts);
    }

    /**
     * Fingerprint of every row that will be written (id, action, content, target version), in
     * file order, hashed incrementally in batches (constant memory).
     *
     * @param ImportJob $job
     * @return string
     */
    public function fingerprint(ImportJob $job): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, $job->id);
        StagedRow::where('job_id', $job->id)->whereIn('action', RowAction::WRITES)->orderBy('line_no')
            ->select(['id', 'line_no', 'action', 'content_hash', 'target_version'])
            ->chunk(1000, function ($rows) use ($hash): void {
                foreach ($rows as $row) {
                    hash_update($hash, implode('|', [$row->id, $row->action, $row->content_hash, (string) $row->target_version]) . "\n");
                }
            });

        return hash_final($hash);
    }
}
