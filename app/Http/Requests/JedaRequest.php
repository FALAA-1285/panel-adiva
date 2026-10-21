<?php

namespace App\Http\Requests;

use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Support\NomorWa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'durasi' => ['required', Rule::in(array_keys(self::DURASI))],
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
            },
        ];
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
        return self::DURASI[(string) $this->validated('durasi')];
    }
}
