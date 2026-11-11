<?php

namespace App\GP247\Plugins\DataImport\Models;

use Illuminate\Support\Facades\Schema;

/**
 * Creates the plugin's tables. Creation is re-entrant (guarded by hasTable) so install() and
 * update() can both run it on a site in any state. Uninstall keeps the id map, the run list and
 * the undo log, so re-installing still recognises what was imported (no duplicates); only the
 * temporary tables (uploaded chunks, staged rows — personal data) are dropped.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-install-lifecycle
 */
class ExtensionModel
{
    public const TABLE_JOB = 'data_import_job';
    public const TABLE_CHUNK = 'data_import_chunk';
    public const TABLE_ROW = 'data_import_row';
    public const TABLE_MAP = 'data_import_map';
    public const TABLE_UNDO = 'data_import_undo';
    public const TABLE_PRESET = 'data_import_mapping_preset';

    /** Tables that hold uploaded / staged data only — never backed up, dropped on uninstall. */
    public const TEMP_TABLES = [self::TABLE_CHUNK, self::TABLE_ROW];

    /**
     * Create every missing table.
     *
     * @return void
     */
    public function installExtension(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $p = GP247_DB_PREFIX;

        if (!$schema->hasTable($p . self::TABLE_JOB)) {
            $schema->create($p . self::TABLE_JOB, function ($table) {
                $table->string('id', 26)->primary();
                $table->string('store_id', 50);
                $table->string('entity', 20);
                $table->string('source', 100);
                $table->string('phase', 20)->index();
                $table->string('encoding', 20)->nullable();
                $table->string('delimiter', 2)->default(',');
                $table->text('mapping')->nullable();
                $table->text('options')->nullable();
                $table->text('cursor')->nullable();
                $table->text('counts')->nullable();
                $table->string('lease_owner', 40)->nullable();
                $table->dateTime('lease_until')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->string('review_fingerprint', 64)->nullable();
                $table->string('approved_by', 36)->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->string('file_path', 255)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('file_sha256', 64)->nullable();
                $table->string('original_name', 255)->nullable();
                $table->dateTime('undo_until')->nullable();
                $table->dateTime('purge_after')->nullable();
                $table->string('created_by', 36)->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable($p . self::TABLE_CHUNK)) {
            $schema->create($p . self::TABLE_CHUNK, function ($table) {
                $table->bigIncrements('id');
                $table->string('job_id', 26);
                $table->unsignedInteger('chunk_index');
                $table->unsignedBigInteger('size');
                $table->string('sha256', 64);
                $table->timestamp('created_at')->nullable();
                $table->unique(['job_id', 'chunk_index']);
            });
        }

        if (!$schema->hasTable($p . self::TABLE_ROW)) {
            $schema->create($p . self::TABLE_ROW, function ($table) {
                $table->bigIncrements('id');
                $table->string('job_id', 26);
                $table->unsignedInteger('line_no');
                $table->longText('row_values');
                $table->string('content_hash', 40);
                $table->string('source_key', 191)->default('');
                $table->string('action', 10)->default('pending');
                $table->string('target_id', 64)->nullable();
                $table->string('target_version', 40)->nullable();
                $table->text('diff')->nullable();
                $table->text('errors')->nullable();
                $table->text('warnings')->nullable();
                $table->string('status', 10)->default('pending');
                $table->index(['job_id', 'action']);
                $table->index(['job_id', 'status', 'line_no']);
                $table->index(['job_id', 'line_no']);
            });
        }

        // WHY: column added during 1.0.0 development; a table created by an earlier build is converged here.
        if (!$schema->hasColumn($p . self::TABLE_ROW, 'warnings')) {
            $schema->table($p . self::TABLE_ROW, fn ($table) => $table->text('warnings')->nullable());
        }

        if (!$schema->hasTable($p . self::TABLE_MAP)) {
            $schema->create($p . self::TABLE_MAP, function ($table) {
                $table->bigIncrements('id');
                // WHY: one hash over (store, source, entity, source key) carries the uniqueness —
                // a unique index on the raw key would follow the column collation, which treats
                // は and ば as the same text (RISK-TECH-data-import-collation-false-match).
                $table->char('key_hash', 40)->unique();
                $table->string('store_id', 50);
                $table->string('source', 100);
                $table->string('entity', 20);
                $table->string('source_key', 191);
                $table->string('target_id', 64);
                $table->string('content_hash', 40)->nullable();
                $table->string('job_id', 26);
                $table->timestamps();
                $table->index(['entity', 'target_id']);
            });
        }

        if (!$schema->hasTable($p . self::TABLE_UNDO)) {
            $schema->create($p . self::TABLE_UNDO, function ($table) {
                $table->bigIncrements('id');
                $table->string('job_id', 26)->index();
                $table->string('entity', 20);
                $table->string('target_id', 64);
                $table->string('kind', 10);
                $table->longText('before')->nullable();
                $table->string('after_version', 40)->nullable();
                $table->string('map_key_hash', 40)->nullable();
                $table->string('status', 10)->default('pending');
                $table->string('note', 255)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!$schema->hasTable($p . self::TABLE_PRESET)) {
            $schema->create($p . self::TABLE_PRESET, function ($table) {
                $table->bigIncrements('id');
                $table->string('store_id', 50);
                $table->string('entity', 20);
                $table->string('name', 100);
                $table->text('mapping');
                $table->string('created_by', 36)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Drop the temporary tables only (the id map, runs and undo log stay — see the class doc).
     *
     * @return void
     */
    public function uninstallExtension(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        foreach (self::TEMP_TABLES as $table) {
            $schema->dropIfExists(GP247_DB_PREFIX . $table);
        }
    }
}
