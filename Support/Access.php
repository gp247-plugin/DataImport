<?php

namespace App\GP247\Plugins\DataImport\Support;

use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationDecision;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what on which run. Every write action of the screens is authorized against the URI
 * of its kind of data — `<admin>/data-import/<group>/<run id | new>` (groups: catalog, customers,
 * orders, undo) — with core's URI permission matcher, and a run is only reachable from the store
 * of the admin's session (root sees every store). The store of a new run is the session store;
 * it never comes from the screen.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-access-control
 */
final class Access
{
    public const URI = 'data-import';

    /** Group of the undo permission (undo is granted apart from importing). */
    public const UNDO = 'undo';

    /**
     * Admin path of the plugin screens, e.g. "gp247_admin/data-import".
     *
     * @return string
     */
    public static function base(): string
    {
        return (defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin') . '/' . self::URI;
    }

    /**
     * Whether the current admin may run write actions of a permission group.
     *
     * @param string $group catalog | customers | orders | undo
     * @param string $target Run id, or "new" for a run not created yet.
     * @return bool
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function allows(string $group, string $target = 'new'): bool
    {
        try {
            return self::decide($group, $target, 'write')->isAllowed();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Refuse the action unless the current admin holds the permission of the group.
     *
     * @param string $group
     * @param string $target
     * @param string $action Method being run.
     * @return void
     * @throws AuthorizationException
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function authorize(string $group, string $target, string $action): void
    {
        $decision = self::decide($group, $target, $action);
        if (!$decision->isAllowed()) {
            throw AuthorizationException::fromReason($decision->reason());
        }
    }

    /**
     * Refuse the action unless the admin may import the run's kind of data.
     *
     * @param ImportJob $job
     * @param string $action
     * @return void
     * @throws AuthorizationException
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function authorizeJob(ImportJob $job, string $action): void
    {
        self::authorize(Handlers::group($job->entity), $job->id, $action);
    }

    /**
     * Entities the current admin may import, in import order.
     *
     * @return array<int, string>
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function importableEntities(): array
    {
        $groups = [];
        $result = [];
        foreach (Handlers::entities() as $entity) {
            $group = Handlers::group($entity);
            $groups[$group] ??= self::allows($group);
            if ($groups[$group]) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * Store of the admin's session (the store a store-admin is bound to; ROOT for the owner).
     *
     * @return string
     */
    public static function storeContext(): string
    {
        return (string) session('adminStoreId', GP247_STORE_ID_ROOT);
    }

    /**
     * Whether the session is at the root store (sees the runs of every store).
     *
     * @return bool
     */
    public static function isRoot(): bool
    {
        return self::storeContext() === (string) GP247_STORE_ID_ROOT;
    }

    /**
     * Runs the session may see.
     *
     * @return Builder
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function jobs(): Builder
    {
        $query = ImportJob::query();
        if (!self::isRoot()) {
            $query->where('store_id', self::storeContext());
        }

        return $query;
    }

    /**
     * A run the session may see; any other id is "not found" (no hint that it exists).
     *
     * @param string $id
     * @return ImportJob
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function find(string $id): ImportJob
    {
        $job = preg_match('/^[0-9a-z]{26}$/', $id) ? self::jobs()->whereKey($id)->first() : null;
        if ($job === null) {
            abort(404);
        }

        return $job;
    }

    /**
     * @param string $group
     * @param string $target
     * @param string $action
     * @return AuthorizationDecision
     */
    private static function decide(string $group, string $target, string $action): AuthorizationDecision
    {
        return app(AuthorizeAdminAction::class)->authorize(app(AdminUserContract::class), self::base() . '/' . $group . '/' . $target, $action);
    }
}
