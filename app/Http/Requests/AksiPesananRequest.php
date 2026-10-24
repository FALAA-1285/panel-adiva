<?php

namespace App\Http\Requests;

use App\Enums\AksiPesanan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A command button on Pesanan. Whether it applies to the order and the role is
 * checked by PerintahPesanan.
 */
class AksiPesananRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aksi' => ['required', Rule::enum(AksiPesanan::class)],
            // "Catatan, nomor resi, atau alasan": the receipt number for KIRIM (none for the
            // company's own courier), the shipping cost for ACC, the reason for TOLAK / BATAL
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function aksi(): AksiPesanan
    {
        return AksiPesanan::from((string) $this->validated('aksi'));
    }
}
