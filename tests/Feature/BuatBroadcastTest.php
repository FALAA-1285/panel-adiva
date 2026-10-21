<?php

use App\Enums\KategoriAktivitas;
use App\Enums\SkorProspek;
use App\Enums\StatusBroadcast;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Produk;
use App\Models\TemplateBroadcast;
use App\Models\User;
use App\Support\PenerimaBroadcast;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2']);
    Produk::create(['jenis_produk' => 'Molreng Reguler', 'kategori_produk' => 'Molreng']);
    Produk::create(['jenis_produk' => 'Kripca 1,8 kg', 'kategori_produk' => 'Keripik kaca']);
});

/**
 * A reseller the "Restock reseller" filter reaches.
 *
 * @param  array<string, mixed>  $isi
 */
function reseller(array $isi = []): CrmCustomer
{
    return CrmCustomer::factory()->create([
        'tipe_customer' => TipeCustomer::Reseller, 'tahap_funnel' => TahapFunnel::OrderDibuat, 'skor_prospek' => SkorProspek::Warm,
        'minat_produk' => 'Kripca 1,8 kg', 'kota' => 'Malang', 'tgl_interaksi_terakhir' => now()->subDays(5), ...$isi,
    ]);
}

/**
 * @return array<string, mixed>
 */
function filterRestock(array $isi = []): array
{
    return PenerimaBroadcast::normalisasi([
        'tipe' => ['RESELLER'], 'tahap' => ['TANYA_HARGA', 'ORDER_DIBUAT'], 'skor' => ['HOT', 'WARM'], 'minat' => ['Keripik kaca'],
        'kota' => 'MALANG_RAYA', 'interaksi' => '90_HARI', ...$isi,
    ]);
}

test('guests are sent to the login page and staff without access are refused', function () {
    $this->get('/broadcast/baru')->assertRedirect('/masuk');
    $this->actingAs(User::factory()->create())->get('/broadcast/baru')->assertForbidden();
});

test('the form starts from the first template', function () {
    TemplateBroadcast::create(['nama' => 'Restock reseller', 'nama_kampanye' => 'Restock Kripca Oktober', 'filter' => filterRestock(), 'pesan' => 'Halo kak {nama}', 'tombol' => ['Mau order', 'Lihat harga'], 'urutan' => 1]);
    TemplateBroadcast::create(['nama' => 'Info libur', 'pesan' => '[Tulis pesan info libur.]', 'urutan' => 2]);
    reseller();
    reseller(['kota' => 'Pasuruan']);
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'kota' => 'Malang', 'minat_produk' => 'Molreng, Basreng', 'tgl_interaksi_terakhir' => now()]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/broadcast/baru')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('broadcast/baru')
            ->where('awal.nama', 'Restock Kripca Oktober')
            ->where('awal.filter.minat', ['Keripik kaca'])
            ->where('awal.tombol', ['Mau order', 'Lihat harga'])
            ->where('awal.tanggal', '2026-09-30')
            ->where('awal.jam', '09:00')
            ->where('perkiraan', 1)
            ->has('template', 2)
            ->where('kosong.nama', '')
            // most wanted first: the two Kripca resellers, then Rina's Molreng
            ->where('pilihan.minat', ['Keripik kaca', 'Molreng'])
            ->where('pilihan.nomorBot', [
                ['nilai' => '', 'label' => 'Ikuti nomor customer'],
                ['nilai' => 'bangbana-waha', 'label' => 'Nomor Utama saja'],
                ['nilai' => 'bangbana-2', 'label' => 'Nomor Lawang saja'],
            ])
            ->where('contoh.0', ['id' => $rina->id, 'nama' => 'Rina Maharani', 'panggilan' => 'Rina', 'kota' => 'Malang', 'minat' => 'Molreng, Basreng'])
            ->where('kuota', 300));
});

test('Duplikat fills the form from the campaign', function () {
    $b = Broadcast::create(['nama' => 'Paket reseller pemula', 'pesan' => 'Halo', 'filter' => filterRestock(['kota' => 'SEMUA']), 'opsi' => ['tombol' => ['Mau order'], 'stop' => false], 'kecepatan' => 'NORMAL']);

    $this->actingAs(User::factory()->falah()->create())
        ->get("/broadcast/baru?dari={$b->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('awal.template', null)
            ->where('awal.nama', 'Paket reseller pemula')
            ->where('awal.filter.kota', 'SEMUA')
            ->where('awal.tombol', ['Mau order'])
            ->where('awal.stop', false)
            ->where('awal.kecepatan', 'NORMAL'));
});

test('the recipients follow the filter and leave out who must not get it', function () {
    $sasaran = reseller();
    reseller(['kota' => 'Lawang']);
    reseller(['tipe_customer' => TipeCustomer::Retail]);
    reseller(['skor_prospek' => SkorProspek::Cold]);
    reseller(['minat_produk' => 'Basreng']);
    reseller(['tgl_interaksi_terakhir' => now()->subDays(120)]);
    reseller(['status_optout' => true]);
    $dijeda = reseller();
    JedaBot::create(['chat_id' => $dijeda->chat_id, 'sejak' => now()]);
    $berjalan = reseller();
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['crm_customer_id' => $berjalan->id]);
    $baruDapat = reseller();
    $lalu = Broadcast::create(['nama' => 'Lalu', 'pesan' => 'Halo']);
    BroadcastPenerima::create(['broadcast_id' => $lalu->id, 'crm_customer_id' => $baruDapat->id, 'chat_id' => $baruDapat->chat_id, 'status' => BroadcastPenerima::TERKIRIM, 'terkirim_at' => now()->subDay()]);

    $jumlah = fn (array $isi = []) => PenerimaBroadcast::query(filterRestock($isi))->count();

    expect($jumlah())->toBe(2)
        ->and(PenerimaBroadcast::query(filterRestock())->pluck('id')->sort()->values()->all())->toBe(collect([$sasaran->id, CrmCustomer::query()->where('kota', 'Lawang')->value('id')])->sort()->values()->all())
        ->and($jumlah(['kecualikan_order' => false]))->toBe(3)
        ->and($jumlah(['kecualikan_baru' => false]))->toBe(3)
        ->and($jumlah(['kota' => 'SURABAYA']))->toBe(0)
        ->and($jumlah(['interaksi' => 'WINBACK']))->toBe(1)
        ->and($jumlah(['minat' => []]))->toBe(3);

    $this->actingAs(User::factory()->falah()->create())
        ->getJson('/broadcast/perkiraan?'.http_build_query(filterRestock(['kecualikan_order' => '0'])))
        ->assertExactJson(['jumlah' => 3]);
});

test('the daily quota counts what was sent today', function () {
    $b = Broadcast::create(['nama' => 'Promo', 'pesan' => 'Halo']);
    foreach (range(1, 3) as $i) {
        $c = CrmCustomer::factory()->create();
        BroadcastPenerima::create(['broadcast_id' => $b->id, 'crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'status' => BroadcastPenerima::TERKIRIM, 'terkirim_at' => $i === 3 ? now()->subDay() : now()->subHour()]);
    }

    $this->actingAs(User::factory()->falah()->create())
        ->get('/broadcast/baru')
        ->assertInertia(fn (Assert $page) => $page->where('kuota', 298));
});

test('a campaign is saved, sent or scheduled', function () {
    $falah = User::factory()->falah()->create();
    $a = reseller(['sesi_wa' => 'bangbana-2']);
    reseller();
    $isi = fn (string $aksi, array $lain = []) => [
        'aksi' => $aksi, 'nama' => 'Restock Kripca Oktober', 'pesan' => 'Halo kak {nama}', 'filter' => filterRestock(),
        'gambar' => null, 'tombol' => ['Mau order', 'Lihat harga'], 'stop' => true, 'konteks' => true,
        'kecepatan' => 'AMAN', 'nomor_bot' => '', 'tanggal' => '2026-09-30', 'jam' => '09:00', ...$lain,
    ];

    $jawaban = $this->actingAs($falah)->post('/broadcast', $isi('kirim'));
    $kirim = Broadcast::query()->latest('id')->first();
    $jawaban->assertRedirect("/broadcast?pilih={$kirim->id}");
    expect($kirim->status)->toBe(StatusBroadcast::Mengirim)
        ->and($kirim->jumlah_target)->toBe(2)
        ->and($kirim->segmen_keterangan)->toBe('Reseller · Tanya harga, Order dibuat · HOT, WARM · minat Keripik kaca · Malang Raya')
        ->and($kirim->opsi)->toEqual(['tombol' => ['Mau order', 'Lihat harga'], 'stop' => true, 'konteks' => true, 'nomor_bot' => null])
        ->and($kirim->penerima()->where('status', BroadcastPenerima::MENUNGGU)->count())->toBe(2)
        ->and($kirim->penerima()->where('crm_customer_id', $a->id)->value('sesi_wa'))->toBe('bangbana-2');

    // both are now queued for the first one, so they count only without that exclusion
    $this->actingAs($falah)->post('/broadcast', $isi('jadwal'))->assertSessionHasErrors('filter');
    $this->actingAs($falah)->post('/broadcast', $isi('jadwal', ['filter' => filterRestock(['kecualikan_baru' => false])]));
    $jadwal = Broadcast::query()->latest('id')->first();
    expect($jadwal->status)->toBe(StatusBroadcast::Terjadwal)
        ->and($jadwal->jadwal_at?->format('Y-m-d H:i'))->toBe('2026-09-30 09:00')
        ->and($jadwal->penerima()->count())->toBe(0);

    $this->actingAs($falah)->post('/broadcast', $isi('draf', ['filter' => filterRestock(['kota' => 'SURABAYA'])]));
    expect(Broadcast::query()->latest('id')->first()->status)->toBe(StatusBroadcast::Draf)
        ->and(ActivityLog::query()->where('kategori', KategoriAktivitas::Broadcast->value)->pluck('aksi')->all())
        ->toBe(['Kirim broadcast', 'Jadwalkan broadcast', 'Simpan draf broadcast']);
});

test('sending needs a name, a message, recipients and a time to come', function () {
    $falah = User::factory()->falah()->create();
    $isi = ['aksi' => 'jadwal', 'nama' => '', 'pesan' => '', 'filter' => filterRestock(), 'kecepatan' => 'AMAN', 'tanggal' => '2026-09-27', 'jam' => '09:00'];

    $this->actingAs($falah)->post('/broadcast', $isi)->assertSessionHasErrors(['nama' => 'Isi nama kampanye.', 'pesan' => 'Isi pesan kampanye.']);
    $this->actingAs($falah)->post('/broadcast', [...$isi, 'nama' => 'A', 'pesan' => 'B'])
        ->assertSessionHasErrors(['filter' => 'Belum ada customer yang cocok dengan filter ini.']);

    reseller();
    $this->actingAs($falah)->post('/broadcast', [...$isi, 'nama' => 'A', 'pesan' => 'B'])
        ->assertSessionHasErrors(['tanggal' => 'Pilih waktu yang belum lewat.']);
    expect(Broadcast::query()->count())->toBe(0);
});

test('a test message reaches the supervisor as the previewed customer would get it', function () {
    ConfigToko::create(['kunci' => 'ADMIN_WA', 'nilai' => '6281234560007']);
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    $rina = reseller(['nama' => 'Rina Maharani']);
    $rekam = PengirimRekam::pasang();

    $this->actingAs(User::factory()->falah()->create())
        ->post('/broadcast/uji', [
            'nama' => 'Restock Kripca Oktober', 'pesan' => 'Halo kak {nama}, {minat_produk} ready', 'tombol' => ['Mau order'],
            'stop' => true, 'nomor_bot' => 'bangbana-2', 'contoh' => $rina->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($rekam->terkirim)->toBe([[
        'sesi' => 'bangbana-2',
        'chat_id' => '6281234560007@c.us',
        'teks' => "Halo kak Rina, Kripca 1,8 kg ready\n\n1. Mau order\n\nBalas STOP bila tidak ingin menerima info lagi",
        'gambar' => null,
    ]])->and(ActivityLog::query()->get(['aksi', 'detail'])->toArray())->toBe([
        ['aksi' => 'Kirim uji broadcast', 'detail' => 'Restock Kripca Oktober · ke nomor supervisor'],
    ]);
});

test('a test message needs the supervisor\'s number and says when WhatsApp refuses it', function () {
    $falah = User::factory()->falah()->create();
    $isian = ['nama' => 'Restock Kripca Oktober', 'pesan' => 'Halo kak'];

    $this->actingAs($falah)->post('/broadcast/uji', $isian)
        ->assertSessionHasErrors(['pesan' => 'Nomor supervisor belum diisi di Pengaturan.']);

    ConfigToko::create(['kunci' => 'ADMIN_WA', 'nilai' => '6281234560007']);
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    $this->actingAs($falah)->post('/broadcast/uji', $isian)
        ->assertSessionHasErrors(['pesan' => 'Pesan uji gagal dikirim: WAHA tidak bisa dihubungi']);
    expect(ActivityLog::query()->count())->toBe(0);
});
