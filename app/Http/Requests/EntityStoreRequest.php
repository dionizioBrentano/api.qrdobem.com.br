<?php

namespace App\Http\Requests;

use App\Models\EntityHealthField;
use App\Models\EntityObjectField;
use App\Models\EntityPetField;
use Illuminate\Contracts\Validation\Validator;
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

            'health_fields' => 'nullable|array',
            'health_fields.*.field_key' => 'required|string|in:' . implode(',', EntityHealthField::FIELD_KEYS),
            'health_fields.*.field_value' => 'nullable|string',
            'health_fields.*.is_public' => 'nullable|boolean',

            // Trilha Pet
            'pet_fields' => 'nullable|array',
            'pet_fields.species' => 'required_with:pet_fields|string|in:' . implode(',', EntityPetField::SPECIES),
            'pet_fields.species_other_description' => 'nullable|string|max:255|required_if:pet_fields.species,other',
            'pet_fields.size' => 'nullable|string|in:' . implode(',', EntityPetField::SIZES),
            'pet_fields.color' => 'nullable|string|max:255',
            'pet_fields.is_neutered' => 'nullable|string|in:' . implode(',', EntityPetField::NEUTERED_STATES),
            'pet_fields.physical_description' => 'nullable|string',
            'pet_fields.clinical_notes' => 'nullable|string',
            'pet_fields.reference_contact' => 'nullable|string|max:255',
            'pet_fields.size_is_public' => 'nullable|boolean',
            'pet_fields.color_is_public' => 'nullable|boolean',
            'pet_fields.is_neutered_is_public' => 'nullable|boolean',
            'pet_fields.physical_description_is_public' => 'nullable|boolean',
            'pet_fields.clinical_notes_is_public' => 'nullable|boolean',
            'pet_fields.reference_contact_is_public' => 'nullable|boolean',
            'pet_fields.vaccinations_is_public' => 'nullable|boolean',

            'vaccinations' => 'nullable|array',
            'vaccinations.*.vaccine_name' => 'required|string|max:255',
            'vaccinations.*.applied_at' => 'required|date_format:Y-m-d',

            // Trilha Objeto
            'object_fields' => 'nullable|array',
            'object_fields.description' => 'nullable|string',
            'object_fields.description_is_public' => 'nullable|boolean',
            'object_fields.public_label' => 'nullable|string|max:' . EntityObjectField::PUBLIC_LABEL_MAX,
            'object_fields.handling_fragile' => 'nullable|boolean',
            'object_fields.handling_light_sensitive' => 'nullable|boolean',
            'object_fields.handling_keep_refrigerated' => 'nullable|boolean',
            'object_fields.handling_do_not_invert' => 'nullable|boolean',
            'object_fields.handling_sentimental_value' => 'nullable|boolean',
            'object_fields.handling_notes_extra' => 'nullable|string',
        ];
    }

    /**
     * Dois campos nunca podem ser públicos: só aparecem sob emergência declarada.
     * A regra vive aqui para o cliente receber 422 antes de qualquer persistência.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('health_fields', []) as $index => $field) {
                $key = $field['field_key'] ?? null;

                if (!in_array($key, EntityHealthField::ALWAYS_RESTRICTED, true)) {
                    continue;
                }

                if (filter_var($field['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $validator->errors()->add(
                        "health_fields.{$index}.is_public",
                        'Este dado não pode ser público. Ele só é exibido sob emergência declarada.'
                    );
                }
            }
        });
    }
}
