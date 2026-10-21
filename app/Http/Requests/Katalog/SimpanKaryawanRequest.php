<?php

namespace App\Http\Requests\Katalog;

use App\Models\Karyawan;
use App\Support\NomorWa;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Program karyawan, "Tambah karyawan" and editing one: Adiva gives employee prices only
 * to numbers on this list, so the number is stored the way n8n matches it (62…).
 */
class SimpanKaryawanRequest extends FormRequest
{
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
        $karyawan = $this->route('karyawan');

        return [
            'nama' => ['required', 'string', 'max:100'],
            'nomor_wa' => ['required', 'regex:/^62\d{8,13}$/', Rule::unique('karyawan', 'nomor_wa')->ignore($karyawan instanceof Karyawan ? $karyawan->id : null)],
            'bagian' => ['nullable', 'string', 'max:100'],
            'aktif' => ['boolean'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Isi nama karyawan.',
            'nomor_wa.required' => 'Isi nomor WhatsApp karyawan.',
            'nomor_wa.regex' => 'Nomor WhatsApp tidak valid.',
            'nomor_wa.unique' => 'Nomor ini sudah ada di daftar karyawan.',
        ];
    }
}
