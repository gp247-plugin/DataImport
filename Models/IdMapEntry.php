<?php

namespace App\GP247\Plugins\DataImport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which GP247 record a source record became: (store, source, entity, source key) → target id.
 * Written in the same transaction as the record itself, so a retried run never creates it twice.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-id-mapping, US-data-import-idempotent-commit
 * @aidlc-adr data-import_id-map-exactly-once
 */
class IdMapEntry extends Model
{
    protected $connection = GP247_DB_CONNECTION;
    protected $table = GP247_DB_PREFIX . ExtensionModel::TABLE_MAP;

    protected $fillable = ['key_hash', 'store_id', 'source', 'entity', 'source_key', 'target_id', 'content_hash', 'job_id'];

    /**
     * The uniqueness hash of a source record.
     *
     * @param string $store
     * @param string $source
     * @param string $entity
     * @param string $sourceKey
     * @return string
     */
    public static function keyHash(string $store, string $source, string $entity, string $sourceKey): string
    {
        return sha1(json_encode([$store, $source, $entity, $sourceKey], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Find an entry, optionally locking it for the current transaction.
     *
     * @param string $keyHash
     * @param bool   $lock
     * @return self|null
     */
    public static function findByHash(string $keyHash, bool $lock = false): ?self
    {
        $query = self::query()->where('key_hash', $keyHash);

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }
}
