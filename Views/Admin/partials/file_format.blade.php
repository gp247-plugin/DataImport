{{--
    File format of the chosen kind of data, on the new-import screen: every column it accepts
    (required ones first, with their alternatives), what each means, an example from the sample
    file, notes of that kind, the sample file to download and a hint about ready-made mappings.
    Built by Application\FileFormat from the handler and the sample — not typed twice.

    @aidlc-unit data-import
    @aidlc-story US-data-import-file-format-guide

    Variables: $format (FileFormat::for()), $entityLabel, $l (language prefix).
--}}
<div x-data="{ open: true }" class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700" data-testid="data-import-index-format">
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
        <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open" class="flex items-center gap-2 text-left text-sm font-semibold text-gray-800 dark:text-gray-100" data-testid="data-import-index-format-toggle">
            <i class="fas fa-chevron-down text-xs text-gray-400 transition-all duration-200" x-bind:class="open ? '' : '-rotate-90'"></i>
            {{ gp247_language_render($l.'format.title', ['entity' => $entityLabel]) }}
        </button>
        <x-gp247::button variant="secondary" wire:click="downloadSample" data-testid="data-import-index-format-download">
            <i class="fas fa-download"></i> {{ gp247_language_render($l.'format.download') }}
        </x-gp247::button>
    </div>
    <div x-show="open" class="space-y-3 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'format.help') }}</p>

        <div class="max-h-96 overflow-y-auto">
            <x-gp247::table :headers="[
                gp247_language_render($l.'format.col_column'),
                gp247_language_render($l.'format.col_required'),
                gp247_language_render($l.'format.col_meaning'),
                gp247_language_render($l.'format.col_example'),
            ]">
                @foreach ($format['columns'] as $column)
                    <tr class="align-top" data-testid="data-import-index-format-row">
                        <td class="px-4 py-2 whitespace-nowrap font-mono text-xs text-gray-800 dark:text-gray-100">{{ $column['field'] }}</td>
                        <td class="px-4 py-2 text-xs">
                            @if ($column['required'])
                                <x-gp247::badge color="red">{{ gp247_language_render($l.'format.required') }}</x-gp247::badge>
                                @if ($column['one_of'] !== [])
                                    <span class="mt-1 block text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'format.one_of', ['fields' => implode(' / ', $column['one_of'])]) }}</span>
                                @endif
                            @else
                                <span class="text-gray-400">{{ gp247_language_render($l.'format.optional') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200">
                            {{ $column['description'] }}
                            @if ($column['multilingual'])
                                <span class="mt-1 block text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'format.multilingual', ['suffix' => ':<lang>', 'example' => $column['field'].':en']) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 break-all font-mono text-xs text-gray-600 dark:text-gray-300">{{ $column['example'] }}</td>
                    </tr>
                @endforeach
            </x-gp247::table>
        </div>

        @if ($format['notes'] !== [])
            <div class="text-xs text-gray-700 dark:text-gray-200">
                <p class="font-semibold">{{ gp247_language_render($l.'format.notes') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($format['notes'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <p class="text-xs text-gray-500 dark:text-gray-400">💡 {{ gp247_language_render($l.'format.presets_hint') }}</p>
    </div>
</div>
