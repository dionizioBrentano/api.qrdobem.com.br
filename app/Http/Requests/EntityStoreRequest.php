<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EntityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:person,pet,object',
            'name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'medical_info' => 'nullable|string',
            'additional_info' => 'nullable|string',
        ];
    }
}
