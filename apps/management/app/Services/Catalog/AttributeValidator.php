<?php

namespace App\Services\Catalog;

use App\Models\PackageCategory;
use App\Models\ServicePackage;
use Illuminate\Validation\ValidationException;

/** Shared by model saves, HTTP forms and import preview/confirmation. Never coerces facts. */
class AttributeValidator
{
    public const MAX_ATTRIBUTES = 40;

    private const OPERATORS = [
        'string' => ['eq'], 'integer' => ['eq', 'gte', 'lte'],
        'decimal' => ['eq', 'gte', 'lte'], 'boolean' => ['eq'],
        'enum' => ['eq', 'in'], 'string_list' => ['contains'],
        'numeric_range' => ['overlaps', 'contains'],
    ];

    public function definitions(mixed $definitions): void
    {
        if (! is_array($definitions) || ! array_is_list($definitions) || count($definitions) > self::MAX_ATTRIBUTES) {
            throw ValidationException::withMessages(['attribute_definitions' => 'Definitions must be a list of at most 40 fields.']);
        }
        $errors = [];
        $seen = [];
        $core = [...(new ServicePackage)->getFillable(), 'id', 'created_at', 'updated_at',
            'record_kind', 'parent_id', 'deleted_at', 'lock_version', 'schema_version'];
        foreach ($definitions as $index => $definition) {
            $path = 'attribute_definitions.'.$index;
            if (! is_array($definition) || array_diff(array_keys($definition), [
                'key', 'label', 'type', 'unit', 'nullable', 'allowed_values', 'searchable', 'allowed_operators',
            ])) {
                $errors[$path] = 'Definition contains unsupported fields.';
                continue;
            }
            $key = $definition['key'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $key)
                || in_array($key, $core, true) || isset($seen[$key])) {
                $errors[$path.'.key'] = 'Use a unique attribute key, not a core catalog field.';
            } else {
                $seen[$key] = true;
            }
            if (! $this->text($definition['label'] ?? null, 100) || trim($definition['label']) === '') {
                $errors[$path.'.label'] = 'A label of 1–100 characters is required.';
            }
            $type = $definition['type'] ?? null;
            if (! is_string($type) || ! isset(self::OPERATORS[$type])) {
                $errors[$path.'.type'] = 'Unsupported attribute type.';
                continue;
            }
            foreach (['nullable', 'searchable'] as $flag) {
                if (! is_bool($definition[$flag] ?? null)) {
                    $errors[$path.'.'.$flag] = 'A JSON boolean is required.';
                }
            }
            $unit = $definition['unit'] ?? null;
            if ($unit !== null && (! is_string($unit) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_\/-]{0,39}$/D', $unit))) {
                $errors[$path.'.unit'] = 'Use a stable unit identifier of at most 40 characters.';
            }
            if ($type === 'numeric_range' && $unit === null) {
                $errors[$path.'.unit'] = 'Ranges require an explicit canonical unit.';
            }
            if ($unit !== null && ! in_array($type, ['integer', 'decimal', 'numeric_range'], true)) {
                $errors[$path.'.unit'] = 'Only numeric attributes may define units.';
            }
            $values = $definition['allowed_values'] ?? [];
            if (! $this->stringList($values, 50, 100) || count(array_unique($values)) !== count($values)
                || ($type === 'enum' ? $values === [] : $values !== [])) {
                $errors[$path.'.allowed_values'] = 'Enums require 1–50 distinct string options; other types use an empty list.';
            }
            $operators = $definition['allowed_operators'] ?? [];
            if (! $this->stringList($operators, 3, 20) || array_diff($operators, self::OPERATORS[$type])
                || count(array_unique($operators)) !== count($operators)
                || (($definition['searchable'] ?? false) === false && $operators !== [])) {
                $errors[$path.'.allowed_operators'] = 'Use only supported operators; non-searchable fields use an empty list.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Version 1 retains its original string-only contract; version 2+ rejects unregistered keys. */
    public function values(mixed $values, ?PackageCategory $category): void
    {
        if ($values === null) {
            return;
        }
        $typed = ($category?->schema_version ?? 1) >= 2;
        if (! is_array($values) || ($typed && $values !== [] && array_is_list($values))
            || count($values) > ($typed ? self::MAX_ATTRIBUTES : 30)) {
            throw ValidationException::withMessages(['attributes' => 'Attributes must be a bounded object, not a list.']);
        }
        $definitions = [];
        if ($typed) {
            $this->definitions($category->attribute_definitions ?? []);
            $definitions = array_column($category->attribute_definitions ?? [], null, 'key');
        }
        $errors = [];
        foreach ($values as $key => $value) {
            if ($typed && (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $key))) {
                $errors['attributes'] = 'Invalid attribute key.';
                continue;
            }
            $path = 'attributes.'.$key;
            if (! $typed) {
                if ($value !== null && ! $this->text($value, 500)) {
                    $errors[$path] = 'Legacy attributes accept only strings of at most 500 characters or null.';
                }
                continue;
            }
            if (! isset($definitions[$key])) {
                $errors[$path] = 'Unregistered attribute. Request a reviewed schema addition before using this key.';
                continue;
            }
            $definition = $definitions[$key];
            if ($value === null) {
                if (! $definition['nullable']) {
                    $errors[$path] = 'This attribute does not allow explicit null; omit it if unknown.';
                }
                continue;
            }
            $valid = match ($definition['type']) {
                'string' => $this->text($value, 500),
                'integer' => is_int($value) && abs($value) <= 1000000000000,
                'decimal' => $this->number($value),
                'boolean' => is_bool($value),
                'enum' => in_array($value, $definition['allowed_values'], true),
                'string_list' => $this->stringList($value, 50, 100),
                'numeric_range' => is_array($value) && count($value) === 3
                    && $this->number($value['min'] ?? null) && $this->number($value['max'] ?? null)
                    && $value['min'] <= $value['max'] && ($value['unit'] ?? null) === $definition['unit'],
            };
            if (! $valid) {
                $errors[$path] = 'Value does not match the declared type, bounds or canonical unit.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Decode only explicit JSON strings; invalid JSON remains invalid for the common validator. */
    public function decode(mixed $value): mixed
    {
        if (! is_string($value) || strlen($value) > 131072) {
            return $value;
        }
        if (trim($value) === '') {
            return null;
        }
        try {
            return json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /** Compatibility for the existing seeder's fixed-column descriptors, never an HTTP schema input. */
    public function legacyCoreDefinitions(mixed $definitions): bool
    {
        if (! is_array($definitions) || ! array_is_list($definitions) || $definitions === [] || count($definitions) > 5) {
            return false;
        }
        $seen = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || array_diff(array_keys($definition), ['key', 'label_th', 'type', 'operators', 'searchable', 'unit'])
                || ! is_string($definition['key'] ?? null)
                || ! in_array($definition['key'], ['bedrooms', 'bathrooms', 'usable_area_sqm', 'land_area_sqw', 'floor'], true)
                || isset($seen[$definition['key']]) || ($definition['type'] ?? null) !== 'number'
                || ! $this->text($definition['label_th'] ?? null, 100)
                || ! is_bool($definition['searchable'] ?? null)
                || ! $this->stringList($definition['operators'] ?? null, 3, 10)
                || array_diff($definition['operators'], ['eq', 'gte', 'lte'])) {
                return false;
            }
            $seen[$definition['key']] = true;
            $unit = match ($definition['key']) {
                'usable_area_sqm' => 'sqm', 'land_area_sqw' => 'sqw', default => null,
            };
            if (($definition['unit'] ?? null) !== $unit) {
                return false;
            }
        }
        return true;
    }

    private function text(mixed $value, int $max): bool
    {
        return is_string($value) && mb_strlen($value) <= $max;
    }

    private function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= 1000000000000;
    }

    private function stringList(mixed $values, int $max, int $length): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > $max) {
            return false;
        }
        foreach ($values as $value) {
            if (! $this->text($value, $length) || trim($value) === '') {
                return false;
            }
        }
        return true;
    }
}
