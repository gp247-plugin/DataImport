<?php

namespace App\GP247\Plugins\DataImport\Application;

use Illuminate\Support\Facades\Lang;

/**
 * What a file of one kind of data must look like, for the new-import screen: every column the
 * kind accepts, which ones are required (the same rule the column mapping enforces), what each
 * means and an example taken from the plugin's sample file of that kind. Columns, required
 * groups and examples come from the code and the sample, so the guide cannot drift from what an
 * import really accepts; only the texts live in the language files.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-file-format-guide
 */
final class FileFormat
{
    private const LANG = 'Plugins/DataImport::lang.';

    /**
     * The format of one kind of data.
     *
     * @param string $entity One of Handlers::entities().
     * @return array{entity: string, sample: string, notes: list<string>,
     *               columns: list<array{field: string, required: bool, one_of: list<string>, multilingual: bool, description: string, example: string}>}
     * @throws \InvalidArgumentException On an unknown entity.
     */
    public static function for(string $entity): array
    {
        $handler = Handlers::for($entity);
        $examples = self::examples($entity);
        $required = [];
        foreach ($handler->requiredFields() as $alternatives) {
            foreach ($alternatives as $field) {
                $required[$field] = count($alternatives) > 1 ? array_values($alternatives) : [];
            }
        }
        $multilingual = array_flip($handler->textFields());

        // WHY this order: required columns first, then the ones the sample file uses, then the rest —
        // the user sees what they must provide before what they may provide.
        $fields = array_values(array_unique(array_merge(
            array_keys($required),
            array_keys($examples),
            ['key'],
            $handler->textFields(),
            $handler->fields()
        )));
        $accepted = array_flip(array_merge(['key'], $handler->fields(), $handler->textFields()));

        $columns = [];
        foreach ($fields as $field) {
            if (!isset($accepted[$field])) {
                continue;
            }
            $columns[] = [
                'field' => $field,
                'required' => array_key_exists($field, $required),
                'one_of' => $required[$field] ?? [],
                'multilingual' => isset($multilingual[$field]),
                'description' => self::description($entity, $field),
                'example' => (string) ($examples[$field] ?? ''),
            ];
        }

        return [
            'entity' => $entity,
            'sample' => $entity . '.csv',
            'notes' => self::notes($entity),
            'columns' => $columns,
        ];
    }

    /**
     * File of the sample of a kind of data (shipped with the plugin).
     *
     * @param string $entity
     * @return string
     * @throws \InvalidArgumentException On an unknown entity.
     */
    public static function samplePath(string $entity): string
    {
        if (!in_array($entity, Handlers::entities(), true)) {
            throw new \InvalidArgumentException('Unknown entity: ' . $entity);
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . $entity . '.csv';
    }

    /**
     * Header => value of the first data line of the sample file.
     *
     * @param string $entity
     * @return array<string, string>
     */
    private static function examples(string $entity): array
    {
        $path = self::samplePath($entity);
        if (!is_file($path)) {
            return [];
        }
        $bom = str_starts_with((string) file_get_contents($path, false, null, 0, 3), "\xEF\xBB\xBF") ? 3 : 0;
        $reader = new CsvStreamReader($path, 'UTF-8', ',', $bom);
        $header = $reader->next();
        $first = $reader->next();
        if ($header === null || $first === null || $header['error'] !== null || $first['error'] !== null) {
            return [];
        }
        $out = [];
        foreach ($header['cells'] as $index => $name) {
            $out[trim((string) $name)] = trim((string) ($first['cells'][$index] ?? ''));
        }

        return $out;
    }

    /**
     * What a field means: the kind's own text when the meaning differs, else the shared one.
     *
     * @param string $entity
     * @param string $field
     * @return string
     */
    private static function description(string $entity, string $field): string
    {
        $own = self::LANG . 'format.entity_field.' . $entity . '.' . $field;

        return (string) trans(Lang::has($own) ? $own : self::LANG . 'format.field.' . $field);
    }

    /**
     * Notes of a kind of data, in the site language.
     *
     * @param string $entity
     * @return list<string>
     */
    private static function notes(string $entity): array
    {
        $notes = trans(self::LANG . 'format.note.' . $entity);

        return is_array($notes) ? array_values(array_map('strval', $notes)) : [];
    }
}
