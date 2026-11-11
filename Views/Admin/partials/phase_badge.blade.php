{{--
    Phase of a run as a colored badge.

    @aidlc-unit data-import
    @aidlc-story US-data-import-shared-host-run

    Variables: $phase (JobPhase value).
--}}
@php($phaseColors = ['committed' => 'green', 'reviewed' => 'blue', 'staged' => 'amber', 'stalled' => 'red', 'cancelled' => 'gray', 'undone' => 'gray', 'uploading' => 'amber'])
<x-gp247::badge :color="$phaseColors[$phase] ?? 'blue'" data-testid="data-import-run-phase">{{ gp247_language_render('Plugins/DataImport::lang.phase.'.$phase) }}</x-gp247::badge>
