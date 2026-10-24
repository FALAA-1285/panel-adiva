<?php

use App\Bot\CekHargaOrder;
use App\Models\Produk;

/*
 * A new order's prices checked against the price list (n8n's "Cek Harga ke Daftar"): it
 * never blocks the order, it only warns the supervisor before ACC.
 */

beforeEach(function () {
    Produk::create(['jenis_produk' => 'Basreng Koin 1 kg', 'kategori_kemasan' => 'Pack', 'harga_bertingkat' => [
        'satuan' => 'pcs',
        'tier' => [['min' => 1, 'max' => 9, 'harga' => 65000], ['min' => 10, 'max' => null, 'harga' => 60000]],
    ]]);
    Produk::create(['jenis_produk' => 'Kripca 1,8 kg', 'kategori_kemasan' => 'Bal', 'informasi_harga' => "1 - 29 Bal: Rp 92.000\n≥ 30 Bal: Rp 87.000"]);
});

$putusan = ['catatan_notif' => '', 'catatan_log' => ''];
$baris = fn (array ...$item) => ['metode_bayar' => 'TRANSFER', 'order_items_detail' => $item];

test('prices from the list leave the order as it is', function () use ($putusan, $baris) {
    $hasil = CekHargaOrder::periksa($baris(
        ['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000],
        ['nama' => 'Kripca 1,8 kg Original', 'qty' => 2, 'harga_satuan' => 92000],
    ), $putusan);

    expect($hasil)->toBe($putusan);
});

test('a price for the wrong quantity and a price not on the list are flagged', function () use ($putusan, $baris) {
    $hasil = CekHargaOrder::periksa($baris(
        ['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 3, 'harga_satuan' => 60000],
        ['nama' => 'Kripca 1,8 kg', 'qty' => 2, 'harga_satuan' => 80000],
    ), $putusan);

    expect($hasil['catatan_notif'])
        ->toContain('⚠️ *CEK HARGA SEBELUM ACC*')
        ->toContain('• Basreng Koin 1 kg Pedas (3 pcs, total untuk tingkat harga 3) ditulis Rp60.000, seharusnya Rp65.000')
        ->toContain('• Kripca 1,8 kg (2 pcs) ditulis Rp80.000 — TIDAK ADA di daftar harga Kripca 1,8 kg (1-29: Rp92.000, ≥30: Rp87.000)')
        ->and($hasil['catatan_log'])->toBe('CEK HARGA: 2 item tidak cocok daftar harga');
});

test('flavours of one pack count together for the tier', function () use ($putusan, $baris) {
    $hasil = CekHargaOrder::periksa($baris(
        ['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 5, 'harga_satuan' => 60000],
        ['nama' => 'Basreng Koin 1 kg Original', 'qty' => 5, 'harga_satuan' => 60000],
    ), $putusan);

    expect($hasil)->toBe($putusan);
});

test('an unknown product is mentioned, an employee order is not checked', function () use ($putusan, $baris) {
    $tidakDikenal = CekHargaOrder::periksa($baris(['nama' => 'Keripik Tempe', 'qty' => 1, 'harga_satuan' => 15000]), $putusan);
    $gaji = CekHargaOrder::periksa([...$baris(['nama' => 'Kripca 1,8 kg', 'qty' => 1, 'harga_satuan' => 50000]), 'metode_bayar' => 'POTONG GAJI'], $putusan);

    expect($tidakDikenal['catatan_notif'])->toContain("ℹ️ Produk berikut tidak ketemu di daftar harga, mohon cek manual:\n• Keripik Tempe — Rp15.000")
        ->and($tidakDikenal['catatan_log'])->toBe('CEK HARGA: 1 item tidak dikenal')
        ->and($gaji)->toBe($putusan);
});
