{{--
    Review before writing: counts per action (and warnings), the rows by action with a search on
    the key, a row's before → after, the error report, then "Approve and write" on the plan shown
    (its fingerprint is checked on the server), with the error rows skipped only when asked.
    Rows and values are shown only to an admin allowed to import this kind of data.

    @aidlc-unit data-import
    @aidlc-story US-data-import-change-review
    @aidlc-story US-data-import-pre-change-snapshot
    @aidlc-adr data-import_staged-review-commit
--}}
@php($problems = (int) ($counts['error'] ?? 0) + (int) ($counts['conflict'] ?? 0))
@php($writes = (int) ($counts['create'] ?? 0) + (int) ($counts['update'] ?? 0))
@php($cardColors = ['create' => 'emerald', 'update' => 'sky', 'skip' => 'sky', 'error' => 'amber', 'conflict' => 'amber', 'warned' => 'amber'])
<div class="grid grid-cols-1 gap-4 md:grid-cols-3" data-testid="data-import-review-summary">
    @foreach (['create' => 'fas fa-plus', 'update' => 'fas fa-pen', 'skip' => 'fas fa-equals', 'error' => 'fas fa-times', 'conflict' => 'fas fa-code-branch', 'warned' => 'fas fa-exclamation-triangle'] as $key => $icon)
        <x-gp247::stat-card :label="gp247_language_render($l.'action.'.$key)" :value="number_format((int) ($counts[$key] ?? 0))" :icon="$icon" :color="$cardColors[$key]" data-testid="data-import-review-count-{{ $key }}" />
    @endforeach
</div>

@if ($canWork && $rows !== null)
    <x-gp247::card :title="gp247_language_render($l.'review.rows')">
        <div class="mb-3 grid grid-cols-1 gap-2 md:grid-cols-3">
            <select wire:model.live="filter" class="{{ $inputCls }}" data-testid="data-import-review-filter">
                <option value="">{{ gp247_language_render($l.'review.all_rows') }}</option>
                @foreach (['create', 'update', 'skip', 'error', 'conflict'] as $key)
                    <option value="{{ $key }}">{{ gp247_language_render($l.'action.'.$key) }} ({{ (int) ($counts[$key] ?? 0) }})</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.500ms="searchSourceKey" placeholder="{{ gp247_language_render($l.'review.search') }}" class="{{ $inputCls }}" data-testid="data-import-review-search" />
            @if ($problems > 0)
                <div class="flex items-center">
                    <x-gp247::button variant="outline" wire:click="downloadErrors" data-testid="data-import-review-download-errors">{{ gp247_language_render($l.'review.download_errors') }}</x-gp247::button>
                </div>
            @endif
        </div>
        <x-gp247::table data-testid="data-import-review-table"
            :headers="[gp247_language_render($l.'review.col_line'), gp247_language_render($l.'review.col_key'), gp247_language_render($l.'review.col_action'), gp247_language_render($l.'review.col_summary'), '']"
            :empty="$rows->isEmpty() ? gp247_language_render($l.'review.no_rows') : null">
            @foreach ($rows as $row)
                <tr wire:key="row-{{ $row->id }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/50" data-testid="data-import-review-row">
                    <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ $row->line_no }}</td>
                    <td class="px-4 py-2 text-sm font-medium text-gray-800 dark:text-gray-100">{{ \Illuminate\Support\Str::limit((string) $row->source_key, 60) }}</td>
                    <td class="px-4 py-2">@include('Plugins/DataImport::Admin.partials.action_badge', ['action' => $row->action])</td>
                    <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">
                        @if (!empty($row->errors))
                            <span class="text-red-600">{{ \Illuminate\Support\Str::limit(collect((array) $row->errors)->flatten()->implode('; '), 160) }}</span>
                        @elseif (!empty($row->diff))
                            {{ gp247_language_render($l.'review.changed_fields', ['fields' => \Illuminate\Support\Str::limit(implode(', ', array_keys((array) $row->diff)), 160)]) }}
                        @endif
                        @if (!empty($row->warnings))
                            <span class="block text-amber-700 dark:text-amber-400">{{ \Illuminate\Support\Str::limit(implode('; ', array_map('strval', (array) $row->warnings)), 160) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        <x-gp247::button size="sm" variant="ghost" wire:click="showRow({{ (int) $row->id }})" data-testid="data-import-review-row-open">{{ gp247_language_render($l.'review.details') }}</x-gp247::button>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>
        <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>

    <x-gp247::card :title="gp247_language_render($l.'review.decide')">
        <p class="mb-3 text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'review.will_write', ['count' => number_format($writes)]) }}</p>
        <div class="space-y-3">
            @if ($problems > 0)
                <x-gp247::checkbox wire:model="skipInvalid" :label="gp247_language_render($l.'review.skip_invalid', ['count' => (string) $problems])" data-testid="data-import-review-skip-invalid" />
            @endif
            <x-gp247::checkbox wire:model="emitEvents" :label="gp247_language_render($l.'review.emit_events')" :help="gp247_language_render($l.'review.emit_events_help')" data-testid="data-import-review-emit-events" />
            @if ($preChangeStopped)
                <x-gp247::checkbox wire:model="confirmPreChange" :label="gp247_language_render($l.'review.confirm_pre_change')" data-testid="data-import-review-confirm-pre-change" />
            @endif
        </div>
        @if ($approveError !== '')
            <x-gp247::alert type="error" class="mt-4" data-testid="data-import-review-errors-block">{{ $approveError }}</x-gp247::alert>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            <x-gp247::button wire:click="approve('{{ $job->review_fingerprint }}')" wire:confirm="{{ gp247_language_render($l.'review.approve_confirm', ['count' => number_format($writes)]) }}" data-testid="data-import-review-approve">{{ gp247_language_render($l.'review.approve') }}</x-gp247::button>
            <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-review-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
        </div>
    </x-gp247::card>

    <x-gp247::modal name="data-import-row" :title="gp247_language_render($l.'review.details')">
        @if ($detailRow)
            <div data-testid="data-import-review-diff" class="space-y-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render($l.'review.col_line') }} {{ $detailRow->line_no }} · {{ $detailRow->source_key }} · @include('Plugins/DataImport::Admin.partials.action_badge', ['action' => $detailRow->action])</p>
                @if (!empty($detailRow->diff))
                    <x-gp247::table :headers="[gp247_language_render($l.'review.col_field'), gp247_language_render($l.'review.col_before'), gp247_language_render($l.'review.col_after')]">
                        @foreach ((array) $detailRow->diff as $field => $change)
                            <tr class="align-top">
                                <td class="px-4 py-2 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $field }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit(is_array($change) ? (is_scalar($change['before'] ?? null) ? (string) $change['before'] : (string) json_encode($change['before'] ?? '', JSON_UNESCAPED_UNICODE)) : '', 300) }}</td>
                                <td class="px-4 py-2 text-xs text-gray-800 dark:text-gray-100">{{ \Illuminate\Support\Str::limit(is_array($change) ? (is_scalar($change['after'] ?? null) ? (string) $change['after'] : (string) json_encode($change['after'] ?? '', JSON_UNESCAPED_UNICODE)) : (string) $change, 300) }}</td>
                            </tr>
                        @endforeach
                    </x-gp247::table>
                @endif
                @foreach ((array) $detailRow->errors as $field => $messages)
                    <p class="text-sm text-red-600">{{ $field === '_row' ? '' : $field . ': ' }}{{ implode('; ', array_map('strval', (array) $messages)) }}</p>
                @endforeach
                @foreach ((array) $detailRow->warnings as $warning)
                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ (string) $warning }}</p>
                @endforeach
            </div>
        @endif
    </x-gp247::modal>
@endif
