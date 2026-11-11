<?php
/**
 * Admin routes of the Data Import plugin (only while the plugin is active).
 */
$config = json_decode((string) file_get_contents(__DIR__ . '/gp247.json'), true);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    \App\GP247\Plugins\DataImport\Support\Routes::register();
}
