<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already guarded by auth + policy; admins may write.
        return (bool) $this->user()?->canEditBusiness();
    }

    protected function prepareForValidation(): void
    {
        // Keep the form contract aligned with the database defaults so older
        // admin clients can create a package without catalog-only fields.
        $defaults = [];
        if (! $this->filled('item_type')) {
            $defaults['item_type'] = 'service';
        }
        if (! $this->filled('availability')) {
            $profile = $this->input('profile', $this->route('package')?->profile);
            $defaults['availability'] = $profile ? 'unknown' : ($this->route('package')?->availability ?? 'available');
        }
        if ($defaults !== []) {
            $this->merge($defaults);
        }

        if (blank($this->currency)) {
            $this->merge(['currency' => 'THB']);
        }

        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->code))]);
        }

        if ($this->exists('attributes')) {
            $this->merge(['attributes' => app(\App\Services\Catalog\AttributeValidator::class)->decode($this->input('attributes'))]);
        }
        if ($this->exists('profile_data')) {
            $this->merge(['profile_data' => app(\App\Services\Catalog\AttributeValidator::class)->decode($this->input('profile_data'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'exists:package_categories,id'],
            'item_type' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'record_kind' => ['sometimes', Rule::in(['group', 'variant', 'offer'])],
            'profile' => ['nullable', Rule::in(array_keys(app(\App\Services\Catalog\CatalogProfiles::class)->schemas()))],
            'profile_data' => ['nullable', 'array', 'max:40'],
            'parent_id' => ['nullable', 'integer', 'exists:packages,id'],
            'code' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('packages', 'code')->ignore($this->route('package')),
            ],
            'name_th' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_th' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'transaction_type' => ['nullable', Rule::in(['sale', 'rent', 'service'])],
            'availability' => ['required', Rule::in(['unknown', 'available', 'reserved', 'sold', 'rented', 'unavailable'])],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'subdistrict' => ['nullable', 'string', 'max:100'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'primary_image_url' => ['nullable', 'url:https', 'max:2048'],
            'map_url' => ['nullable', 'url:https', 'max:2048'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:99'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:99'],
            'usable_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'land_area_sqw' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
            'attributes' => ['nullable', 'array', 'max:40'],
            'is_active' => ['boolean'],
            'is_published' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            // Older direct admin clients did not submit a version. The
            // controller uses the freshly route-bound current version in that
            // compatibility case; new forms submit this field for optimistic
            // conflict detection.
            'lock_version' => $this->route('package') ? ['nullable', 'integer', 'min:1'] : ['sometimes', 'prohibited'],
        ];
    }
}
