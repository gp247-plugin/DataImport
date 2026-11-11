<?php

namespace App\GP247\Plugins\DataImport\Application;

/**
 * Ready-made column mappings (and order status mappings) for the exports of other platforms.
 * Each preset is a JSON data file in the plugin's `Presets/` folder — adding a platform is
 * adding a file, never code. A file whose header equals a GP247 field name (the sample file)
 * needs no preset: such columns map themselves.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-column-mapping, US-data-import-order-status-mapping
 * @aidlc-adr data-import_file-formats
 */
final class Presets
{
    /**
     * Every preset, by file name.
     *
     * @return array<string, array{name: string, help: string, entities: array<string, array{columns?: array<string, string>, statuses?: array<string, array<string, int>>}>}>
     */
    public static function all(): array
    {
        static $presets = null;
        if ($presets !== null) {
            return $presets;
        }
        $presets = [];
        foreach (glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Presets' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['entities']) && is_array($data['entities'])) {
                $presets[basename($file, '.json')] = [
                    'name' => (string) ($data['name'] ?? basename($file, '.json')),
                    'help' => (string) ($data['help'] ?? ''),
                    'entities' => array_intersect_key($data['entities'], array_flip(Handlers::entities())),
                ];
            }
        }
        ksort($presets);

        return $presets;
    }

    /**
     * Apply a preset to the headers of a file.
     *
     * @param string $preset Preset file name, e.g. "shopify".
     * @param string $entity
     * @param array<int, string> $headers Headers of the file.
     * @return array{columns: array<string, string>, missing: array<int, string>, statuses: array<string, array<string, int>>}
     *         Column mapping for the headers the file has, preset columns the file does not have, status mapping.
     */
    public static function apply(string $preset, string $entity, array $headers): array
    {
        $spec = self::all()[$preset]['entities'][$entity] ?? [];
        $byName = [];
        foreach ($headers as $header) {
            $byName[mb_strtolower(trim((string) $header))] ??= (string) $header;
        }
        $columns = [];
        $missing = [];
        foreach ((array) ($spec['columns'] ?? []) as $column => $field) {
            $header = in_array($column, $headers, true) ? $column : ($byName[mb_strtolower($column)] ?? null);
            if ($header === null) {
                $missing[] = (string) $column;
            } else {
                $columns[$header] = (string) $field;
            }
        }

        return ['columns' => $columns, 'missing' => $missing, 'statuses' => (array) ($spec['statuses'] ?? [])];
    }
}
