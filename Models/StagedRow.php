<?php

namespace App\GP247\Plugins\DataImport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A line of the source file, read and decoded, with what the run plans to do with it.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-staging-validation, US-data-import-change-review
 */
class StagedRow extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WRITTEN = 'written';
    public const STATUS_FAILED = 'failed';

    public $timestamps = false;
    protected $connection = GP247_DB_CONNECTION;
    protected $table = GP247_DB_PREFIX . ExtensionModel::TABLE_ROW;

    protected $fillable = [
        'job_id', 'line_no', 'row_values', 'content_hash', 'source_key', 'action', 'target_id', 'target_version', 'diff', 'errors', 'warnings', 'status',
    ];

    protected $casts = ['row_values' => 'array', 'diff' => 'array', 'errors' => 'array', 'warnings' => 'array', 'line_no' => 'integer'];
}
