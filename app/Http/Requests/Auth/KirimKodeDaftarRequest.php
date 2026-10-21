<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class KirimKodeDaftarRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'nomor_wa' => ['required', 'string', 'max:30'],
            // "Minimal 8 karakter, campur huruf dan angka."
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'setuju' => ['accepted'],
        ];
    }
}
