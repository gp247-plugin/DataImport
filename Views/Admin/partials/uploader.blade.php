{{--
    File sent in parts (US-data-import-upload-source). The file is chosen in a drop zone — dragged
    in, or picked with a click (the file input stays, visually hidden, for keyboards and tests); a
    file that is not .csv / .txt is refused at once (the server still checks the content). The
    browser cuts the file into parts the server sized below the host's upload limit and sends one
    part at a time through Livewire's temporary upload; a part that fails is sent again on the next
    try (the server keeps what is missing). New run: $resumeJobId = null (startUpload creates the
    run). Resume: the run's id (uploadState answers what is missing; the same file must be chosen again).

    @aidlc-unit data-import
    @aidlc-story US-data-import-upload-source

    Variables: $resumeJobId (string|null), $testid (prefix of the data-testid), $inputCls (unused, kept for callers), $maxBytes (int|null).
--}}
@php($l = 'Plugins/DataImport::lang.')
@php($maxMb = (string) intdiv((int) ($maxBytes ?? \App\GP247\Plugins\DataImport\Application\ImportService::maxBytes()), 1048576))
<div wire:ignore
    data-msg-failed="{{ gp247_language_render($l.'upload.failed') }}"
    data-msg-other-file="{{ gp247_language_render($l.'upload.other_file') }}"
    data-msg-wrong-type="{{ gp247_language_render($l.'upload.wrong_type') }}"
    x-data="{
        file: null, sent: 0, total: 0, busy: false, error: '', over: false,
        resume: @js($resumeJobId),
        pick(list) {
            const chosen = list && list[0] ? list[0] : null;
            this.error = '';
            if (!chosen) return;
            if (!/\.(csv|txt)$/i.test(chosen.name)) { this.file = null; this.error = this.$root.dataset.msgWrongType; return; }
            this.file = chosen;
        },
        clear() { this.file = null; this.error = ''; this.sent = 0; this.total = 0; this.$refs.input.value = ''; },
        size(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        },
        percent() { return this.total ? Math.floor(100 * this.sent / this.total) : 0; },
        async send() {
            if (!this.file || this.busy) return;
            this.error = ''; this.busy = true;
            try {
                const plan = this.resume ? await $wire.uploadState(this.resume) : await $wire.startUpload(this.file.name, this.file.size);
                if (!plan || plan.error) { this.error = plan ? plan.error : this.$root.dataset.msgFailed; return; }
                if (plan.total && plan.total !== this.file.size) { this.error = this.$root.dataset.msgOtherFile; return; }
                this.total = plan.missing.length; this.sent = 0;
                for (const index of plan.missing) {
                    const start = index * plan.chunk;
                    const part = new File([this.file.slice(start, Math.min(this.file.size, start + plan.chunk))], 'part-' + index + '.csv', { type: 'text/csv' });
                    await new Promise((done, fail) => $wire.upload('chunk', part, done, fail));
                    const r = await $wire.dataImportPart(plan.job, index);
                    if (!r || !r.ok) { this.error = (r && r.error) || this.$root.dataset.msgFailed; return; }
                    this.sent++;
                }
                await $wire.finishUpload(plan.job);
            } catch (e) {
                this.error = this.$root.dataset.msgFailed;
            } finally {
                this.busy = false;
            }
        }
    }" class="space-y-3">
    <label
        x-on:dragover.prevent="over = true"
        x-on:dragleave.prevent="over = false"
        x-on:drop.prevent="over = false; if (!busy) pick($event.dataTransfer.files)"
        x-bind:class="over ? 'border-blue-500 bg-blue-50 dark:bg-gray-700' : (error ? 'border-red-300 bg-red-50 dark:bg-gray-800' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800')"
        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-all duration-200"
        data-testid="{{ $testid }}-dropzone">
        <input type="file" x-ref="input" accept=".csv,.txt,text/csv,text/plain" class="sr-only" x-on:change="pick($event.target.files)" x-bind:disabled="busy" data-testid="{{ $testid }}-file" />
        <i class="fas fa-cloud-upload-alt text-3xl" x-bind:class="over ? 'text-blue-600' : 'text-gray-400'"></i>
        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ gp247_language_render($l.'upload.drop') }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'upload.or_click') }} · {{ gp247_language_render($l.'upload.limit', ['max' => $maxMb]) }}</span>
    </label>

    <div x-show="file" x-cloak class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700" data-testid="{{ $testid }}-selected">
        <i class="fas fa-file-csv text-2xl text-blue-600 shrink-0"></i>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" x-text="file ? file.name : ''"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="file ? size(file.size) : ''"></p>
            <div x-show="busy || sent" class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                <div class="h-2 rounded-full bg-blue-600 transition-all duration-200" x-bind:style="'width: ' + percent() + '%'"></div>
            </div>
        </div>
        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" x-show="busy" x-text="percent() + '%'" data-testid="{{ $testid }}-progress"></span>
        <button type="button" x-on:click="clear()" x-show="!busy" class="shrink-0 text-gray-400" title="{{ gp247_language_render($l.'upload.clear') }}" aria-label="{{ gp247_language_render($l.'upload.clear') }}" data-testid="{{ $testid }}-clear">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="flex items-center gap-2">
        <x-gp247::button x-on:click="send()" x-bind:disabled="!file || busy" data-testid="{{ $testid }}-start">
            {{ gp247_language_render($resumeJobId ? $l.'upload.resume' : $l.'upload.start') }}
        </x-gp247::button>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error" data-testid="{{ $testid }}-error"></p>
</div>
