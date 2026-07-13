<?php
/**
 * TC_Airtable_Field_Mapper
 *
 * Issue #517 - slice 1 of 3. Pure helper that translates Airtable
 * field schemas into Gravity Tables column definitions.
 *
 * Slices 2 and 3 add the HTTP client (PAT auth + rate-limit-aware
 * fetch) and the admin UI / caching / error surface.
 *
 * Mapping table (canonical):
 *
 *   Airtable type            → GT type     | readonly?
 *   ─────────────────────────┼─────────────┼──────────
 *   singleLineText           → text        | no
 *   multilineText            → text        | no
 *   richText                 → text        | no
 *   phoneNumber              → text        | no
 *   barcode                  → text        | no
 *   button                   → text        | no
 *   number                   → number      | no
 *   currency                 → number      | no
 *   percent                  → number      | no
 *   duration                 → number      | no
 *   rating                   → number      | no
 *   autoNumber               → number      | YES
 *   checkbox                 → toggle      | no
 *   singleSelect             → select      | no
 *   multipleSelects          → multiselect | no
 *   date                     → date        | no
 *   dateTime                 → date        | no
 *   createdTime              → date        | YES
 *   lastModifiedTime         → date        | YES
 *   multipleAttachments      → image       | no
 *   multipleRecordLinks      → text        | no  (looked-up primary)
 *   formula                  → text        | YES
 *   rollup                   → text        | YES
 *   lookup                   → text        | YES
 *   count                    → text        | YES
 *   email                    → email       | no
 *   url                      → url         | no
 *   (anything else)          → text        | no  (defensive)
 *
 * @since 4.7.35
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Airtable_Field_Mapper {

    private const MAP = [
        'singleLineText'      => 'text',
        'multilineText'       => 'text',
        'richText'            => 'text',
        'phoneNumber'         => 'text',
        'barcode'             => 'text',
        'button'              => 'text',
        'number'              => 'number',
        'currency'            => 'number',
        'percent'             => 'number',
        'duration'            => 'number',
        'rating'              => 'number',
        'autoNumber'          => 'number',
        'checkbox'            => 'toggle',
        'singleSelect'        => 'select',
        'multipleSelects'     => 'multiselect',
        'date'                => 'date',
        'dateTime'            => 'date',
        'createdTime'         => 'date',
        'lastModifiedTime'    => 'date',
        'multipleAttachments' => 'image',
        'multipleRecordLinks' => 'text',
        'formula'             => 'text',
        'rollup'              => 'text',
        'lookup'              => 'text',
        'count'               => 'text',
        'email'               => 'email',
        'url'                 => 'url',
    ];

    private const READONLY_TYPES = [
        'formula', 'rollup', 'lookup', 'count',
        'autoNumber', 'createdTime', 'lastModifiedTime',
    ];

    public static function map_field_type(string $airtable_type): string {
        return self::MAP[$airtable_type] ?? 'text';
    }

    public static function is_readonly_field(string $airtable_type): bool {
        return in_array($airtable_type, self::READONLY_TYPES, true);
    }

    public static function map_field(array $airtable_field): array {
        $type = (string) ($airtable_field['type'] ?? '');
        return [
            'id'          => (string) ($airtable_field['id'] ?? ''),
            'label'       => (string) ($airtable_field['name'] ?? ''),
            'type'        => self::map_field_type($type),
            'is_readonly' => self::is_readonly_field($type),
            'source_type' => $type,
        ];
    }

    public static function map_table_schema(array $airtable_table): array {
        $fields = isset($airtable_table['fields']) && is_array($airtable_table['fields'])
            ? $airtable_table['fields']
            : [];
        $cols = [];
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $cols[] = self::map_field($f);
        }
        return $cols;
    }
}
