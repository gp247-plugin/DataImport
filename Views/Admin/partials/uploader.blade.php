{{--
    File sent in parts (US-data-import-upload-source). The browser cuts the file into parts the
    server sized below the host's upload limit and sends one part at a time through Livewire's
    temporary upload; a part that fails is sent again on the next try (the server keeps what is
    missing). New run: $resumeJobId = null (startUpload creates the run). Resume: the run's id
    (uploadState answers what is missing; the same file must be chosen again).

    @aidlc-unit data-import
    @aidlc-story US-data-import-upload-source

    Variables: $resumeJobId (string|null), $testid (prefix of the data-testid), $inputCls.
--}}
@php($l = 'Plugins/DataImport::lang.')
<div wire:ignore
    data-msg-failed="{{ gp247_language_render($l.'upload.failed') }}"
    data-msg-other-file="{{ gp247_language_render($l.'upload.other_file') }}"
    x-data="{
        file: null, sent: 0, total: 0, busy: false, error: '',
        resume: @js($resumeJobId),
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
    <input type="file" accept=".csv,.txt,text/csv,text/plain" x-on:change="file = $event.target.files[0] || null" class="{{ $inputCls }}" data-testid="{{ $testid }}-file" />
    <div class="flex items-center gap-2">
        <x-gp247::button x-on:click="send()" x-bind:disabled="!file || busy" data-testid="{{ $testid }}-start">
            {{ gp247_language_render($resumeJobId ? $l.'upload.resume' : $l.'upload.start') }}
        </x-gp247::button>
        <span class="text-xs text-gray-500 dark:text-gray-400" x-show="busy" x-text="total ? Math.floor(100 * sent / total) + '%' : ''" data-testid="{{ $testid }}-progress"></span>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error" data-testid="{{ $testid }}-error"></p>
</div>
