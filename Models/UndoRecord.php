<?php

namespace App\GP247\Plugins\DataImport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a run changed, so it can be undone: a record it created, or the previous values of
 * the fields it changed on an existing record.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-undo
 */
class UndoRecord extends Model
{
    public const KIND_CREATED = 'created';
    public const KIND_UPDATED = 'updated';

    /** A 301 from the record's old address (target_id = redirect id, before.to = its target). */
    public const KIND_REDIRECT = 'redirect';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_KEPT = 'kept';

    public const UPDATED_AT = null;
    protected $connection = GP247_DB_CONNECTION;
    protected $table = GP247_DB_PREFIX . ExtensionModel::TABLE_UNDO;

    protected $fillable = ['job_id', 'entity', 'target_id', 'kind', 'before', 'after_version', 'map_key_hash', 'status', 'note'];

    protected $casts = ['before' => 'array'];
}
