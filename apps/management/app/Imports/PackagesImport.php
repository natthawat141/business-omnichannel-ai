<?php

namespace App\Imports;

use App\Models\ServicePackage;
use App\Models\PackageCategory;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PackagesImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use SkipsFailures;

    public int $imported = 0;
    public int $skipped = 0;

    /** @var array<string, true> */
    private array $seenCodes;

    public function __construct()
    {
        $this->seenCodes = ServicePackage::query()
            ->whereNotNull('code')
            ->pluck('code')
            ->mapWithKeys(fn (string $code) => [mb_strtoupper(trim($code)) => true])
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function prepareForValidation(array $data, int $index): array
    {
        $data['code'] = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $data['effective_from'] = $this->date($data['effective_from'] ?? null);
        $data['effective_until'] = $this->date($data['effective_until'] ?? null);
        $data['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)->decode($data['attributes'] ?? null);

        return $data;
    }

    /** @param array<string, mixed> $row */
    public function model(array $row): ?Model
    {
        $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));

        if (isset($this->seenCodes[$code])) {
            $this->skipped++;
            return null;
        }

        $this->seenCodes[$code] = true;
        $this->imported++;

        $categorySlug = $this->str($row['category_slug'] ?? null);
        $transactionType = $this->str($row['transaction_type'] ?? null);
        $isProperty = $categorySlug !== null || in_array($transactionType, ['sale', 'rent'], true);

        return new ServicePackage([
            'category_id' => $categorySlug !== null
                ? PackageCategory::query()->where('slug', $categorySlug)->value('id')
                : null,
            'item_type' => $isProperty ? 'property' : 'service',
            'code' => $code,
            'name_th' => $this->str($row['name_th'] ?? null),
            'description_th' => $this->str($row['description_th'] ?? null),
            'price' => $this->num($row['price'] ?? null),
            'sale_price' => $this->num($row['sale_price'] ?? null),
            'currency' => 'THB',
            'transaction_type' => $transactionType,
            'availability' => $this->str($row['availability'] ?? null) ?? 'available',
            'location_text' => $this->str($row['location_text'] ?? null),
            'province' => $this->str($row['province'] ?? null),
            'district' => $this->str($row['district'] ?? null),
            'subdistrict' => $this->str($row['subdistrict'] ?? null),
            'project_name' => $this->str($row['project_name'] ?? null),
            'bedrooms' => $this->num($row['bedrooms'] ?? null),
            'bathrooms' => $this->num($row['bathrooms'] ?? null),
            'usable_area_sqm' => $this->num($row['usable_area_sqm'] ?? null),
            'land_area_sqw' => $this->num($row['land_area_sqw'] ?? null),
            'floor' => $this->num($row['floor'] ?? null),
            'primary_image_url' => $this->str($row['primary_image_url'] ?? null),
            'effective_from' => $this->date($row['effective_from'] ?? null),
            'effective_until' => $this->date($row['effective_until'] ?? null),
            'terms' => $this->str($row['terms'] ?? null),
            'keywords' => $this->str($row['keywords'] ?? null),
            'attributes' => $row['attributes'] ?? null,
            'is_active' => true,
            'is_published' => false,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $index => $row) {
                try {
                    app(\App\Services\Catalog\AttributeValidator::class)->values(
                        $row['attributes'] ?? null,
                        ! empty($row['category_slug']) ? PackageCategory::where('slug', $row['category_slug'])->first() : null,
                    );
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $validator->errors()->add($index.'.'.$field, $messages[0]);
                    }
                }
            }
        });
    }

    private function str(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function num(mixed $value): ?float
    {
        $value = $this->str($value);
        return $value === null ? null : (float) $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value)
            ? Date::excelToDateTimeObject((float) $value)->format('Y-m-d')
            : trim((string) $value);
    }
}
