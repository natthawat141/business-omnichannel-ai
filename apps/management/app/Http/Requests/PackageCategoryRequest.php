<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already guarded by auth + policy; admins may write.
        return (bool) $this->user()?->canEditBusiness()
            && (! $this->exists('attribute_definitions') || (bool) $this->user()?->is_admin);
    }

    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator) {
            if (! $this->exists('attribute_definitions') || $validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                app(\App\Services\Catalog\AttributeValidator::class)->definitions($this->input('attribute_definitions'));
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = is_object($category) ? $category->id : $category;

        return [
            'name_th' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('package_categories', 'slug')->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:5000'],
            'attribute_definitions' => ['sometimes', 'array', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
