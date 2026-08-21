<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'avatar' => 'foto de perfil',
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'Debes seleccionar una imagen.',
            'avatar.image' => 'El archivo debe ser una imagen.',
            'avatar.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'avatar.max' => 'La imagen no puede superar los 2 MB.',
        ];
    }
}
