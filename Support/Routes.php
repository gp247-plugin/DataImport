<?php

namespace App\GP247\Plugins\DataImport\Support;

use App\GP247\Plugins\DataImport\Admin\Livewire\ImportDetail;
use App\GP247\Plugins\DataImport\Admin\Livewire\ImportIndex;
use Illuminate\Support\Facades\Route;

/**
 * The two admin screens (list of runs, one run). Every write is a Livewire action re-authorized
 * on the server against the URI of its kind of data, so no POST route exists.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-access-control
 */
final class Routes
{
    /**
     * Register the admin routes.
     *
     * @return void
     *
     * @aidlc-unit data-import
     * @aidlc-story US-data-import-access-control
     */
    public static function register(): void
    {
        Route::group(
            [
                'prefix' => GP247_ADMIN_PREFIX . '/' . Access::URI,
                'middleware' => GP247_ADMIN_MIDDLEWARE,
            ],
            static function (): void {
                Route::get('/', ImportIndex::class)->name('admin_data_import.index');
                // WHY "{id}": the view permission names the route URI exactly (GET::…/{id}), as the route middleware requires.
                Route::get('/{id}', ImportDetail::class)->where('id', '[0-9a-z]{26}')->name('admin_data_import.detail');
            }
        );
    }
}
