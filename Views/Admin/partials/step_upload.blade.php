{{--
    Upload in progress: the parts still missing, and the uploader to send them again.

    @aidlc-unit data-import
    @aidlc-story US-data-import-upload-source
--}}
<x-gp247::card :title="gp247_language_render($l.'upload.resume_title')" data-testid="data-import-upload-resume">
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render($l.'upload.resume_help', ['missing' => (string) count($missing)]) }}</p>
    @if ($canWork)
        @include('Plugins/DataImport::Admin.partials.uploader', ['resumeJobId' => $job->id, 'testid' => 'data-import-upload-resume', 'inputCls' => $inputCls])
        <div class="mt-4">
            <x-gp247::button variant="secondary" wire:click="cancel" wire:confirm="{{ gp247_language_render($l.'cancel_confirm') }}" data-testid="data-import-upload-cancel">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
        </div>
    @endif
</x-gp247::card>
