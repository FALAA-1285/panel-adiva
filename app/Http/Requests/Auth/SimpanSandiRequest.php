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
            // the panel's one password rule (AppServiceProvider: Password::defaults)
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'keluarkan' => ['boolean'],
        ];
    }
}
