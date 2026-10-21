<?php

namespace App\Http\Requests\Katalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SimpanKebijakanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'topik' => ['required', 'string', 'max:255'],
            'jawaban_resmi' => ['nullable', 'string'],
            'kata_pemicu' => ['array'],
            'kata_pemicu.*' => ['string', 'max:50', 'distinct'],
        ];
    }
}
