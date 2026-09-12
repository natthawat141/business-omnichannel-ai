<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaqRequest extends FormRequest
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
            'question_th' => ['required', 'string', 'max:2000'],
            'answer_th' => ['required', 'string', 'max:5000'],
            'question_en' => ['nullable', 'string', 'max:2000'],
            'answer_en' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }
}
