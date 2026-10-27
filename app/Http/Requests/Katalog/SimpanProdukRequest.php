<?php

namespace App\Http\Requests\Katalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Katalog, "Tambah produk" and editing a product: the PRODUK columns Adiva reads,
 * and the tiered prices ({satuan, tier: [{min, max, harga}]}).
 */
class SimpanProdukRequest extends FormRequest
{
    /**
     * "Rp15.000" → 15000; empty max or price → null.
     */
    protected function prepareForValidation(): void
    {
        // "Rp 35.000" is 35000; an empty field is no price; text without a digit ("abc")
        // stays as typed so the integer rule refuses it instead of it vanishing
        $angka = function (mixed $nilai): int|string|null {
            $teks = trim((string) $nilai);
            $digit = preg_replace('/\D/', '', $teks) ?? '';

            return match (true) {
                $teks === '' => null,
                $digit === '' => $teks,
                default => (int) $digit,
            };
        };

        $this->merge([
            'tier' => collect((array) $this->input('tier', []))
                ->map(fn ($t) => [
                    'min' => $angka($t['min'] ?? null),
                    'max' => $angka($t['max'] ?? null),
                    'harga' => $angka($t['harga'] ?? null),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jenis_produk' => ['required', 'string', 'max:255'],
            'kategori_produk' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'kategori_kemasan' => ['nullable', 'string', 'max:255'],
            'gramasi' => ['nullable', 'string', 'max:255'],
            'rasa' => ['nullable', 'string'],
            'target_pasar' => ['nullable', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'tier' => ['array', 'max:6'],
            'tier.*.min' => ['required', 'integer', 'min:1'],
            'tier.*.max' => ['nullable', 'integer', 'min:1'],
            'tier.*.harga' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis_produk.required' => 'Isi nama produk.',
            'tier.*.min.required' => 'Isi jumlah minimal tiap tingkat harga.',
            'tier.*.min.integer' => 'Jumlah harus angka.',
            'tier.*.max.integer' => 'Jumlah harus angka.',
            'tier.*.harga.integer' => 'Harga harus angka, misalnya 35000.',
        ];
    }

    /**
     * Tiers go up: each starts after the one before, and only the last may be open-ended.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $tier = (array) $this->input('tier', []);

            foreach ($tier as $i => $t) {
                $sebelum = $tier[$i - 1] ?? null;

                if (($t['max'] ?? null) !== null && $t['max'] < $t['min']) {
                    $validator->errors()->add('tier', 'Batas atas tiap tingkat harus lebih besar dari batas bawahnya.');

                    return;
                }

                if ($sebelum !== null && (($sebelum['max'] ?? null) === null || $t['min'] <= $sebelum['max'])) {
                    $validator->errors()->add('tier', 'Tingkat harga harus berurutan dan tidak tumpang tindih.');

                    return;
                }
            }
        }];
    }

    /**
     * The row as stored.
     *
     * @return array<string, mixed>
     */
    public function isian(): array
    {
        $tier = (array) $this->validated('tier', []);

        return [
            ...$this->safe()->only(['jenis_produk', 'kategori_produk', 'brand', 'kategori_kemasan', 'gramasi', 'rasa', 'target_pasar', 'deskripsi']),
            'harga_bertingkat' => $tier === [] ? null : ['satuan' => $this->validated('satuan') ?: 'pcs', 'tier' => array_values($tier)],
        ];
    }
}
