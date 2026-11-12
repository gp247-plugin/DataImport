{{--
    Data Import — list of runs (a store-admin sees its own store) and the start of a new run:
    kind of data, source name, file sent in parts. Write controls only for admins allowed to
    import at least one kind of data; every action is re-authorized on the server.

    @aidlc-unit data-import
    @aidlc-story US-data-import-access-control
    @aidlc-story US-data-import-upload-source
    @aidlc-story US-data-import-file-format-guide

    Variables: $jobs (paginator of ImportJob), $entities (entity => label), $phases, $showStore, $maxBytes, $format (FileFormat::for() of the chosen kind, or null).
--}}
@php($l = 'Plugins/DataImport::lang.')
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'subtitle') }}</p>

    @if (count($entities) > 0)
        <x-gp247::card :title="gp247_language_render($l.'new_run')" :subtitle="gp247_language_render($l.'new_run_help', ['max' => (string) intdiv($maxBytes, 1048576)])" data-testid="data-import-index-upload">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="block text-sm text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block font-medium">{{ gp247_language_render($l.'field_entity') }}</span>
                    <select wire:model.live="entity" class="{{ $inputCls }}" data-testid="data-import-index-entity">
                        @foreach ($entities as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block font-medium">{{ gp247_language_render($l.'field_source') }}</span>
                    <input type="text" wire:model="source" maxlength="100" class="{{ $inputCls }}" data-testid="data-import-index-source" />
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'field_source_help') }}</span>
                </label>
            </div>
            <div class="mt-4">
                @include('Plugins/DataImport::Admin.partials.uploader', ['resumeJobId' => null, 'testid' => 'data-import-index-upload', 'inputCls' => $inputCls, 'maxBytes' => $maxBytes])
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'import_order') }}</p>
            @if ($format !== null)
                @include('Plugins/DataImport::Admin.partials.file_format', ['format' => $format, 'entityLabel' => $entities[$format['entity']] ?? $format['entity'], 'l' => $l])
            @endif
        </x-gp247::card>
    @else
        <x-gp247::alert type="info" data-testid="data-import-index-readonly">{{ gp247_language_render($l.'readonly_hint') }}</x-gp247::alert>
    @endif

    <x-gp247::card :title="gp247_language_render($l.'runs')">
        <div class="mb-3 flex items-center gap-2">
            <select wire:model.live="phase" class="{{ $inputCls }} max-w-xs" data-testid="data-import-index-phase-filter">
                <option value="">{{ gp247_language_render($l.'all_phases') }}</option>
                @foreach ($phases as $phaseKey)
                    <option value="{{ $phaseKey }}">{{ gp247_language_render($l.'phase.'.$phaseKey) }}</option>
                @endforeach
            </select>
        </div>
        <x-gp247::table data-testid="data-import-index-table"
            :headers="array_values(array_filter([
                gp247_language_render($l.'col_created'),
                gp247_language_render($l.'col_file'),
                gp247_language_render($l.'col_entity'),
                gp247_language_render($l.'col_source'),
                $showStore ? gp247_language_render($l.'col_store') : null,
                gp247_language_render($l.'col_phase'),
                gp247_language_render($l.'col_counts'),
            ]))"
            :empty="$jobs->isEmpty() ? gp247_language_render($l.'no_runs') : null">
            @foreach ($jobs as $run)
                @php($c = (array) $run->counts)
                <tr wire:key="run-{{ $run->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/50" data-testid="data-import-index-row">
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ optional($run->created_at)->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3 text-sm">
                        <a href="{{ gp247_route_admin('admin_data_import.detail', ['id' => $run->id]) }}" class="font-medium text-blue-600 hover:underline" data-testid="data-import-index-open">{{ $run->original_name ?: $run->id }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'entity.'.$run->entity) }}</td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $run->source }}</td>
                    @if ($showStore)
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $run->store_id }}</td>
                    @endif
                    <td class="px-4 py-3">@include('Plugins/DataImport::Admin.partials.phase_badge', ['phase' => $run->phase])</td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300"><div class="flex flex-wrap gap-2">
                        @foreach (['create', 'update', 'skip', 'error', 'conflict'] as $k)
                            @if (!empty($c[$k]))
                                <span>{{ gp247_language_render($l.'action.'.$k) }}: {{ (int) $c[$k] }}</span>
                            @endif
                        @endforeach
                    </div></td>
                </tr>
            @endforeach
        </x-gp247::table>
        <div class="mt-4">{{ $jobs->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>
</div>
