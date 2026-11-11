{{--
    A phase worked through in slices (reading, planning, writing, undoing): what is done so far.
    The screen pulls the next slice every 2 s; closing it pauses the run, opening it again resumes.

    @aidlc-unit data-import
    @aidlc-story US-data-import-shared-host-run
--}}
@php($percent = $progress['total'] > 0 ? (int) floor(100 * $progress['done'] / $progress['total']) : null)
<x-gp247::card :title="gp247_language_render($l.'progress.'.$job->phase)" data-testid="data-import-progress">
    <p class="text-sm text-gray-700 dark:text-gray-200" data-testid="data-import-progress-count">
        @if ($percent === null)
            {{ gp247_language_render($l.'progress.lines', ['count' => number_format($progress['done'])]) }}
        @else
            {{ gp247_language_render($l.'progress.done_of', ['done' => number_format($progress['done']), 'total' => number_format($progress['total'])]) }} ({{ $percent }}%)
        @endif
    </p>
    @if ($percent !== null)
        <div class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
            <div class="h-2 rounded-full bg-blue-600" style="width: {{ $percent }}%"></div>
        </div>
    @endif
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'progress.keep_open') }}</p>
    @if ($canWork && in_array($job->phase, ['staging', 'planning'], true))
        <div class="mt-4">
            <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-progress-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
        </div>
    @endif
</x-gp247::card>
