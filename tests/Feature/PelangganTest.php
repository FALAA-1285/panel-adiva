1<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach (['JEDA_FOLLOWUP_JAM' => '10', 'JEDA_KESAN_HARI' => '2', 'DAFTAR_NOMOR_BOT' => 'bangbana-waha=Nomor Utama=utama'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
});

test('guests are sent to the login page', function () {
    $this->get('/pelanggan')->assertRedirect('/masuk');
});

test('the list shows the 10 latest chats and counts everyone', function () {
    foreach (range(1, 12) as $i) {
        CrmCustomer::factory()->create(['nama' => "Pelanggan {$i}", 'tgl_interaksi_terakhir' => now()->subHours($i)]);
    }
    CrmCustomer::factory()->create(['nama' => 'Lama', 'tgl_interaksi_terakhir' => now()->subDays(40)]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pelanggan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pelanggan')
            ->has('daftar', 10)
            ->where('daftar.0.nama', 'Pelanggan 1')
            ->where('jumlah', '13 pelanggan · 12 aktif 30 hari')
            ->where('profil.nama', 'Pelanggan 1'));
});

test('search and filters narrow the list', function () {
    CrmCustomer::factory()->create(['nama' => 'Toko Berkah Jaya', 'kota' => 'Blitar', 'nomor_wa' => '6285744100036', 'tipe_customer' => TipeCustomer::Reseller, 'tahap_funnel' => TahapFunnel::Nego]);
    CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'kota' => 'Malang', 'nomor_wa' => '6281355660042', 'tipe_customer' => TipeCustomer::Retail, 'tahap_funnel' => TahapFunnel::TanyaHarga]);
    $falah = User::factory()->falah()->create();
    $nama = fn (string $url) => $this->actingAs($falah)->get($url)->viewData('page')['props']['daftar'];

    expect(collect($nama('/pelanggan?cari=berkah'))->pluck('nama')->all())->toBe(['Toko Berkah Jaya'])
        ->and(collect($nama('/pelanggan?cari=blitar'))->pluck('nama')->all())->toBe(['Toko Berkah Jaya'])
        ->and(collect($nama('/pelanggan?cari=0813 5566'))->pluck('nama')->all())->toBe(['Bu Wati'])
        ->and(collect($nama('/pelanggan?tipe=RETAIL'))->pluck('nama')->all())->toBe(['Bu Wati'])
        ->and(collect($nama('/pelanggan?tahap=NEGO'))->pluck('nama')->all())->toBe(['Toko Berkah Jaya']);
});

test('the profile shows the funnel, the assessment, orders and history', function () {
    $rina = CrmCustomer::factory()->create([
        'nama' => 'Rina Maharani', 'nomor_wa' => '6281233450081', 'chat_id' => '6281233450081@c.us', 'sesi_wa' => 'bangbana-waha',
        'tipe_customer' => TipeCustomer::Reseller, 'tahap_funnel' => TahapFunnel::OrderDibuat, 'skor_prospek' => SkorProspek::Hot,
        'kota' => 'Malang', 'estimasi_modal' => 1500000, 'tgl_interaksi_terakhir' => '2026-09-28 13:06',
        'tahap_sejak' => ['BARU' => '2026-09-28T13:02:00+07:00', 'TANYA_PRODUK' => '2026-09-28T13:03:00+07:00', 'ORDER_DIBUAT' => '2026-09-28T13:06:00+07:00'],
    ]);
    $order = LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id, 'total_produk' => 1245000, 'ongkir' => 38000, 'kurir_pilihan' => 'JNE REG']);
    $order->items()->create(['urutan' => 1, 'nama_produk' => 'Molreng Reguler Bawang Original', 'qty' => 40, 'harga_satuan' => 16500, 'subtotal' => 660000]);
    $order->items()->create(['urutan' => 2, 'nama_produk' => 'Basreng Pedas Daun Jeruk', 'qty' => 30, 'harga_satuan' => 19500, 'subtotal' => 585000]);
    LogOrder::factory()->status(StatusOrder::Selesai)->create(['crm_customer_id' => $rina->id, 'tanggal' => '2026-09-01', 'total_produk' => 100000, 'lunas_at' => '2026-09-02']);
    $rina->riwayat()->create(['waktu' => '2026-09-28 13:02', 'teks' => 'Chat pertama']);
    $rina->riwayat()->create(['waktu' => '2026-09-28 13:06', 'teks' => 'Order dicatat', 'nada' => 'blue']);

    $this->actingAs(User::factory()->falah()->create())
        ->get("/pelanggan?pilih={$rina->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('profil.nomor', '0812-3345-••81')
            ->where('profil.sejak', 'Pelanggan sejak 28 Sep 2026')
            ->where('profil.status', ['label' => 'Dijawab Adiva', 'nada' => 'green'])
            ->where('profil.langkah.0', ['label' => 'Baru', 'waktu' => '28 Sep 13:02', 'selesai' => true, 'dilewati' => false, 'kini' => false])
            ->where('profil.langkah.1.waktu', '13:03')
            // reached later without a time: skipped
            ->where('profil.langkah.3', ['label' => 'Nego', 'waktu' => 'dilewati', 'selesai' => false, 'dilewati' => true, 'kini' => false])
            ->where('profil.langkah.5.kini', true)
            ->where('profil.penilaian.5', ['Estimasi modal', 'Rp1.500.000'])
            ->where('profil.penilaian.10', ['ID chat', '62812334••81@c.us'])
            ->where('profil.penilaian.12', ['Nomor bot', 'Nomor Utama'])
            ->where('profil.pesanan.jumlah', 2)
            ->where('profil.pesanan.nilai', 'Rp1,38 jt')
            ->where('profil.pesanan.lunas', 'Rp100.000')
            ->where('profil.pesanan.terakhir.ringkas', 'Molreng ×40, Basreng ×30 · JNE REG')
            ->where('profil.sapaan.prospek', 'Tidak berlaku')
            ->where('profil.sapaan.kesan', '2 hari usai SELESAI')
            ->where('profil.aktivitas.0', ['waktu' => '13:06', 'teks' => 'Order dicatat', 'nada' => 'blue']));
});

test('a prospect is greeted again unless paused', function () {
    $nisa = CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::TanyaProduk]);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->get("/pelanggan?pilih={$nisa->id}")
        ->assertInertia(fn (Assert $page) => $page->where('profil.sapaan.prospek', '10 jam setelah chat terakhir'));

    JedaBot::create(['chat_id' => $nisa->chat_id, 'nama' => $nisa->nama, 'sejak' => now()]);

    $this->actingAs($falah)->get("/pelanggan?pilih={$nisa->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('profil.sapaan.prospek', 'Tidak berlaku')
            ->where('profil.status', ['label' => 'Dijeda', 'nada' => 'amber']));
});

test('opt-out is saved and logged', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->patch("/pelanggan/{$rina->id}/optout", ['optout' => true])->assertRedirect();
    $this->actingAs($falah)->patch("/pelanggan/{$rina->id}/optout", ['optout' => true]);

    expect($rina->refresh()->status_optout)->toBeTrue()
        ->and(ActivityLog::query()->get(['kategori', 'aksi', 'detail'])->toArray())->toBe([
            ['kategori' => KategoriAktivitas::Percakapan->value, 'aksi' => 'Ubah opt-out', 'detail' => 'Rina Maharani · tidak mau dihubungi'],
        ]);
});

test('the list is exported as CSV', function () {
    CrmCustomer::factory()->create(['nama' => 'Toko Berkah Jaya', 'nomor_wa' => '6285744100036', 'kota' => 'Blitar', 'tipe_customer' => TipeCustomer::Reseller, 'tahap_funnel' => TahapFunnel::Nego, 'skor_prospek' => SkorProspek::Hot, 'tgl_interaksi_terakhir' => '2026-09-27 15:00']);
    CrmCustomer::factory()->create(['nama' => 'Bu Wati']);

    $csv = $this->actingAs(User::factory()->falah()->create())
        ->get('/pelanggan/ekspor?cari=berkah')
        ->assertDownload('pelanggan-2026-09-28.csv')
        ->streamedContent();

    expect($csv)->toContain('"Toko Berkah Jaya",6285744100036,Reseller,Nego,HOT,Blitar,"2026-09-27 15:00"')
        ->not->toContain('Bu Wati');
});

test('only staff with balas access can change opt-out', function () {
    $sarah = User::factory()->create(['peran' => Peran::PjKaryawan]);
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani']);

    $this->actingAs($sarah)->patch("/pelanggan/{$rina->id}/optout", ['optout' => true])
        ->assertForbidden();
});
