{{--
    Order status mapping: every value of the file's status columns with the number of orders
    that have it, each mapped to an existing GP247 status (none is created; an unmapped value
    makes its orders refused rows). The payment status of an order follows the amount received;
    the file's payment status is only compared with it (a warning on the review when they disagree).

    @aidlc-unit data-import
    @aidlc-story US-data-import-order-status-mapping
--}}
@if ($canWork)
    <x-gp247::card :title="gp247_language_render($l.'statuses.title')" :subtitle="gp247_language_render($l.'statuses.help')" data-testid="data-import-status-table">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach ($statusValues as $kind => $values)
                <div data-testid="data-import-status-kind-{{ str_replace('_', '-', $kind) }}">
                    <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render($l.'statuses.kind.'.$kind) }}</h3>
                    @forelse (array_keys($values) as $i => $value)
                        <label class="mb-2 block text-sm text-gray-700 dark:text-gray-200" wire:key="st-{{ $kind }}-{{ $i }}">
                            <span class="mb-1 block">
                                <span class="font-medium">{{ $value === '' ? gp247_language_render($l.'statuses.empty_value') : $value }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">({{ gp247_language_render($l.'statuses.orders', ['count' => (string) $values[$value]]) }})</span>
                            </span>
                            <select wire:model="statusChoice.{{ $kind }}.{{ $i }}" class="{{ $inputCls }}" data-testid="data-import-status-select">
                                <option value="">{{ gp247_language_render($l.'statuses.unmapped') }}</option>
                                @foreach ($statusOptions[$kind] ?? [] as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @empty
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'statuses.no_column') }}</p>
                    @endforelse
                </div>
            @endforeach
        </div>

        @if ($mappingError !== '')
            <x-gp247::alert type="error" class="mt-4" data-testid="data-import-status-error">{{ $mappingError }}</x-gp247::alert>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            <x-gp247::button wire:click="saveStatuses" data-testid="data-import-status-save">{{ gp247_language_render($l.'statuses.save') }}</x-gp247::button>
            <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-status-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
        </div>
    </x-gp247::card>
@endif
