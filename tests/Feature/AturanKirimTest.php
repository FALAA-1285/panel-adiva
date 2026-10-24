<?php

use App\Models\LogOrder;
use App\Support\AturanKirim;
use App\Support\PerintahPesanan;

test('an expedition parcel is ONGKIR COD; the company\'s own couriers, pickups and free parcels are not', function (string $kurir, string $metode, bool $cod) {
    expect(AturanKirim::jadiCod($kurir, $metode))->toBe($cod);
})->with([
    'JNE' => ['JNE', 'TRANSFER', true],
    'motorbike courier' => ['KURIR MOTOR', 'TRANSFER', false],
    'company truck' => ['ARMADA KANTOR', 'TRANSFER', false],
    'pickup' => ['AMBIL SENDIRI - Pabrik Lawang', 'TRANSFER (PICKUP)', false],
    'salary order' => ['JNE', 'POTONG GAJI', false],
    'free shipping' => ['JNE GRATIS ONGKIR', 'TRANSFER', false],
]);

test('the courier is marked the way Adiva writes it', function () {
    expect(AturanKirim::kurirCod('JNE'))->toBe('EKSPEDISI (ONGKIR COD) - JNE')
        ->and(AturanKirim::kurirCod('EKSPEDISI'))->toBe('EKSPEDISI (ONGKIR COD)')
        ->and(AturanKirim::kurirCod('EKSPEDISI (ONGKIR COD) - J&T'))->toBe('EKSPEDISI (ONGKIR COD) - J&T');
});

test('packing: per started kilo, at least the minimum, free above the limit', function () {
    $config = ['PACKING_PER_KG' => '2.000', 'PACKING_MINIMAL' => '5.000', 'PACKING_GRATIS_DI_ATAS' => '1.000.000'];

    expect(AturanKirim::packing(1.2, 100000, 'JNE', 'TRANSFER', $config))->toBe(5000)
        ->and(AturanKirim::packing(12.5, 100000, 'JNE', 'TRANSFER', $config))->toBe(26000)
        ->and(AturanKirim::packing(12.5, 1000000, 'JNE', 'TRANSFER', $config))->toBe(0)
        ->and(AturanKirim::packing(12.5, 100000, 'KURIR MOTOR', 'TRANSFER', $config))->toBe(0)
        ->and(AturanKirim::packing(12.5, 100000, 'JNE', 'TRANSFER', []))->toBe(0);
});

test('the bill leaves out a COD shipping cost and adds packing', function () {
    $cod = LogOrder::factory()->make(['metode_bayar' => 'TRANSFER (ONGKIR COD)', 'kurir_pilihan' => 'EKSPEDISI (ONGKIR COD) - JNE', 'total_produk' => 100000, 'ongkir' => 25000, 'biaya_packing' => 6000]);
    $biasa = LogOrder::factory()->make(['metode_bayar' => 'TRANSFER', 'kurir_pilihan' => 'KURIR MOTOR', 'total_produk' => 100000, 'ongkir' => 25000]);

    expect($cod->tagihan())->toBe(106000)
        ->and($biasa->tagihan())->toBe(125000);
});

test('ACC reads a packing fee typed after the shipping cost', function () {
    expect(PerintahPesanan::pisahPacking('25000 packing 5000'))->toBe(['25000', 5000])
        ->and(PerintahPesanan::pisahPacking('packing gratis'))->toBe([null, 0])
        ->and(PerintahPesanan::pisahPacking('packing banyak'))->toBe([null, false])
        ->and(PerintahPesanan::pisahPacking('25000'))->toBe(['25000', null]);
});
