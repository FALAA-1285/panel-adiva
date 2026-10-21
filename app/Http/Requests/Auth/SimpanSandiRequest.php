<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SimpanSandiRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Same rule as Daftar: "Minimal 8 karakter, campur huruf dan angka."
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'keluarkan' => ['boolean'],
        ];
    }
}
