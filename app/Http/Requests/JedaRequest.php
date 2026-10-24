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
            'jumlah' => ['nullable', 'integer', 'min:0'],
            'satuan' => ['nullable', 'string', 'in:detik,menit,jam,hari'],
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
                } elseif (JedaBot::query()->menahan()->where('chat_id', $customer->chat_id)->exists()) {
                    $validator->errors()->add('customer', 'Customer ini sedang dijeda.');
                }

                if (! $this->isTanpaBatas() && $this->detik() === null) {
                    $validator->errors()->add('durasi', 'Pilih durasi jeda yang valid atau centang Tanpa batas.');
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

    /**
     * Total pause duration in seconds (null: tanpa batas).
     */
    public function detik(): ?int
    {
        if ($this->isTanpaBatas()) {
            return null;
        }

        if ($this->filled('jumlah') && $this->filled('satuan')) {
            $jml = (int) $this->input('jumlah');
            if ($jml <= 0) {
                return null;
            }

            return match ($this->input('satuan')) {
                'detik' => $jml,
                'menit' => $jml * 60,
                'jam' => $jml * 3600,
                'hari' => $jml * 86400,
                default => $jml * 60,
            };
        }

        if ($this->has('jam') || $this->has('menit')) {
            $totalMnt = ((int) $this->input('jam', 0) * 60) + (int) $this->input('menit', 0);

            return $totalMnt > 0 ? $totalMnt * 60 : null;
        }

        $durasi = trim((string) $this->input('durasi'));
        if (array_key_exists($durasi, self::DURASI)) {
            $mnt = self::DURASI[$durasi];

            return $mnt === null ? null : $mnt * 60;
        }

        if (is_numeric($durasi)) {
            $val = (int) $durasi;

            return $val > 0 ? $val * 60 : null;
        }

        $totalDetik = 0;
        if (preg_match('/(\d+)\s*hari/i', $durasi, $m)) {
            $totalDetik += (int) $m[1] * 86400;
        }
        if (preg_match('/(\d+)\s*jam/i', $durasi, $m)) {
            $totalDetik += (int) $m[1] * 3600;
        }
        if (preg_match('/(\d+)\s*menit/i', $durasi, $m)) {
            $totalDetik += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)\s*detik/i', $durasi, $m)) {
            $totalDetik += (int) $m[1];
        }

        return $totalDetik > 0 ? $totalDetik : null;
    }

    /**
     * Total pause duration in minutes (legacy).
     */
    public function menit(): ?int
    {
        $detik = $this->detik();

        return $detik === null ? null : (int) ceil($detik / 60);
    }
}
