<?php

use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\SkorProspek;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Pesan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function papan(string $url = '/pipeline'): array
{
    return test()->actingAs(User::query()->firstWhere('name', 'Falah') ?? User::factory()->falah()->create())
        ->get($url)
        ->assertOk()
        ->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function kolomTahap(array $props, TahapFunnel $tahap): array
{
    return collect($props['kolom'])->firstWhere('kode', $tahap->value);
}

test('guests are sent to the login page', function () {
    $this->get('/pipeline')->assertRedirect('/masuk');
});

test('columns count the customers active in 30 days and show the latest three of the last 3 days', function () {
    foreach (['Satu' => 1, 'Dua' => 2, 'Tiga' => 3, 'Empat' => 4] as $nama => $jam) {
        CrmCustomer::factory()->create(['nama' => $nama, 'tahap_funnel' => TahapFunnel::TanyaProduk, 'tgl_interaksi_terakhir' => now()->subHours($jam)]);
    }
    CrmCustomer::factory()->create(['nama' => 'Minggu lalu', 'tahap_funnel' => TahapFunnel::TanyaProduk, 'tgl_interaksi_terakhir' => now()->subDays(10)]);
    CrmCustomer::factory()->create(['nama' => 'Lama', 'tahap_funnel' => TahapFunnel::TanyaProduk, 'tgl_interaksi_terakhir' => now()->subDays(40)]);
    // only Nego card: from the 10 days ago it is not on the board
    CrmCustomer::factory()->create(['nama' => 'Nego lama', 'tahap_funnel' => TahapFunnel::Nego, 'tgl_interaksi_terakhir' => now()->subDays(10)]);
    // greeted again later today: first in its column
    CrmCustomer::factory()->create(['nama' => 'Disapa', 'tahap_funnel' => TahapFunnel::TanyaProduk, 'tgl_interaksi_terakhir' => now()->subHours(8), 'tgl_followup_berikutnya' => now()->addHours(3)]);

    $props = papan();
    $produk = kolomTahap($props, TahapFunnel::TanyaProduk);

    expect(collect($props['kolom'])->pluck('kode')->all())->toBe(array_map(fn (TahapFunnel $t) => $t->value, TahapFunnel::cases()))
        ->and($produk['label'])->toBe('Tanya produk')
        ->and($produk['definisi'])->toBe('Tanya produk, varian, atau rekomendasi')
        ->and($produk['n'])->toBe(6)
        ->and(collect($produk['kartu'])->pluck('nama')->all())->toBe(['Disapa', 'Satu', 'Dua'])
        ->and(kolomTahap($props, TahapFunnel::Nego)['n'])->toBe(1)
        ->and(kolomTahap($props, TahapFunnel::Nego)['kartu'])->toBe([]);

    // "Lihat N lainnya" opens the whole column
    expect(collect(kolomTahap(papan('/pipeline?buka=TANYA_PRODUK'), TahapFunnel::TanyaProduk)['kartu'])->pluck('nama')->all())
        ->toBe(['Disapa', 'Satu', 'Dua', 'Tiga', 'Empat', 'Minggu lalu']);
});

test('the card line is worked out from the data', function () {
    User::factory()->create(['name' => 'Bu Sarah', 'peran' => Peran::PjKaryawan]);
    $baru = fn (array $isi) => CrmCustomer::factory()->create([...$isi, 'tgl_interaksi_terakhir' => $isi['tgl_interaksi_terakhir'] ?? now()->subMinutes(30)]);

    $baru(['nama' => 'Salsa', 'kota' => 'Malang', 'tahap_funnel' => TahapFunnel::TanyaProduk, 'minat_produk' => 'Makaroni, Usus', 'lokasi_jualan' => 'Warung depan kampus', 'tgl_interaksi_terakhir' => '2026-09-28 11:20']);
    $baru(['nama' => 'Dewi', 'kota' => 'Malang', 'tahap_funnel' => TahapFunnel::Baru, 'tgl_interaksi_terakhir' => '2026-09-27 10:00']);
    $baru(['nama' => 'Arif', 'kota' => 'Surabaya', 'tahap_funnel' => TahapFunnel::Baru, 'tgl_interaksi_terakhir' => '2026-09-26 11:00']);
    $baru(['nama' => null, 'nomor_wa' => '6287800415120', 'tahap_funnel' => TahapFunnel::Baru, 'tgl_interaksi_terakhir' => '2026-09-26 09:14', 'tgl_followup_berikutnya' => '2026-09-28 19:14']);
    $baru(['nama' => 'Andika', 'kota' => 'Pasuruan', 'tahap_funnel' => TahapFunnel::TanyaHarga, 'minat_produk' => 'Basreng', 'estimasi_modal' => 800000, 'tgl_interaksi_terakhir' => '2026-09-28 06:30', 'tgl_followup_berikutnya' => '2026-09-28 16:30']);
    $baru(['nama' => 'Joko', 'kota' => 'Kepanjen', 'tahap_funnel' => TahapFunnel::TanyaProduk, 'jumlah_followup' => 1, 'tgl_interaksi_terakhir' => '2026-09-26 10:30']);
    $rina = $baru(['nama' => 'Rina', 'tahap_funnel' => TahapFunnel::OrderDibuat]);
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id]);
    $anisa = $baru(['nama' => 'Anisa', 'tahap_funnel' => TahapFunnel::OrderDibuat, 'tgl_interaksi_terakhir' => now()->subHours(2)]);
    LogOrder::factory()->create(['order_id' => 'ORD-260928-012', 'crm_customer_id' => $anisa->id, 'ambil_sendiri' => true, 'dobel_dengan' => 'ORD-260926-015']);
    $yoga = $baru(['nama' => 'Yoga', 'tipe_customer' => TipeCustomer::Karyawan, 'tahap_funnel' => TahapFunnel::OrderDibuat, 'minat_produk' => 'Basreng', 'tgl_interaksi_terakhir' => now()->subHours(3)]);
    LogOrder::factory()->karyawan('6289612000077')->create(['order_id' => 'ORD-260928-011', 'crm_customer_id' => $yoga->id, 'ambil_sendiri' => true]);
    $baru(['nama' => 'Sinta', 'kota' => 'Malang', 'tahap_funnel' => TahapFunnel::CartDitinggal, 'jumlah_followup' => 1, 'tahap_sejak' => ['CART_DITINGGAL' => '2026-09-25T12:50:00+07:00']]);
    $baru(['nama' => 'Rudi', 'kota' => 'Batu', 'tahap_funnel' => TahapFunnel::CartDitinggal, 'tahap_sejak' => ['CART_DITINGGAL' => '2026-09-28T09:15:00+07:00'], 'tgl_followup_berikutnya' => '2026-09-28 19:30', 'tgl_interaksi_terakhir' => now()->subHours(5)]);
    $baru(['nama' => 'Fikri', 'kota' => 'Malang', 'tahap_funnel' => TahapFunnel::TidakMinat, 'status_optout' => true, 'tahap_sejak' => ['TIDAK_MINAT' => '2026-09-26T12:00:00+07:00']]);
    $baru(['nama' => 'Hendra', 'kota' => 'Batu', 'tahap_funnel' => TahapFunnel::Nego, 'butuh_admin' => true, 'tgl_interaksi_terakhir' => '2026-09-28 12:48']);
    $wati = $baru(['nama' => 'Wati', 'kota' => 'Malang', 'tahap_funnel' => TahapFunnel::Nego, 'tgl_interaksi_terakhir' => '2026-09-28 11:52']);
    JedaBot::create(['chat_id' => $wati->chat_id, 'nama' => 'Wati', 'sejak' => now()->subHours(3), 'sampai' => '2026-09-28 14:30']);
    $berkah = $baru(['nama' => 'Toko Berkah', 'kota' => 'Blitar', 'tahap_funnel' => TahapFunnel::Nego, 'tgl_interaksi_terakhir' => now()->subDays(2)]);
    JedaBot::create(['chat_id' => $berkah->chat_id, 'nama' => 'Toko Berkah', 'sejak' => now()->subDay()]);

    $kartu = collect(papan()['kolom'])->flatMap(fn (array $k) => $k['kartu'])->keyBy('nama');
    $baris = fn (string $nama) => [$kartu[$nama]['minat'], $kartu[$nama]['keterangan'], $kartu[$nama]['tanda']['teks'] ?? null];

    expect($baris('Salsa'))->toBe(['Makaroni, Usus · warung depan kampus', 'Malang · 11:20', null])
        ->and($baris('Dewi'))->toBe(['Belum menyebut kebutuhan', 'Malang · kemarin', null])
        ->and($baris('Arif'))->toBe(['Belum menyebut kebutuhan', 'Surabaya · 2 hari lalu', null])
        ->and($baris('+62 878-••••-5120'))->toBe(['Belum menyebut kebutuhan', 'Chat 26 Sep 09:14 · disapa ulang 19:14', null])
        ->and($baris('Andika'))->toBe(['Basreng · modal Rp800 rb', 'Pasuruan · chat terakhir 06:30', 'Disapa ulang 16:30'])
        ->and($baris('Joko'))->toBe(['Belum menyebut kebutuhan', 'Kepanjen · sudah disapa ulang', null])
        ->and($baris('Rina'))->toBe(['Belum menyebut kebutuhan', 'ORD-260928-014 · menunggu ACC', null])
        ->and($baris('Anisa'))->toBe(['Belum menyebut kebutuhan', 'ORD-260928-012 · ambil sendiri', 'Kemungkinan dobel'])
        ->and($baris('Yoga'))->toBe(['Basreng · potong gaji', 'ORD-260928-011 · ke Bu Sarah', null])
        ->and($baris('Sinta'))->toBe(['Belum menyebut kebutuhan', 'Malang · ditutup 25 Sep · sudah disapa', null])
        ->and($baris('Rudi'))->toBe(['Belum menyebut kebutuhan', 'Batu · ditutup 09.15 · disapa 19.30', null])
        ->and($baris('Fikri'))->toBe(['Belum menyebut kebutuhan', 'Malang · 26 Sep', 'Opt-out'])
        ->and($baris('Hendra'))->toBe(['Belum menyebut kebutuhan', 'Batu · 12:48', 'Butuh admin'])
        ->and($baris('Wati'))->toBe(['Belum menyebut kebutuhan', 'Malang · 11:52', 'Dijeda s/d 14:30'])
        ->and($baris('Toko Berkah'))->toBe(['Belum menyebut kebutuhan', 'Blitar · 2 hari lalu', 'Dijeda tanpa batas'])
        ->and($kartu['Hendra']['tanda']['nada'])->toBe('amber')
        ->and($kartu['Wati']['tanda']['nada'])->toBe('gray')
        ->and($kartu['Fikri']['tanda']['nada'])->toBe('red');
});

test('search and chips narrow the board', function () {
    CrmCustomer::factory()->create(['nama' => 'Pak Hendra', 'kota' => 'Batu', 'minat_produk' => 'Basreng 200 pcs', 'tipe_customer' => TipeCustomer::Reseller, 'skor_prospek' => SkorProspek::Hot, 'tahap_funnel' => TahapFunnel::Nego, 'butuh_admin' => true, 'tgl_interaksi_terakhir' => now()]);
    CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'kota' => 'Malang', 'minat_produk' => 'Seblak kering', 'tipe_customer' => TipeCustomer::Retail, 'skor_prospek' => SkorProspek::Warm, 'tahap_funnel' => TahapFunnel::TanyaHarga, 'tgl_interaksi_terakhir' => now()]);
    $nama = fn (string $url) => collect(papan($url)['kolom'])->flatMap(fn (array $k) => $k['kartu'])->pluck('nama')->all();

    expect($nama('/pipeline?cari=batu'))->toBe(['Pak Hendra'])
        ->and($nama('/pipeline?cari=seblak'))->toBe(['Bu Wati'])
        ->and($nama('/pipeline?tipe=RETAIL'))->toBe(['Bu Wati'])
        ->and($nama('/pipeline?skor=HOT'))->toBe(['Pak Hendra'])
        ->and($nama('/pipeline?admin=1'))->toBe(['Pak Hendra'])
        ->and(kolomTahap(papan('/pipeline?tipe=RETAIL'), TahapFunnel::Nego)['n'])->toBe(0);
});

test('the figures above the board', function () {
    CrmCustomer::factory()->create(['tgl_interaksi_terakhir' => now()->subDays(40), 'status_optout' => true]);
    CrmCustomer::factory()->create(['tgl_interaksi_terakhir' => now()->subDays(2)]);
    CrmCustomer::factory()->create(['tgl_interaksi_terakhir' => now(), 'tahap_sejak' => ['BARU' => '2026-09-26T13:00:00+07:00', 'ORDER_DIBUAT' => '2026-09-28T13:00:00+07:00']]);
    $c = CrmCustomer::factory()->create(['tgl_interaksi_terakhir' => now(), 'tahap_sejak' => ['BARU' => '2026-09-25T13:00:00+07:00', 'ORDER_DIBUAT' => '2026-09-28T13:00:00+07:00']]);
    CrmCustomer::factory()->create(['tgl_interaksi_terakhir' => now()]);
    Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => now()->subHour(), 'pengirim' => PengirimPesan::Adiva, 'meta' => ['otomatis' => 'SAPAAN', 'template' => 'FU-01']]);
    Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => now()->subHour(), 'pengirim' => PengirimPesan::Adiva, 'meta' => ['otomatis' => 'KESAN']]);
    Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => now()->subDay(), 'pengirim' => PengirimPesan::Adiva, 'meta' => ['otomatis' => 'SAPAAN']]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pipeline')
        ->assertInertia(fn (Assert $page) => $page
            ->component('pipeline')
            ->where('angka', [
                ['label' => 'Customer aktif · 30 hari', 'nilai' => '4'],
                ['label' => 'Baru menyapa → order dibuat', 'nilai' => '50%'],
                ['label' => 'Rata-rata sampai order', 'nilai' => '2,5 hari'],
                ['label' => 'Disapa ulang hari ini', 'nilai' => '1'],
                ['label' => 'Opt-out (tidak dihubungi)', 'nilai' => '1'],
            ]));
});

test('a card is moved a stage by hand', function () {
    $falah = User::factory()->falah()->create();
    $mega = CrmCustomer::factory()->create(['nama' => 'Mega Lestari', 'tahap_funnel' => TahapFunnel::SiapOrder, 'tgl_interaksi_terakhir' => now()->subHour(), 'tahap_sejak' => ['BARU' => '2026-09-28T10:00:00+07:00']]);

    $this->actingAs($falah)->patch("/pipeline/{$mega->id}/tahap", ['arah' => 1])->assertRedirect();

    $mega->refresh();
    $kartu = kolomTahap(papan(), TahapFunnel::OrderDibuat)['kartu'][0];

    expect($mega->tahap_funnel)->toBe(TahapFunnel::OrderDibuat)
        ->and(array_keys($mega->tahap_sejak ?? []))->toBe(['BARU', 'ORDER_DIBUAT'])
        ->and($mega->tahap_manual_oleh_id)->toBe($falah->id)
        ->and($kartu['nama'])->toBe('Mega Lestari')
        ->and($kartu['keterangan'])->toBe('Dipindah manual oleh Falah · baru saja')
        ->and($mega->riwayat()->sole()->teks)->toBe('Tahap → Order dibuat · dipindah manual oleh Falah')
        ->and(ActivityLog::query()->get(['kategori', 'aksi', 'detail'])->toArray())->toBe([
            ['kategori' => KategoriAktivitas::Percakapan->value, 'aksi' => 'Pindah tahap', 'detail' => 'Mega Lestari · Siap order → Order dibuat'],
        ]);

    $this->actingAs($falah)->patch("/pipeline/{$mega->id}/tahap", ['arah' => -1]);
    expect($mega->refresh()->tahap_funnel)->toBe(TahapFunnel::SiapOrder);
});

test('a card cannot move past either end', function () {
    $falah = User::factory()->falah()->create();
    $baru = CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::Baru]);
    $keluar = CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::TidakMinat]);

    $this->actingAs($falah)->patch("/pipeline/{$baru->id}/tahap", ['arah' => -1])->assertSessionHasErrors('arah');
    $this->actingAs($falah)->patch("/pipeline/{$keluar->id}/tahap", ['arah' => 1])->assertSessionHasErrors('arah');
    $this->actingAs($falah)->patch("/pipeline/{$baru->id}/tahap", ['arah' => 2])->assertSessionHasErrors('arah');

    expect($baru->refresh()->tahap_funnel)->toBe(TahapFunnel::Baru)
        ->and($keluar->refresh()->tahap_funnel)->toBe(TahapFunnel::TidakMinat);
});

test('only staff with balas access can move pipeline card', function () {
    $sarah = User::factory()->create(['peran' => Peran::PjKaryawan]);
    $mega = CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::SiapOrder]);

    $this->actingAs($sarah)->patch("/pipeline/{$mega->id}/tahap", ['arah' => 1])
        ->assertForbidden();
});
