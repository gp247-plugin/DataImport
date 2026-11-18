{{--
    Result of a finished run (written, undone, cancelled): the counts, the remarks of the write
    (e.g. images not downloaded — host and path only) and the undo while its period lasts.

    @aidlc-unit data-import
    @aidlc-story US-data-import-undo
    @aidlc-story US-data-import-image-fetch
    @aidlc-story US-data-import-legacy-url-redirect
    @aidlc-story US-data-import-seo-meta
--}}
<x-gp247::card :title="gp247_language_render($l.'result.'.$job->phase)" data-testid="data-import-result-summary">
    <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach (['written', 'create', 'update', 'skip', 'error', 'conflict', 'warned', 'image_failed', 'redirects', 'redirect_skipped'] as $key)
            @if (!empty($counts[$key]))
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'action.'.$key) }}</dt>
                    <dd class="text-lg font-semibold text-gray-800 dark:text-gray-100" data-testid="data-import-result-count-{{ str_replace('_', '-', $key) }}">{{ number_format((int) $counts[$key]) }}</dd>
                </div>
            @endif
        @endforeach
    </dl>

    @if (!empty($counts['seo_template']))
        {{-- US-data-import-seo-meta: titles / descriptions that were only template variables of the old SEO plugin. --}}
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300" data-testid="data-import-result-seo-skipped">{{ gp247_language_render($l.'result.seo_skipped', ['count' => number_format((int) $counts['seo_template'])]) }}</p>
    @endif

    @if (count($writeWarnings) > 0)
        <div class="mt-4" data-testid="data-import-result-warnings">
            <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render($l.'result.warnings') }}</h3>
            <ul class="space-y-1 text-xs text-amber-700 dark:text-amber-400">
                @foreach ($writeWarnings as $warning)
                    <li>{{ gp247_language_render($l.'review.col_line') }} {{ (int) ($warning['line'] ?? 0) }}: {{ (string) ($warning['message'] ?? '') }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($job->phase === 'committed' && $job->undo_until)
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render($l.'result.undo_until', ['date' => $job->undo_until->format('Y-m-d H:i')]) }}</p>
        @if ($canUndo)
            @if ($preChangeStopped)
                <div class="mt-3">
                    <x-gp247::checkbox wire:model="confirmPreChange" :label="gp247_language_render($l.'review.confirm_pre_change')" data-testid="data-import-result-confirm-pre-change" />
                </div>
            @endif
            <div class="mt-3">
                <x-gp247::button variant="danger" wire:click="undo" wire:confirm="{{ gp247_language_render($l.'result.undo_confirm') }}" data-testid="data-import-result-undo">{{ gp247_language_render($l.'result.undo') }}</x-gp247::button>
            </div>
        @endif
    @endif
</x-gp247::card>
