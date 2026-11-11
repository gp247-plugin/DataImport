<?php

namespace App\GP247\Plugins\DataImport\Domain;

/**
 * Phases of an import run. Only COMMITTING and UNDOING write shop data; STAGING, PLANNING,
 * COMMITTING and UNDOING are worked through in time-boxed slices.
 *
 *   uploading → uploaded → staging → staged (orders: waits for the status mapping) → planning → reviewed --approve--> committing → committed --undo--> undoing → undone
 *   (any phase before committing) --cancel--> cancelled ; a phase that stops making progress → stalled
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-shared-host-run
 */
final class JobPhase
{
    public const UPLOADING = 'uploading';
    public const UPLOADED = 'uploaded';
    public const STAGING = 'staging';
    public const STAGED = 'staged';
    public const PLANNING = 'planning';
    public const REVIEWED = 'reviewed';
    public const COMMITTING = 'committing';
    public const COMMITTED = 'committed';
    public const UNDOING = 'undoing';
    public const UNDONE = 'undone';
    public const CANCELLED = 'cancelled';
    public const STALLED = 'stalled';

    /** Phases worked through by the slice runner, and the phase each one ends in. */
    public const SLICED = [
        self::STAGING => self::STAGED,
        self::PLANNING => self::REVIEWED,
        self::COMMITTING => self::COMMITTED,
        self::UNDOING => self::UNDONE,
    ];

    /** Phases a run can be cancelled from (nothing written yet). */
    public const CANCELLABLE = [self::UPLOADING, self::UPLOADED, self::STAGING, self::STAGED, self::PLANNING, self::REVIEWED, self::STALLED];

    /** Phases after which the staged data is no longer needed. */
    public const FINISHED = [self::COMMITTED, self::UNDONE, self::CANCELLED];
}
