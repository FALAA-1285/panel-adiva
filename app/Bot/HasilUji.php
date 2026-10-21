<?php

namespace App\Bot;

/**
 * Adiva's answer to a test question, and how she read it.
 */
final readonly class HasilUji
{
    /**
     * @param  list<string>  $tool  the tools Adiva called, in order ("Cari_Produk_per_Kategori (Keripik kaca)")
     * @param  array{tipe?: string|null, tahap?: string|null, skor?: string|null}|null  $penilaian  how she rated the customer
     * @param  array{butuh_admin?: bool, tahap?: string|null, alasan?: string|null}|null  $hasil  the draft prompt test result
     */
    public function __construct(
        public string $jawaban,
        public array $tool = [],
        public ?array $penilaian = null,
        public ?array $hasil = null,
        public ?int $produkId = null,
    ) {}
}
