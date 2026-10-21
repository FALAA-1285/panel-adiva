<?php

namespace App\Http\Requests;

use App\Models\CrmCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Catat order manual" (OrderNew.dc.html), the panel's CATAT <nama> | item | total |
 * kurir | alamat.
 */
class CatatOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:crm_customer,id'],
            'item' => ['required', 'array', 'min:1'],
            'item.*.nama' => ['required', 'string', 'max:255'],
            'item.*.qty' => ['required', 'integer', 'min:1'],
            'item.*.harga' => ['required', 'integer', 'min:0'],
            'total' => ['nullable', 'integer', 'min:0'],
            'kurir' => ['nullable', 'string', 'max:100'],
            'alamat' => ['nullable', 'string', 'max:255'],
            'tambahan' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pilih customer dari CRM.',
            'customer_id.exists' => 'Pilih customer dari CRM.',
            'item.required' => 'Tambahkan minimal satu item.',
            'item.min' => 'Tambahkan minimal satu item.',
            'item.*.nama.required' => 'Isi nama item.',
            'item.*.qty.*' => 'Qty minimal 1.',
            'item.*.harga.*' => 'Isi harga satuan.',
        ];
    }

    /**
     * The total may only add to the items (shipping typed in by the admin), and a
     * customer with an order still running needs "Pesanan tambahan terpisah".
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('total') && $this->integer('total') < $this->jumlahItem()) {
                    $validator->errors()->add('total', 'Total tidak boleh kurang dari jumlah item.');
                }

                $berjalan = $this->customer()?->orderBerjalan();

                if ($berjalan && ! $this->boolean('tambahan')) {
                    $validator->errors()->add('tambahan', "Customer masih punya order berjalan ({$berjalan->order_id}). Centang pesanan tambahan untuk tetap mencatat.");
                }
            },
        ];
    }

    public function customer(): ?CrmCustomer
    {
        return CrmCustomer::query()->find($this->integer('customer_id'));
    }

    /**
     * @return list<array{nama: string, qty: int, harga: int}>
     */
    public function item(): array
    {
        return array_values(array_map(fn (array $baris) => [
            'nama' => trim((string) $baris['nama']),
            'qty' => (int) $baris['qty'],
            'harga' => (int) $baris['harga'],
        ], (array) $this->validated('item')));
    }

    public function jumlahItem(): int
    {
        return array_sum(array_map(fn (mixed $baris) => (int) ($baris['qty'] ?? 0) * (int) ($baris['harga'] ?? 0), (array) $this->input('item', [])));
    }
}
