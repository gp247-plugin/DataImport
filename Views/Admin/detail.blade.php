{{--
    Data Import — one run, step by step (upload → preview & mapping → reading → review → write).
    The part shown follows the run's phase; a sliced phase is pulled every 2 s by wire:poll (no
    cron needed). Values of the file are always printed escaped ({{ }}).

    @aidlc-unit data-import
    @aidlc-story US-data-import-upload-source
    @aidlc-story US-data-import-encoding-detection
    @aidlc-story US-data-import-column-mapping
    @aidlc-story US-data-import-order-status-mapping
    @aidlc-story US-data-import-change-review
    @aidlc-story US-data-import-shared-host-run
    @aidlc-story US-data-import-undo

    Variables: see App\GP247\Plugins\DataImport\Admin\RunScreen::data() + $job.
--}}
@php($l = 'Plugins/DataImport::lang.')
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div class="space-y-4" @if ($sliced && $canWork) wire:poll.2s="dataImportSlice" @endif data-testid="data-import-run">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <a href="{{ gp247_route_admin('admin_data_import.index') }}" class="text-sm text-blue-600 hover:underline" data-testid="data-import-run-back">&larr; {{ gp247_language_render($l.'runs') }}</a>
            <h2 class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">
                {{ $job->original_name ?: $job->id }}
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">· {{ gp247_language_render($l.'entity.'.$job->entity) }} · {{ $job->source }}</span>
            </h2>
        </div>
        @include('Plugins/DataImport::Admin.partials.phase_badge', ['phase' => $job->phase])
    </div>

    <ol class="flex flex-wrap gap-2" data-testid="data-import-run-steps">
        @foreach ($steps as $step => $state)
            <li>
                <x-gp247::badge :color="$state === 'current' ? 'blue' : ($state === 'done' ? 'green' : 'gray')" :solid="$state === 'current'">
                    {{ $loop->iteration }}. {{ gp247_language_render($l.'step.'.$step) }}
                </x-gp247::badge>
            </li>
        @endforeach
    </ol>

    @unless ($canWork)
        <x-gp247::alert type="info" data-testid="data-import-run-readonly">{{ gp247_language_render($l.'readonly_run') }}</x-gp247::alert>
    @endunless

    @if ($blockedBy !== '')
        <x-gp247::alert type="warning" data-testid="data-import-run-blocked">{{ gp247_language_render($l.'error.other_write_running', ['name' => $blockedBy]) }}</x-gp247::alert>
    @endif

    @switch($job->phase)
        @case('uploading')
            @include('Plugins/DataImport::Admin.partials.step_upload')
            @break
        @case('uploaded')
            @include('Plugins/DataImport::Admin.partials.step_mapping')
            @break
        @case('staged')
            @include('Plugins/DataImport::Admin.partials.step_statuses')
            @break
        @case('reviewed')
            @include('Plugins/DataImport::Admin.partials.step_review')
            @break
        @case('stalled')
            <x-gp247::alert type="error" data-testid="data-import-run-stalled">{{ gp247_language_render($l.'stalled', ['error' => (string) $job->last_error]) }}</x-gp247::alert>
            @if ($canWork)
                <div class="flex gap-2">
                    <x-gp247::button wire:click="retry" data-testid="data-import-run-retry">{{ gp247_language_render($l.'retry') }}</x-gp247::button>
                    @unless (\App\GP247\Plugins\DataImport\Application\ImportService::stalledWhileWriting($job))
                        <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-run-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
                    @endunless
                </div>
            @endif
            @break
        @default
            @if ($sliced)
                @include('Plugins/DataImport::Admin.partials.step_progress')
            @else
                @include('Plugins/DataImport::Admin.partials.step_result')
            @endif
    @endswitch
</div>
