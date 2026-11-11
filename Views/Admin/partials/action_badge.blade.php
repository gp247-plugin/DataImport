{{--
    Planned action of a staged row as a colored badge.

    @aidlc-unit data-import
    @aidlc-story US-data-import-change-review

    Variables: $action (RowAction value).
--}}
@php($actionColors = ['create' => 'green', 'update' => 'blue', 'skip' => 'gray', 'error' => 'red', 'conflict' => 'amber', 'part' => 'gray'])
<x-gp247::badge :color="$actionColors[$action] ?? 'gray'">{{ gp247_language_render('Plugins/DataImport::lang.action.'.$action) }}</x-gp247::badge>
