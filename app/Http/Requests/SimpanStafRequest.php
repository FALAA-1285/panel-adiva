<?php

namespace App\Http\Requests;

use App\Enums\Peran;
use App\Models\User;
use App\Support\NomorWa;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tim & akses, "Undang staf" and "Ubah": who the staff member is and what they may do.
 */
class SimpanStafRequest extends FormRequest
{
    /**
     * The number as stored ("812 3456 0007" → "6281234560007"), before the unique check.
     */
    protected function prepareForValidation(): void
    {
        if (filled($this->input('nomor_wa'))) {
            $this->merge(['nomor_wa' => NomorWa::normalisasi((string) $this->input('nomor_wa'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $id = $user instanceof User ? $user->id : null;

        return [
            'nama' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'nomor_wa' => ['required', 'regex:/^62\d{8,13}$/', Rule::unique('users', 'nomor_wa')->ignore($id)],
            'peran' => ['required', Rule::enum(Peran::class)],
            'cabang' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Isi nama staf.',
            'email.required' => 'Isi email staf.',
            'email.email' => 'Email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai staf lain.',
            'nomor_wa.required' => 'Isi nomor WhatsApp staf.',
            'nomor_wa.regex' => 'Nomor WhatsApp tidak valid.',
            'nomor_wa.unique' => 'Nomor ini sudah dipakai staf lain.',
            'peran.required' => 'Pilih peran.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            // a supervisor cannot take away their own access to Tim & akses
            if ($this->route('user') instanceof User && $this->route('user')->is($this->user())
                && $this->input('peran') !== Peran::Supervisor->value) {
                $validator->errors()->add('peran', 'Peran Anda sendiri tidak bisa diturunkan.');
            }
        }];
    }
}
