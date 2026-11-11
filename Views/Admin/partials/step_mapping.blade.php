{{--
    Preview & mapping: the first lines read with the chosen character set and separator (change
    them and read again), then a GP247 field per column — by hand or from a preset of another
    platform — and the run options. Nothing is read into the run before "Start reading".

    @aidlc-unit data-import
    @aidlc-story US-data-import-encoding-detection
    @aidlc-story US-data-import-column-mapping
--}}
@if ($canWork)
<x-gp247::card :title="gp247_language_render($l.'preview.title')" :subtitle="gp247_language_render($l.'preview.help')">
        <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <label class="block text-sm text-gray-700 dark:text-gray-200">
                <span class="mb-1 block font-medium">{{ gp247_language_render($l.'preview.encoding') }}</span>
                <select wire:model="encoding" class="{{ $inputCls }}" data-testid="data-import-preview-encoding">
                    @foreach ($encodings as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-700 dark:text-gray-200">
                <span class="mb-1 block font-medium">{{ gp247_language_render($l.'preview.delimiter') }}</span>
                <select wire:model="delimiter" class="{{ $inputCls }}" data-testid="data-import-preview-delimiter">
                    @foreach ($delimiters as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <x-gp247::button variant="secondary" wire:click="applyReading" data-testid="data-import-preview-apply">{{ gp247_language_render($l.'preview.apply') }}</x-gp247::button>
            </div>
        </div>
    <x-gp247::table data-testid="data-import-preview-table" :headers="$headers">
        @foreach ($preview as $line)
            <tr class="align-top">
                @foreach ($headers as $i => $header)
                    <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit((string) ($line[$i] ?? ''), 80) }}</td>
                @endforeach
            </tr>
        @endforeach
    </x-gp247::table>
</x-gp247::card>

    <x-gp247::card :title="gp247_language_render($l.'mapping.title')" :subtitle="gp247_language_render($l.'mapping.help', ['required' => implode(' · ', $required)])">
        @if (count($presets) > 0)
            <div class="mb-4 flex flex-wrap items-end gap-2">
                <label class="block text-sm text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block font-medium">{{ gp247_language_render($l.'mapping.preset') }}</span>
                    <select wire:model="preset" class="{{ $inputCls }}" data-testid="data-import-mapping-preset">
                        <option value="">{{ gp247_language_render($l.'mapping.preset_none') }}</option>
                        @foreach ($presets as $key => $presetInfo)
                            <option value="{{ $key }}">{{ $presetInfo['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <x-gp247::button variant="secondary" wire:click="applyPreset" data-testid="data-import-mapping-preset-apply">{{ gp247_language_render($l.'mapping.preset_apply') }}</x-gp247::button>
            </div>
            @if ($preset !== '' && isset($presets[$preset]))
                <x-gp247::alert type="info" data-testid="data-import-mapping-preset-help">{{ $presets[$preset]['help'] }}</x-gp247::alert>
            @endif
            @if (count($presetMissing) > 0)
                <x-gp247::alert type="warning" data-testid="data-import-mapping-preset-missing">{{ gp247_language_render($l.'mapping.preset_missing', ['columns' => implode(', ', $presetMissing)]) }}</x-gp247::alert>
            @endif
        @endif

        <x-gp247::table :headers="[gp247_language_render($l.'mapping.col_source'), gp247_language_render($l.'mapping.col_sample'), gp247_language_render($l.'mapping.col_field')]">
            @foreach ($headers as $i => $header)
                <tr wire:key="map-{{ $i }}">
                    <td class="px-4 py-2 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $header }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit((string) ($preview[0][$i] ?? ''), 40) }}</td>
                    <td class="px-4 py-2">
                        <select wire:model="columns.{{ $i }}" class="{{ $inputCls }}" data-testid="data-import-mapping-column-{{ \Illuminate\Support\Str::slug($header) ?: $i }}">
                            <option value="">{{ gp247_language_render($l.'mapping.skip_column') }}</option>
                            @foreach ($targets as $field)
                                <option value="{{ $field }}">{{ $field }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-gp247::checkbox wire:model="updateExisting" :label="gp247_language_render($l.'mapping.update_existing')" data-testid="data-import-mapping-update-existing" />
            <label class="block text-sm text-gray-700 dark:text-gray-200">
                <span class="mb-1 block font-medium">{{ gp247_language_render($l.'mapping.language') }}</span>
                <select wire:model="language" class="{{ $inputCls }}" data-testid="data-import-mapping-language">
                    <option value="">{{ gp247_language_render($l.'mapping.language_default') }}</option>
                    @foreach ($languages as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($mappingError !== '')
            <x-gp247::alert type="error" class="mt-4" data-testid="data-import-mapping-error">{{ $mappingError }}</x-gp247::alert>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            <x-gp247::button wire:click="startReading" data-testid="data-import-mapping-start">{{ gp247_language_render($l.'mapping.start') }}</x-gp247::button>
            <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-mapping-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
        </div>
    </x-gp247::card>
@endif
