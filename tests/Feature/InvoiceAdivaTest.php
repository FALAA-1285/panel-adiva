<?php

use App\Enums\StatusOrder;
use App\Models\ConfigToko;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\InvoiceAdiva;
use App\Support\Terbilang;

/*
 * The invoice of an ACC'd order: the PDF (dompdf, resources/views/invoice/adiva.blade.php),
 * the words sent with it and the text version. The clock is frozen to 28 Sep 2026 13:42 WIB.
 */

beforeEach(function () {
    ConfigToko::create(['kunci' => 'REKENING', 'nilai' => 'BCA 123 a.n. Bang Bana']);

    $this->buat = function (array $ubah = [], int $baris = 2) {
        $order = LogOrder::factory()->status(StatusOrder::Diacc)->create([
            'order_id' => 'ORD-260928-014', 'invoice_number' => 'INV/2026/09/142', 'nama_penerima' => 'Rina Maharani 🌸',
            'no_hp' => '081234567890', 'total_produk' => 1245000, 'ongkir' => 38000, 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
            'ambil_sendiri' => false, 'alamat_lengkap' => 'Jl. Kalpataru 18', 'kelurahan' => 'Dinoyo', 'kecamatan' => 'Lowokwaru', 'kota' => 'Malang', 'kode_pos' => '65144',
            ...$ubah,
        ]);

        foreach ($baris > 0 ? range(1, $baris) : [] as $n) {
            OrderItem::create(['log_order_id' => $order->id, 'urutan' => $n, 'nama_produk' => "Produk {$n}", 'qty' => $n, 'harga_satuan' => 10000, 'subtotal' => $n * 10000]);
        }

        return $order->refresh();
    };
});

test('an amount is written in words the way the old invoice did', function (int $nilai, string $kata) {
    expect(Terbilang::rupiah($nilai))->toBe($kata);
})->with([
    [0, 'Nol Rupiah'],
    [11, 'Sebelas Rupiah'],
    [19, 'Sembilan Belas Rupiah'],
    [101, 'Seratus Satu Rupiah'],
    [1000, 'Seribu Rupiah'],
    [2000, 'Dua Ribu Rupiah'],
    [34500, 'Tiga Puluh Empat Ribu Lima Ratus Rupiah'],
    [1250000, 'Satu Juta Dua Ratus Lima Puluh Ribu Rupiah'],
    [17500123, 'Tujuh Belas Juta Lima Ratus Ribu Seratus Dua Puluh Tiga Rupiah'],
]);

test('the PDF is a real PDF named after the invoice number', function () {
    $order = ($this->buat)();

    $pdf = InvoiceAdiva::pdf($order, User::factory()->falah()->create());

    expect($pdf)->toStartWith('%PDF')
        ->and(InvoiceAdiva::namaFile($order))->toBe('INV-2026-09-142.pdf');
});

test('what the invoice prints: the customer without emoji, the lines, the totals and the words', function () {
    $order = ($this->buat)();
    $data = InvoiceAdiva::data($order, User::factory()->falah()->create());

    // Helvetica draws no emoji; the name stays readable
    expect($data['customer'])->toBe('Rina Maharani')
        ->and($data['alamat'])->toBe('Jl. Kalpataru 18, Dinoyo, Lowokwaru, Malang, 65144')
        ->and($data['tgl_invoice'])->toBe('28/09/2026')
        ->and($data['platform'])->toBe('WHATSAPP/TF')
        ->and($data['admin'])->toBe('ADIVA')
        ->and($data['subtotal'])->toBe(30000)
        ->and($data['grand_total'])->toBe(68000)
        ->and($data['terbilang'])->toBe('Enam Puluh Delapan Ribu Rupiah')
        ->and($data['notes1'])->toBe('KIRIM - JNE')
        ->and($data['notes2'])->toBe('BELUM LUNAS')
        ->and($data['catatan_bayar'])->toBe('Pembayaran dapat dilakukan secara tunai atau transfer via BCA 123 a.n. Bang Bana');
});

test('a long order continues on the next page', function () {
    $order = ($this->buat)(baris: 20);

    $halaman = InvoiceAdiva::data($order)['halaman'];
    $pdf = InvoiceAdiva::pdf($order);

    expect($halaman)->toHaveCount(2)
        ->and($halaman[0])->toHaveCount(16)
        ->and($halaman[1])->toHaveCount(4)
        ->and(preg_match_all('#/Type /Page\b#', $pdf))->toBe(2);
});

test('an order without lines is one line for its whole price', function () {
    $order = ($this->buat)(baris: 0);

    $data = InvoiceAdiva::data($order);

    expect($data['halaman'][0])->toBe([['nama' => '-', 'qty' => 1, 'harga' => 1245000, 'unit' => 'Pcs']])
        ->and($data['grand_total'])->toBe(1283000);
});

test('pickup and salary-deduction orders print their own notes', function (array $ubah, string $platform, string $notes1, string $notes2, string $bayar) {
    $order = ($this->buat)($ubah);

    $data = InvoiceAdiva::data($order);

    expect($data['platform'])->toBe($platform)
        ->and($data['notes1'])->toBe($notes1)
        ->and($data['notes2'])->toBe($notes2)
        ->and($data['catatan_bayar'])->toBe($bayar);
})->with([
    'pay at the shop' => [['ambil_sendiri' => true, 'kurir_pilihan' => 'AMBIL SENDIRI - Toko Sawojajar', 'metode_bayar' => 'BAYAR DI TEMPAT'], 'WHATSAPP/CASH', 'AMBIL TOKO SAWOJAJAR', 'BELUM LUNAS', 'Pembayaran dilakukan langsung di tempat saat pengambilan di Toko Sawojajar'],
    'pickup paid by transfer' => [['ambil_sendiri' => true, 'kurir_pilihan' => 'AMBIL SENDIRI - Pabrik Lawang', 'metode_bayar' => 'TRANSFER'], 'WHATSAPP/TF', 'AMBIL PABRIK LAWANG', 'BELUM LUNAS', 'Pembayaran dapat dilakukan secara tunai atau transfer via BCA 123 a.n. Bang Bana'],
    'an employee order' => [['kurir_pilihan' => 'AMBIL SENDIRI', 'metode_bayar' => 'POTONG GAJI', 'ambil_sendiri' => true], 'WHATSAPP/POTONG GAJI', 'AMBIL KANTOR', 'POTONG GAJI', 'Pembayaran dipotong dari gaji karyawan sesuai ketentuan program karyawan Bana Food'],
]);

test('a paid order says LUNAS', function () {
    $order = ($this->buat)(['status_order' => StatusOrder::Lunas]);

    expect(InvoiceAdiva::data($order)['notes2'])->toBe('LUNAS');
});

test('the working hours in the payment words come from the settings, with Sunday left out by default', function () {
    expect(InvoiceAdiva::teksJamKerja())->toBe('jam kerja 07.00–21.00, Minggu tidak dihitung');

    ConfigToko::create(['kunci' => 'JAM_PESAN_OTOMATIS', 'nilai' => '08:30-20:00']);
    ConfigToko::create(['kunci' => 'HARI_LIBUR_OTOMATIS', 'nilai' => 'Minggu, 2026-12-25, Sabtu']);

    expect(InvoiceAdiva::teksJamKerja())->toBe('jam kerja 08.30–20.00, Minggu/Sabtu tidak dihitung');
});

test('the text invoice carries the same figures', function () {
    $order = ($this->buat)();

    $teks = InvoiceAdiva::teks($order, revisi: false);

    expect($teks)->toContain(
        'Halo Kak Rina Maharani 🌸 😊',
        '🧾 *INVOICE INV/2026/09/142*',
        'Tanggal: 28/09/2026',
        'Kurir: JNE',
        '• Produk 1 (1 x Rp10.000) — Rp10.000',
        '• Produk 2 (2 x Rp10.000) — Rp20.000',
        '💰 Total produk: Rp30.000',
        '🚚 Ongkir: Rp38.000',
        '*Total bayar: Rp68.000*',
        '💳 *Pembayaran transfer ke:*'."\nBCA 123 a.n. Bang Bana",
    );
});

test('an ONGKIR COD invoice bills products and packing; the shipping cost is paid to the courier', function () {
    $order = ($this->buat)(['metode_bayar' => 'TRANSFER (ONGKIR COD)', 'kurir_pilihan' => 'EKSPEDISI (ONGKIR COD) - JNE', 'ongkir' => 25000, 'biaya_packing' => 6000]);
    $data = InvoiceAdiva::data($order);

    expect($data['shipping_cost'])->toBe(0)
        ->and($data['packing_cost'])->toBe(6000)
        ->and($data['grand_total'])->toBe(36000)
        ->and($data['notes1'])->toBe('KIRIM - EKSPEDISI (ONGKIR COD) - JNE - ongkir Rp25.000 bayar ke kurir')
        ->and(InvoiceAdiva::teks($order, false))
        ->toContain('🚚 Ongkir ekspedisi: Rp25.000 *dibayar COD ke kurir saat paket sampai* (tidak ikut transfer)')
        ->toContain('📦 Packing: Rp6.000')
        ->toContain('*Total bayar: Rp36.000*');
});
