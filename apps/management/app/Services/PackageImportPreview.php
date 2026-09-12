<?php

namespace App\Services;

use App\Models\ServicePackage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

class PackageImportPreview
{
    public const LEGACY_COLUMNS = [
        'code',
        'name_th',
        'description_th',
        'price',
        'sale_price',
        'effective_from',
        'effective_until',
        'terms',
        'keywords',
    ];

    public const PROPERTY_COLUMNS = [
        'code',
        'category_slug',
        'transaction_type',
        'availability',
        'name_th',
        'description_th',
        'price',
        'sale_price',
        'location_text',
        'province',
        'district',
        'subdistrict',
        'project_name',
        'bedrooms',
        'bathrooms',
        'usable_area_sqm',
        'land_area_sqw',
        'floor',
        'primary_image_url',
        'effective_from',
        'effective_until',
        'terms',
        'keywords',
    ];

    public const COLUMNS = [...self::PROPERTY_COLUMNS, 'attributes'];

    /**
     * @return array{new_count: int, duplicate_count: int, invalid_count: int, rows: array<int, array<string, mixed>>}
     */
    public function analyze(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, false);
        $headings = array_map(fn (mixed $value) => $this->heading($value), array_shift($rawRows) ?? []);

        if ($headings !== self::COLUMNS && $headings !== self::PROPERTY_COLUMNS && $headings !== self::LEGACY_COLUMNS) {
            throw new RuntimeException('หัวตารางไม่ถูกต้อง กรุณาดาวน์โหลดไฟล์ต้นแบบใหม่และห้ามเปลี่ยนชื่อหรือสลับคอลัมน์');
        }

        $existing = ServicePackage::query()
            ->whereNotNull('code')
            ->pluck('code')
            ->mapWithKeys(fn (string $code) => [mb_strtoupper(trim($code)) => true])
            ->all();

        $seen = [];
        $previewRows = [];
        $counts = ['new_count' => 0, 'duplicate_count' => 0, 'invalid_count' => 0];

        foreach ($rawRows as $offset => $values) {
            if (collect($values)->every(fn (mixed $value) => $value === null || trim((string) $value) === '')) {
                continue;
            }

            $rowNumber = $offset + 2;
            $row = array_combine($headings, array_pad(array_slice($values, 0, count($headings)), count($headings), null));
            $row['code'] = mb_strtoupper(trim((string) ($row['code'] ?? '')));
            $row['name_th'] = trim((string) ($row['name_th'] ?? ''));
            $row['effective_from'] = $this->date($row['effective_from'] ?? null);
            $row['effective_until'] = $this->date($row['effective_until'] ?? null);
            $row['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)->decode($row['attributes'] ?? null);

            $validator = Validator::make($row, [
                'code' => ['required', 'string', 'max:60'],
                'name_th' => ['required', 'string', 'max:255'],
                'description_th' => ['nullable', 'string', 'max:5000'],
                'price' => ['nullable', 'numeric', 'min:0'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'category_slug' => ['nullable', 'string', 'max:255', 'exists:package_categories,slug'],
                'transaction_type' => ['nullable', 'in:sale,rent,service'],
                'availability' => ['nullable', 'in:available,reserved,sold,rented,unavailable'],
                'location_text' => ['nullable', 'string', 'max:255'],
                'province' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'subdistrict' => ['nullable', 'string', 'max:100'],
                'project_name' => ['nullable', 'string', 'max:255'],
                'bedrooms' => ['nullable', 'integer', 'min:0', 'max:99'],
                'bathrooms' => ['nullable', 'integer', 'min:0', 'max:99'],
                'usable_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'land_area_sqw' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
                'primary_image_url' => ['nullable', 'url:https', 'max:2048'],
                'effective_from' => ['nullable', 'date'],
                'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
                'terms' => ['nullable', 'string', 'max:5000'],
                'keywords' => ['nullable', 'string', 'max:1000'],
            ]);
            $validator->after(function ($validator) use ($row) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                try {
                    app(\App\Services\Catalog\AttributeValidator::class)->values(
                        $row['attributes'],
                        ! empty($row['category_slug']) ? \App\Models\PackageCategory::where('slug', $row['category_slug'])->first() : null,
                    );
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $validator->errors()->add($field, $messages[0]);
                    }
                }
            });

            if ($validator->fails()) {
                $status = 'invalid';
                $reason = $validator->errors()->first();
                $counts['invalid_count']++;
            } elseif (isset($existing[$row['code']]) || isset($seen[$row['code']])) {
                $status = 'duplicate';
                $reason = 'รหัสนี้มีอยู่แล้ว ระบบจะข้ามและไม่เขียนทับ';
                $counts['duplicate_count']++;
            } else {
                $status = 'new';
                $reason = 'พร้อมเพิ่มเป็นฉบับร่าง';
                $counts['new_count']++;
                $seen[$row['code']] = true;
            }

            if (count($previewRows) < 50) {
                $previewRows[] = [
                    'row' => $rowNumber,
                    'code' => $row['code'],
                    'name_th' => $row['name_th'],
                    'status' => $status,
                    'reason' => $reason,
                ];
            }
        }

        return $counts + ['rows' => $previewRows];
    }

    private function heading(mixed $value): string
    {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $value)));
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }
}
