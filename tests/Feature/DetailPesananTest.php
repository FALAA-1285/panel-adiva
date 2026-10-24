<?php

use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach ([
        'BATAS_BAYAR_JAM' => '5', 'BATAS_BAYAR_PICKUP_JAM' => '24', 'JAM_PESAN_OTOMATIS' => '07:00-21:00',
        'HARI_LIBUR_OTOMATIS' => 'Minggu', 'NAMA_TOKO' => 'Bang Bana — CV TKT Bana Food', 'INVOICE_TERAKHIR' => 'INV/2026/09/141',
        'CATATAN_INVOICE' => 'Ongkir sudah termasuk dalam tagihan ini.', 'DAFTAR_NOMOR_BOT' => 'bangbana-waha=Nomor Utama',
    ] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
});

test('guests are sent to the login page and unknown orders are not found', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014']);

    $this->get('/pesanan/ORD-260928-014')->assertRedirect('/masuk');
    $this->actingAs(User::factory()->falah()->create())->get('/pesanan/ORD-TIDAK-ADA')->assertNotFound();
});

test('an order waiting for ACC shows its steps, items, delivery, history and a draft invoice', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani']);
    $order = LogOrder::factory()->pada('2026-09-28 13:06')->create([
        'order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id, 'nama_penerima' => 'Rina Maharani', 'nomor_wa' => '6281233450081',
        'sesi_wa' => 'bangbana-waha', 'tipe_customer' => TipeCustomer::Reseller, 'metode_bayar' => 'TRANSFER', 'kurir_pilihan' => 'JNE REG',
        'estimasi_kirim' => '1–2 hari', 'total_produk' => 1245000, 'ongkir' => 38000, 'berat_kg' => 1.6,
        'alamat_lengkap' => 'Jl. Kalpataru 18', 'kelurahan' => 'Dinoyo', 'kecamatan' => 'Lowokwaru', 'kota' => 'Kota Malang',
        'provinsi' => 'Jawa Timur', 'kode_pos' => '65144',
    ]);
    $order->items()->create(['urutan' => 1, 'nama_produk' => 'Molreng Reguler Bawang Original', 'keterangan_harga' => 'Harga 10–49 pcs', 'qty' => 40, 'harga_satuan' => 16500, 'subtotal' => 660000]);
    $order->items()->create(['urutan' => 2, 'nama_produk' => 'Basreng Pedas Daun Jeruk', 'qty' => 30, 'harga_satuan' => 19500, 'subtotal' => 585000]);
    $order->riwayat()->create(['waktu' => '2026-09-28 13:02', 'jenis' => 'PERCAKAPAN', 'teks' => 'Chat pertama masuk.']);
    $order->riwayat()->create([
        'waktu' => '2026-09-28 13:06', 'jenis' => 'DICATAT', 'nada' => 'blue', 'teks' => 'Dicatat Adiva dari chat',
        'data' => ['lengkap' => 'Adiva mencatat order dan mengabari supervisor.'],
    ]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ORD-260928-014')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pesanan/detail')
            ->where('order.sub', 'Rina Maharani · Reseller · dicatat Adiva dari chat, 28 Sep 13:06')
            ->where('langkah.0', ['label' => 'Menunggu ACC', 'selesai' => true, 'kini' => true, 'mati' => false, 'waktu' => '28 Sep 13:06'])
            ->where('langkah.1.label', 'Invoice terkirim')
            ->where('langkah.1.waktu', '—')
            ->where('item.0', ['nama' => 'Molreng Reguler Bawang Original', 'keterangan' => 'Harga 10–49 pcs', 'harga' => 'Rp16.500', 'qty' => 40, 'subtotal' => 'Rp660.000'])
            ->where('total', ['produk' => 'Rp1.245.000', 'ongkir' => ['label' => 'Ongkir JNE REG · 1,6 kg', 'nilai' => 'Rp38.000', 'cod' => false], 'packing' => null, 'tagihan' => 'Rp1.283.000'])
            ->where('pengiriman', [
                'penerima' => 'Rina Maharani',
                'nomor' => '0812-3345-••81',
                'alamat' => 'Jl. Kalpataru 18, Kel. Dinoyo, Kec. Lowokwaru, Kota Malang, Jawa Timur 65144',
                'kurir' => 'JNE REG · estimasi 1–2 hari',
                'berat' => '1,6 kg',
                'metode' => 'Transfer',
                'batasBayar' => '5 jam kerja setelah ACC',
                'nomorBot' => 'Nomor Utama',
                'dikirimOleh' => 'Adiva · setelah ACC',
            ])
            // newest first; the full sentence where there is one
            ->where('riwayat', [
                ['waktu' => '13:06', 'teks' => 'Adiva mencatat order dan mengabari supervisor.', 'nada' => 'blue'],
                ['waktu' => '13:02', 'teks' => 'Chat pertama masuk.', 'nada' => 'gray'],
            ])
            ->where('invoice.nomor', 'INV/2026/09/142')
            ->where('invoice.draf', true)
            ->where('invoice.toko', 'Bang Bana')
            ->where('invoice.perusahaan', 'CV TKT Bana Food · Sawojajar, Kota Malang')
            ->where('invoice.tanggal', '28 September 2026')
            ->where('invoice.alamat', 'Jl. Kalpataru 18, Dinoyo, Lowokwaru, Kota Malang 65144')
            ->where('invoice.ongkir', ['label' => 'Ongkir JNE REG', 'nilai' => 'Rp38.000', 'cod' => false])
            ->where('invoice.admin', 'ADIVA')
            ->where('invoice.rekening', 'Rekening belum diisi di Pengaturan')
            ->where('invoice.ditahan', '5 jam kerja (07.00–21.00, Minggu tidak dihitung)')
            ->where('invoice.catatan', 'Ongkir sudah termasuk dalam tagihan ini.')
            ->where('aksi', fn ($aksi) => collect($aksi)->pluck('kode')->all() === ['TOLAK', 'BATAL', 'ACC'])
            ->where('aksi.2', ['kode' => 'ACC', 'label' => 'ACC & kirim invoice', 'bahaya' => false]));
});

test('after ACC the invoice is final and only Tolak is left', function () {
    LogOrder::factory()->pada('2026-09-27 19:12')->status(StatusOrder::Diacc)->create([
        'order_id' => 'ORD-260927-031', 'metode_bayar' => 'TRANSFER', 'invoice_number' => 'INV/2026/09/138',
        'diacc_at' => '2026-09-27 19:20', 'batas_bayar_at' => '2026-09-28 15:42',
    ]);
    ConfigToko::query()->where('kunci', 'REKENING')->delete();
    ConfigToko::create(['kunci' => 'REKENING', 'nilai' => 'BCA 1234567890 a.n. CV TKT Bana Food']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ORD-260927-031')
        ->assertInertia(fn (Assert $page) => $page
            ->where('langkah.1', ['label' => 'Invoice terkirim', 'selesai' => true, 'kini' => true, 'mati' => false, 'waktu' => '27 Sep 19:20'])
            ->where('pengiriman.batasBayar', '28 Sep 15:42')
            ->where('pengiriman.dikirimOleh', 'Adiva · 27 Sep 19:20')
            ->where('invoice.nomor', 'INV/2026/09/138')
            ->where('invoice.draf', false)
            ->where('invoice.tanggal', '27 September 2026')
            ->where('invoice.rekening', 'BCA 1234567890 a.n. CV TKT Bana Food')
            // TOLAK still applies while it is unpaid, as on Pesanan; the next step is LUNAS
            ->where('aksi', fn ($aksi) => collect($aksi)->pluck('kode')->all() === ['TOLAK', 'BATAL', 'LUNAS']));
});

test('a pickup order has no shipping cost and its own payment time', function () {
    LogOrder::factory()->create([
        'order_id' => 'ORD-AMBIL', 'metode_bayar' => 'TRANSFER (PICKUP)', 'ambil_sendiri' => true,
        'kurir_pilihan' => 'AMBIL SENDIRI - Pabrik Lawang', 'alamat_lengkap' => 'Ambil di Pabrik Lawang',
    ]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ORD-AMBIL')
        ->assertInertia(fn (Assert $page) => $page
            ->where('total.ongkir', null)
            ->where('langkah.3.label', 'Diambil')
            ->where('pengiriman.alamat', 'Ambil di Pabrik Lawang')
            ->where('pengiriman.batasBayar', '24 jam kerja setelah ACC')
            ->where('invoice.ongkir', null)
            ->where('invoice.catatan', null)
            ->where('invoice.ditahan', '24 jam kerja (07.00–21.00, Minggu tidak dihitung)'));
});

test('an ONGKIR COD order bills products and packing; the shipping cost is only information', function () {
    LogOrder::factory()->create([
        'order_id' => 'ORD-COD', 'metode_bayar' => 'TRANSFER (ONGKIR COD)', 'kurir_pilihan' => 'EKSPEDISI (ONGKIR COD) - JNE',
        'total_produk' => 600000, 'ongkir' => 25000, 'biaya_packing' => 26000,
    ]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan/ORD-COD')
        ->assertInertia(fn (Assert $page) => $page
            ->where('total.ongkir.label', 'Ongkir JNE (COD)')
            ->where('total.ongkir.nilai', 'Rp25.000 · COD ke kurir')
            ->where('invoice.catatan', 'Ongkir ekspedisi dibayar COD ke kurir saat paket sampai, tidak ikut ditransfer.')
            ->where('total.ongkir.cod', true)
            ->where('total.packing', 'Rp26.000')
            ->where('total.tagihan', 'Rp626.000')
            ->where('invoice.total', 'Rp626.000')
            ->where('invoice.admin', 'ADIVA'));
});
