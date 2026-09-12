<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BusinessProfileRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'business_description' => ['required', 'string', 'max:2000'],
            'services_offered' => ['nullable', 'string', 'max:5000'],
            'service_areas' => ['nullable', 'string', 'max:5000'],
            'business_hours' => ['nullable', 'string', 'max:255'],
            'contact_channels' => ['nullable', 'string', 'max:5000'],
            'conversation_tone' => ['nullable', 'string', 'max:255'],
            'always_escalate_topics' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
