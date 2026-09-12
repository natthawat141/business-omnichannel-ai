<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already guarded by auth + policy; admins may write.
        return (bool) $this->user()?->canEditBusiness();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'type' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:500'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'version' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'reviewed_at' => ['nullable', 'date'],
        ];
    }
}
