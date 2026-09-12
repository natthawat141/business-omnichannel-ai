<?php

namespace App\Http\Resources;

use App\Models\ServicePackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServicePackage
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'item_type' => $this->item_type,
            'record_kind' => $this->record_kind,
            'profile' => $this->profile,
            'profile_data' => $this->profile_data,
            'parent_id' => $this->parent_id,
            'lock_version' => $this->lock_version,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name_th' => $this->category->name_th,
            ]),
            'code' => $this->code,
            'name_th' => $this->name_th,
            'name_en' => $this->name_en,
            'description_th' => $this->description_th,
            'description_en' => $this->description_en,
            'price' => $this->price !== null ? (float) $this->price : null,
            'sale_price' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'currency' => $this->currency,
            'transaction_type' => $this->transaction_type,
            'availability' => $this->availability,
            'duration_minutes' => $this->duration_minutes,
            'terms' => $this->terms,
            'keywords' => $this->keywords,
            'location_text' => $this->location_text,
            'province' => $this->province,
            'district' => $this->district,
            'subdistrict' => $this->subdistrict,
            'project_name' => $this->project_name,
            'primary_image_url' => $this->primary_image_url,
            'map_url' => $this->map_url,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'usable_area_sqm' => $this->usable_area_sqm !== null ? (float) $this->usable_area_sqm : null,
            'land_area_sqw' => $this->land_area_sqw !== null ? (float) $this->land_area_sqw : null,
            'floor' => $this->floor,
            'attributes' => $this->attributes,
            'is_active' => (bool) $this->is_active,
            'is_published' => (bool) $this->is_published,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'effective_until' => $this->effective_until?->format('Y-m-d'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
