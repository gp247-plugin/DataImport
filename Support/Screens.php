<?php

namespace App\GP247\Plugins\DataImport\Support;

/**
 * What the admin screens need while the plugin is active: the Livewire namespace and the
 * store-scoped admin segment (so a MultiStore Pro store-admin reaches the runs of its own store;
 * the plugin itself limits each run to its store).
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-access-control
 */
final class Screens
{
    /**
     * Register the Livewire namespace and the store-scoped segment.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function register(): void
    {
        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::addNamespace('DataImport', classNamespace: 'App\\GP247\\Plugins\\DataImport\\Admin\\Livewire');
        }
        config(['gp247-config.admin.store_scoped_segments' => array_values(array_unique(array_merge(
            (array) config('gp247-config.admin.store_scoped_segments', []),
            [Access::URI]
        )))]);
        self::keepPlumbingOutOfTheLog();
    }

    /**
     * Keep the upload plumbing and the 2-second slice pull out of core's Livewire operation log
     * (the run decisions are logged by AuditLog).
     *
     * WHY: a part sent through Livewire's temporary upload holds a TemporaryUploadedFile in the
     * component state, which the operation log cannot serialise; and the slice pull would add a
     * log row every two seconds while a run works.
     *
     * @return void
     */
    private static function keepPlumbingOutOfTheLog(): void
    {
        $except = (string) config('gp247-config.admin.admin_log_livewire_except', \GP247\Core\AdminShell\Support\LivewireOperationLog::DEFAULT_EXCEPT);
        $methods = array_filter(array_map('trim', explode(',', $except)), static fn ($m) => $m !== '');
        $methods = array_values(array_unique(array_merge($methods, self::UNLOGGED_METHODS)));
        config(['gp247-config.admin.admin_log_livewire_except' => implode(',', $methods)]);

        // WHY: the review search holds values of the file (a key may be an e-mail); keep it out of the logged state.
        $keys = array_filter(array_map('trim', explode(',', (string) config('gp247-config.admin.admin_log_except', ''))), static fn ($k) => $k !== '');
        config(['gp247-config.admin.admin_log_except' => implode(',', array_values(array_unique(array_merge($keys, self::UNLOGGED_PROPERTIES))))]);
    }

    /** Component properties holding values of the imported file. */
    private const UNLOGGED_PROPERTIES = ['searchSourceKey'];

    /** Livewire's upload hooks and the plugin's own plumbing methods. */
    private const UNLOGGED_METHODS = ['_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload', 'dataImportPart', 'dataImportSlice'];
}
