<?php

namespace App\Http\Requests\Katalog;

use App\Models\PaketReseller;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanPaketRequest extends FormRequest
{
    /**
     * Prepare the data for validation: "Rp240.000" → 240000.
     */
    protected function prepareForValidation(): void
    {
        $harga = preg_replace('/\D/', '', (string) $this->input('harga'));

        $this->merge(['harga' => $harga === '' ? null : (int) $harga]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // null for "Tambah paket"
        $paket = $this->route('paket');

        return [
            'kode_paket' => ['required', 'string', 'max:20', Rule::unique('paket_reseller', 'kode_paket')->ignore($paket instanceof PaketReseller ? $paket->id : null)],
            'nama_paket' => ['required', 'string', 'max:255'],
            'isi_detail' => ['array'],
            'isi_detail.*.nama' => ['required', 'string', 'max:255'],
            'isi_detail.*.jumlah' => ['required', 'string', 'max:30'],
            'harga' => ['nullable', 'integer', 'min:0'],
            'berat' => ['nullable', 'string', 'max:100'],
            'tawarkan_ketika' => ['nullable', 'string'],
            'aktif' => ['boolean'],
        ];
    }
}
