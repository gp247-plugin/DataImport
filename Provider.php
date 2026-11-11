<?php
/**
 * Boots the Data Import plugin. Everything that runs is registered only while the plugin is
 * active: the CLI command, the write-activity probe (so a backup or restore never runs while a
 * run writes shop data, and the other way round) and the backup exclusion of the temporary
 * tables (uploaded chunks and staged rows hold personal data and can be rebuilt from the file),
 * and the admin screens (views, Livewire namespace, store-scoped admin segment).
 */

$config = json_decode((string) file_get_contents(__DIR__ . '/gp247.json'), true);
$extensionPath = $config['configGroup'] . '/' . $config['configKey'];

$this->loadTranslationsFrom(__DIR__ . '/Lang', $extensionPath);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    $this->mergeConfigFrom(__DIR__ . '/config.php', $extensionPath);
    $this->commands([\App\GP247\Plugins\DataImport\Console\DataImportRun::class]);
    \App\GP247\Plugins\DataImport\Support\Boot::register();
    $this->loadViewsFrom(__DIR__ . '/Views', $extensionPath);
    \App\GP247\Plugins\DataImport\Support\Screens::register();
}
