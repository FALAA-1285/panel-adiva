<?php

namespace App\Http\Requests;

use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Support\NomorWa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Jeda customer" (Jeda.dc.html), the panel's JEDA <nama> <durasi> | <alasan>.
 */
class JedaRequest extends FormRequest
{
    /**
     * The duration choices and their minutes (null: tanpa batas).
     *
     * @var array<string, int|null>
     */
    public const DURASI = ['30 menit' => 30, '1 jam' => 60, '2 jam' => 120, '1 hari' => 1440, 'Tanpa batas' => null];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer' => ['required', 'string', 'max:255'],
            'durasi' => ['nullable', 'string', 'max:255'],
            'tanpa_batas' => ['nullable', 'boolean'],
            'jam' => ['nullable', 'integer', 'min:0', 'max:720'],
            'menit' => ['nullable', 'integer', 'min:0', 'max:59'],
            'alasan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.required' => 'Isi nama atau nomor WA customer.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $customer = $this->customer();

                if (! $customer) {
                    $validator->errors()->add('customer', 'Customer tidak ditemukan di CRM.');
                } elseif (JedaBot::query()->aktif()->where('chat_id', $customer->chat_id)->exists()) {
                    $validator->errors()->add('customer', 'Customer ini sedang dijeda.');
                }

                if (! $this->isTanpaBatas() && $this->menit() === null) {
                    $validator->errors()->add('durasi', 'Pilih durasi jeda minimal 1 menit atau centang Tanpa batas.');
                }
            },
        ];
    }

    /**
     * Whether the pause is without limit.
     */
    public function isTanpaBatas(): bool
    {
        return $this->boolean('tanpa_batas') || $this->input('durasi') === 'Tanpa batas';
    }

    /**
     * The customer by exact name, or by WhatsApp number.
     */
    public function customer(): ?CrmCustomer
    {
        $isi = trim((string) $this->input('customer'));
        $angka = preg_replace('/\D/', '', $isi) ?? '';

        return CrmCustomer::query()
            ->where('nama', 'ilike', addcslashes($isi, '%_\\'))
            ->when(strlen($angka) >= 8, fn ($q) => $q->orWhere('nomor_wa', NomorWa::normalisasi($angka)))
            ->first();
    }

    public function menit(): ?int
    {
        if ($this->isTanpaBatas()) {
            return null;
        }

        if ($this->has('jam') || $this->has('menit')) {
            $total = ((int) $this->input('jam', 0) * 60) + (int) $this->input('menit', 0);

            return $total > 0 ? $total : null;
        }

        $durasi = trim((string) $this->input('durasi'));
        if (array_key_exists($durasi, self::DURASI)) {
            return self::DURASI[$durasi];
        }

        if (is_numeric($durasi)) {
            $val = (int) $durasi;

            return $val > 0 ? $val : null;
        }

        $jam = 0;
        $menit = 0;
        if (preg_match('/(\d+)\s*jam/i', $durasi, $m)) {
            $jam = (int) $m[1];
        }
        if (preg_match('/(\d+)\s*menit/i', $durasi, $m)) {
            $menit = (int) $m[1];
        }
        $total = ($jam * 60) + $menit;

        return $total > 0 ? $total : null;
    }
}
