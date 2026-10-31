<?php

namespace App\Accurate;

/**
 * What the panel reads from Accurate (database "Bana Food"). Reading only: nothing here,
 * nor anywhere in the panel, writes to Accurate.
 */
interface BacaAccurate
{
    /**
     * Every good in the database, suspended ones and ones without a barcode included
     * (SinkronBarangToko decides what the shop sells).
     *
     * @return list<array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}>
     *
     * @throws AccurateGagal
     */
    public function barang(): array;
}
