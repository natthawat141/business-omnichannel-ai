<?php

namespace App\Exports;

use App\Models\ServicePackage;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports packages using the exact heading order that PackagesImport reads,
 * so a downloaded sheet can be edited and re-imported.
 */
class PackagesExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @return Collection<int, ServicePackage>
     */
    public function collection(): Collection
    {
        return ServicePackage::query()->with('category')->orderBy('name_th')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return \App\Services\PackageImportPreview::COLUMNS;
    }

    /**
     * @param  ServicePackage  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->code,
            $row->category?->slug,
            $row->transaction_type,
            $row->availability,
            $row->name_th,
            $row->description_th,
            $row->price,
            $row->sale_price,
            $row->location_text,
            $row->province,
            $row->district,
            $row->subdistrict,
            $row->project_name,
            $row->bedrooms,
            $row->bathrooms,
            $row->usable_area_sqm,
            $row->land_area_sqw,
            $row->floor,
            $row->primary_image_url,
            $row->effective_from?->format('Y-m-d'),
            $row->effective_until?->format('Y-m-d'),
            $row->terms,
            $row->keywords,
            $row->attributes === null ? null : json_encode($row->attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
