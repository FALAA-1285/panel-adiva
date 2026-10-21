<?php

namespace App\Http\Requests;

use App\Support\PengaturanKeamanan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKeamananRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kode_perangkat_baru' => ['sometimes', 'boolean'],
            'keluar_otomatis' => ['sometimes', 'integer', Rule::in(PengaturanKeamanan::PILIHAN_JAM)],
        ];
    }
}
