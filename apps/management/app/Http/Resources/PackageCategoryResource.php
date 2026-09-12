<?php

namespace App\Http\Resources;

use App\Models\PackageCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PackageCategory
 */
class PackageCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_th' => $this->name_th,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'description' => $this->description,
            'attribute_definitions' => $this->attribute_definitions,
            'schema_version' => $this->schema_version,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'packages_count' => $this->whenCounted('packages'),
        ];
    }
}
