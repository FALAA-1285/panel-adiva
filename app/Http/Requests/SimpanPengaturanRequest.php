<?php

namespace App\Http\Requests;

use App\Support\KunciPengaturan;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanAi;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings.dc.html, "Simpan perubahan": every field on the page at once, named by
 * its CONFIG_TOKO key (AI fields by their PengaturanAi key).
 */
class SimpanPengaturanRequest extends FormRequest
{
    /**
     * CONFIG_TOKO keys edited on the page, in the design's order.
     *
     * @var list<string>
     */
    public const ISIAN = [
        'ADMIN_WA', 'SARAH_WA',
        'NAMA_TOKO', 'REKENING', 'BATAS_BAYAR_JAM', 'BATAS_BAYAR_PICKUP_JAM', 'BATAS_KONFIRMASI_JAM', 'CATATAN_INVOICE', 'LINK_ULASAN',
        'AMBIL_TOKO_SAWOJAJAR', 'AMBIL_PABRIK_LAWANG',
        'JAM_PESAN_OTOMATIS', 'HARI_LIBUR_OTOMATIS', 'JEDA_FOLLOWUP_JAM', 'JEDA_KESAN_HARI',
        'PACKING_PER_KG', 'PACKING_MINIMAL', 'PACKING_GRATIS_DI_ATAS',
        'SESI_UTAMA',
        'ARSIP_ORDER_HARI', 'ARSIP_CUSTOMER_HARI',
    ];

    /**
     * Keys holding a list, stored comma separated.
     *
     * @var list<string>
     */
    public const DAFTAR = ['HARI_LIBUR_OTOMATIS'];

    /**
     * "Tanggal merah ditulis seperti 2026-12-25", or a weekday.
     */
    private const HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    protected function prepareForValidation(): void
    {
        foreach (['ADMIN_WA', 'SARAH_WA'] as $kunci) {
            if (is_string($this->input($kunci)) && $this->input($kunci) !== '') {
                $this->merge([$kunci => NomorWa::normalisasi($this->input($kunci))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $jam = '([01]\d|2[0-3]):[0-5]\d';
        $jumlah = ['required', 'integer', 'min:1'];

        return [
            'ADMIN_WA' => ['required', 'regex:/^62\d{8,13}$/'],
            'SARAH_WA' => ['required', 'regex:/^62\d{8,13}$/'],
            'NAMA_TOKO' => ['required', 'string', 'max:120'],
            'REKENING' => ['nullable', 'string', 'max:300'],
            'BATAS_BAYAR_JAM' => $jumlah,
            'BATAS_BAYAR_PICKUP_JAM' => $jumlah,
            'BATAS_KONFIRMASI_JAM' => $jumlah,
            'CATATAN_INVOICE' => ['nullable', 'string', 'max:500'],
            'LINK_ULASAN' => ['nullable', 'url', 'max:300'],
            'AMBIL_TOKO_SAWOJAJAR' => ['nullable', 'string', 'max:300'],
            'AMBIL_PABRIK_LAWANG' => ['nullable', 'string', 'max:300'],
            'JAM_PESAN_OTOMATIS' => ['required', "regex:/^{$jam}-{$jam}$/"],
            'HARI_LIBUR_OTOMATIS' => ['present', 'array'],
            'HARI_LIBUR_OTOMATIS.*' => ['distinct', function (string $atribut, mixed $nilai, \Closure $gagal) {
                $tanggal = is_string($nilai) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $nilai) : false;

                if (! in_array($nilai, self::HARI, true) && ($tanggal === false || $tanggal->format('Y-m-d') !== $nilai)) {
                    $gagal('Tulis nama hari (Minggu) atau tanggal seperti 2026-12-25.');
                }
            }],
            'JEDA_FOLLOWUP_JAM' => $jumlah,
            'JEDA_KESAN_HARI' => $jumlah,
            // packing of an expedition parcel; empty = no packing fee
            'PACKING_PER_KG' => ['nullable', 'integer', 'min:0'],
            'PACKING_MINIMAL' => ['nullable', 'integer', 'min:0'],
            'PACKING_GRATIS_DI_ATAS' => ['nullable', 'integer', 'min:0'],
            'SESI_UTAMA' => ['required', Rule::in(array_keys(NomorBot::daftar()))],
            'ARSIP_ORDER_HARI' => $jumlah,
            'ARSIP_CUSTOMER_HARI' => $jumlah,
            'ai' => ['required', 'array'],
            'ai.AI_MODEL_UTAMA' => ['required', Rule::in(PengaturanAi::MODEL)],
            'ai.AI_MODEL_CADANGAN' => ['required', Rule::in(PengaturanAi::MODEL)],
            'ai.AI_TEMPERATURE' => ['required', 'numeric', 'between:0,1'],
            'ai.AI_INGATAN_CHAT' => $jumlah,
            'ai.AI_TUNGGU_GABUNG' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * The field names as written on the page.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect(self::ISIAN)
            ->concat(array_keys(PengaturanAi::KUNCI))
            ->mapWithKeys(fn (string $kunci) => [
                (str_starts_with($kunci, 'AI_') ? "ai.{$kunci}" : $kunci) => KunciPengaturan::nama($kunci),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa angka bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'min' => ':attribute minimal :min.',
            'between' => ':attribute harus antara :min dan :max.',
            'max' => ':attribute terlalu panjang.',
            'url' => ':attribute harus berupa tautan lengkap (https://…).',
            'in' => 'Pilihan :attribute tidak dikenal.',
            'distinct' => 'Isian ini dobel.',
            'ADMIN_WA.regex' => 'Tulis nomor WhatsApp seperti 6281234567890.',
            'SARAH_WA.regex' => 'Tulis nomor WhatsApp seperti 6281234567890.',
            'JAM_PESAN_OTOMATIS.regex' => 'Tulis seperti 07:00-21:00.',
        ];
    }
}
