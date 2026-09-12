<?php

namespace App\Services\Catalog;

use Illuminate\Validation\ValidationException;

/** Optional, server-owned schemas. New industries add profiles, not business columns. */
class CatalogProfiles
{
    public const FACILITIES = ['lobby', 'pool', 'gym', 'fitness_studio', 'spa', 'salon',
        'garden', 'lounge', 'concierge', 'security', 'parking', 'jacuzzi', 'sky_bar',
        'private_pods', 'coworking', 'playground', 'sauna', 'ev_charging'];

    public function schemas(): array
    {
        $text = ['type' => 'string', 'max_length' => 500, 'nullable' => true];
        $count = ['type' => 'integer', 'min' => 0, 'max' => 1000000, 'nullable' => true];
        $date = ['type' => 'date', 'format' => 'YYYY-MM-DD', 'nullable' => true];
        return [
            'property_project' => [
                'version' => 1, 'label' => 'โครงการอสังหาริมทรัพย์', 'record_kind' => 'group',
                'core_fields' => ['name_th', 'name_en', 'location_text', 'province', 'district', 'subdistrict'],
                'fields' => [
                    'developer' => $text, 'operator' => $text, 'brand' => $text,
                    'project_type' => ['type' => 'enum', 'values' => ['high_rise_condominium', 'low_rise_condominium', 'housing_estate', 'mixed_use', 'commercial', 'other'], 'nullable' => true],
                    'latitude' => ['type' => 'number', 'min' => -90, 'max' => 90, 'nullable' => true],
                    'longitude' => ['type' => 'number', 'min' => -180, 'max' => 180, 'nullable' => true],
                    'construction_status' => ['type' => 'enum', 'values' => ['unknown', 'planned', 'under_construction', 'completed', 'on_hold', 'cancelled'], 'nullable' => true],
                    'construction_status_as_of' => $date,
                    'expected_completion' => ['type' => 'month', 'format' => 'YYYY-MM', 'nullable' => true],
                    'facilities' => ['type' => 'enum_list', 'values' => self::FACILITIES, 'max_items' => 30, 'nullable' => true],
                    'building_count' => $count, 'storey_count' => $count, 'unit_count' => $count,
                    'parking_spaces' => $count, 'passenger_lifts' => $count, 'service_lifts' => $count,
                    'document_reviewed_on' => $date, 'source_note' => $text,
                ],
            ],
            'property_layout' => [
                'version' => 1, 'label' => 'แบบห้อง', 'record_kind' => 'variant',
                'core_fields' => ['name_th', 'name_en', 'bedrooms', 'parent_id'],
                'fields' => [
                    'layout_type' => ['type' => 'enum', 'values' => ['studio', 'one_bedroom', 'one_bedroom_plus', 'two_bedroom', 'three_bedroom', 'penthouse', 'other'], 'nullable' => true],
                    'area_range' => ['type' => 'numeric_range', 'min' => 0, 'max' => 1000000, 'unit' => 'sqm', 'nullable' => true],
                    'document_reviewed_on' => $date, 'source_note' => $text,
                ],
            ],
        ];
    }

    /** Validate the effective record, including fields retained by a partial update. */
    public function validate(array $record): void
    {
        $profile = $record['profile'] ?? null;
        $data = $record['profile_data'] ?? null;
        if ($profile === null) {
            if ($data !== null && $data !== []) {
                throw ValidationException::withMessages(['profile_data' => 'Choose a registered profile before providing profile data.']);
            }
            return;
        }
        $schemas = $this->schemas();
        if (! is_string($profile) || ! isset($schemas[$profile])) {
            throw ValidationException::withMessages(['profile' => 'Unknown catalog profile. Read agent_schema for registered profiles.']);
        }
        $schema = $schemas[$profile];
        $errors = [];
        if (($record['record_kind'] ?? 'offer') !== $schema['record_kind']) {
            $errors['profile'] = 'This profile requires record_kind='.$schema['record_kind'].'.';
        }
        foreach (['price', 'sale_price'] as $field) {
            if (($record[$field] ?? null) !== null) $errors[$field] = 'Price belongs to an actual offer, not a project or layout.';
        }
        if (! in_array($record['availability'] ?? null, [null, 'unknown', 'unavailable'], true)) {
            $errors['availability'] = 'Project/layout facts do not establish offer availability. Use unknown.';
        }
        if ($data !== null && (! is_array($data) || ($data !== [] && array_is_list($data)) || count($data) > 40)) {
            $errors['profile_data'] = 'Profile data must be an object with at most 40 fields.';
        } else {
            foreach ($data ?? [] as $key => $value) {
                $field = $schema['fields'][$key] ?? null;
                if ($field === null) {
                    $errors['profile_data.'.$key] = 'Unknown profile field. Read the registered schema.';
                } elseif ($value !== null && ! $this->matches($value, $field)) {
                    $errors['profile_data.'.$key] = 'Value does not match profile type, bounds or allowed values.';
                }
            }
            if ($profile === 'property_project' && ! in_array($data['construction_status'] ?? null, [null, 'unknown'], true)
                && empty($data['construction_status_as_of'])) {
                $errors['profile_data.construction_status_as_of'] = 'A known construction status requires its evidence date.';
            }
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    private function matches(mixed $value, array $field): bool
    {
        return match ($field['type']) {
            'string' => is_string($value) && mb_strlen($value) <= $field['max_length'],
            'integer' => is_int($value) && $value >= $field['min'] && $value <= $field['max'],
            'number' => $this->number($value, $field),
            'enum' => is_string($value) && in_array($value, $field['values'], true),
            'date' => $this->date($value),
            'month' => is_string($value) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) === 1,
            'enum_list' => is_array($value) && array_is_list($value) && count($value) <= $field['max_items']
                && collect($value)->every(fn ($item) => is_string($item) && in_array($item, $field['values'], true))
                && count(array_unique($value)) === count($value),
            'numeric_range' => is_array($value) && count($value) === 3 && ($value['unit'] ?? null) === $field['unit']
                && $this->number($value['min'] ?? null, $field) && $this->number($value['max'] ?? null, $field)
                && $value['min'] <= $value['max'],
        };
    }

    private function number(mixed $value, array $field): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value >= $field['min'] && $value <= $field['max'];
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) !== 1) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
