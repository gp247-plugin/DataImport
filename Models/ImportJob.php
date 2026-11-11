<?php

namespace App\GP247\Plugins\DataImport\Models;

use App\GP247\Plugins\DataImport\Domain\JobPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One import run: which store and kind of data, the file, how it is read and mapped, where
 * the run stands (phase + cursor) and who holds the slice lease.
 *
 * @property string $id
 * @property string $store_id
 * @property string $entity
 * @property string $source
 * @property string $phase
 * @property array  $mapping
 * @property array  $options
 * @property array  $cursor
 * @property array  $counts
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-shared-host-run
 */
class ImportJob extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = GP247_DB_CONNECTION;
    protected $table = GP247_DB_PREFIX . ExtensionModel::TABLE_JOB;

    protected $fillable = [
        'id', 'store_id', 'entity', 'source', 'phase', 'encoding', 'delimiter', 'mapping', 'options', 'cursor', 'counts',
        'lease_owner', 'lease_until', 'attempts', 'last_error', 'review_fingerprint', 'approved_by', 'approved_at',
        'file_path', 'file_size', 'file_sha256', 'original_name', 'undo_until', 'purge_after', 'created_by',
    ];

    protected $casts = [
        'mapping' => 'array', 'options' => 'array', 'cursor' => 'array', 'counts' => 'array',
        'lease_until' => 'datetime', 'approved_at' => 'datetime', 'undo_until' => 'datetime', 'purge_after' => 'datetime',
        'attempts' => 'integer', 'file_size' => 'integer',
    ];

    /**
     * New run id (sortable by creation time, not guessable from the previous one).
     *
     * @return string
     */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * An option of the run with its default.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function option(string $key, $default = null)
    {
        return ((array) $this->options)[$key] ?? $default;
    }

    /**
     * Whether the run is in a phase that writes shop data.
     *
     * @return bool
     */
    public function isWriting(): bool
    {
        return in_array($this->phase, [JobPhase::COMMITTING, JobPhase::UNDOING], true);
    }

    /**
     * Staged rows of this run.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function rows()
    {
        return $this->hasMany(StagedRow::class, 'job_id');
    }
}
