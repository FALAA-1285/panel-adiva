<?php

namespace App\Http\Requests\Katalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autosave from the "Detail foto" panel: one or a few fields at a time.
 */
class UbahGambarRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'produk_id' => ['sometimes', 'nullable', 'integer', 'exists:produk,id'],
            'tags' => ['sometimes', 'nullable', 'string', 'max:500'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }
}
