<?php

use App\Bot\JawabAdiva;
use App\Bot\MetaPengirim;
use App\Bot\N8nJawabAdiva;
use App\Bot\N8nPerintahOrder;
use App\Bot\PengirimanGagal;
use App\Bot\PengirimSosmed;
use App\Bot\PengirimWa;
use App\Bot\PerintahOrder;
use App\Enums\JenisNotifikasi;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\StatusOrder;
use App\Jobs\KirimPesanSosmed;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Models\SosmedKontak;
use App\Models\SosmedPesan;
use App\Models\User;
use App\Support\KabarWa;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach (['BATAS_BAYAR_JAM' => '5', 'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }

    $this->falah = User::factory()->falah()->create();
});

function n8nPalsu(): void
{
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel-perintah', 'services.n8n.webhook_secret' => 'rahasia']);
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'pesan' => 'beres'])]);
}

// ---------------------------------------------------------------- order commands once WhatsApp-only

test('TUTUP and ACC with a shipping cost reach n8n as the commands it parses', function () {
    n8nPalsu();
    $this->app->bind(PerintahOrder::class, N8nPerintahOrder::class);
    LogOrder::factory()->create(['order_id' => 'ORD-1']);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-3', 'invoice_number' => 'INV/2026/09/140']);

    $this->actingAs($this->falah)->post('/pesanan/ORD-1/aksi', ['aksi' => 'TUTUP', 'catatan' => 'pindah ke CS lain'])->assertSessionHasNoErrors();
    // a revised cost on an order already ACC'd
    $this->actingAs($this->falah)->post('/pesanan/ORD-3/aksi', ['aksi' => 'ACC', 'catatan' => '25rb'])->assertSessionHasNoErrors();

    expect(collect(Http::recorded())->map(fn (array $r) => array_values(array_intersect_key(json_decode($r[0]->body(), true), array_flip(['perintah', 'order_id', 'catatan']))))->all())->toBe([
        ['TUTUP', 'ORD-1', 'pindah ke CS lain'],
        ['ACC', 'ORD-3', '25rb'],
    ])->and(ActivityLog::query()->orderBy('id')->pluck('detail')->all())->toBe([
        'ORD-1 · ditutup tanpa chat ke customer · pindah ke CS lain',
        'ORD-3 · invoice INV/2026/09/140 terkirim · ongkir Rp25.000',
    ]);
});

test('KIRIM of an expedition parcel needs its waybill; the company\'s own courier does not', function () {
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['order_id' => 'ORD-X', 'kurir_pilihan' => 'EKSPEDISI (ONGKIR COD) - JNE']);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['order_id' => 'ORD-Y', 'kurir_pilihan' => 'KURIR MOTOR']);

    $this->actingAs($this->falah)->post('/pesanan/ORD-X/aksi', ['aksi' => 'KIRIM'])
        ->assertSessionHasErrors(['aksi' => 'Nomor resinya belum disertakan. Contoh: JNE1234567890']);
    $this->actingAs($this->falah)->post('/pesanan/ORD-X/aksi', ['aksi' => 'KIRIM', 'catatan' => 'JNE999'])->assertSessionHasNoErrors();
    $this->actingAs($this->falah)->post('/pesanan/ORD-Y/aksi', ['aksi' => 'KIRIM'])->assertSessionHasNoErrors();

    expect(LogOrder::query()->where('order_id', 'ORD-X')->sole()->no_resi)->toBe('JNE999')
        ->and(LogOrder::query()->where('order_id', 'ORD-Y')->sole()->status_order)->toBe(StatusOrder::Dikirim);
});

test('a revised shipping cost keeps the invoice number and the counter', function () {
    ConfigToko::create(['kunci' => 'INVOICE_TERAKHIR', 'nilai' => 'INV/2026/09/141']);
    $order = LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-REV', 'invoice_number' => 'INV/2026/09/140', 'ongkir' => 38000]);

    $this->actingAs($this->falah)->post('/pesanan/ORD-REV/aksi', ['aksi' => 'ACC', 'catatan' => 'Rp30.000'])->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->invoice_number)->toBe('INV/2026/09/140')
        ->and($order->ongkir)->toBe(30000)
        ->and($order->status_order)->toBe(StatusOrder::Diacc)
        ->and($order->riwayat()->sole()->teks)->toBe('ACC dari panel · revisi ongkir · invoice dikirim ulang · ongkir Rp30.000')
        ->and(ConfigToko::query()->where('kunci', 'INVOICE_TERAKHIR')->value('nilai'))->toBe('INV/2026/09/141');
});

test('the order panel offers TUTUP under Lainnya, and ACC again once a cost is typed', function () {
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-260928-014']);

    $this->actingAs($this->falah)
        ->get('/pesanan?order=ORD-260928-014')
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.aksi.0', ['kode' => 'ACC', 'boleh' => false, 'bolehDenganOngkir' => true, 'judul' => 'Order sudah di-ACC · tulis ongkir baru di catatan untuk revisi ongkir', 'gaya' => 'utama'])
            ->where('detail.lainnya.0.kode', 'TUTUP')
            ->where('detail.lainnya.0.boleh', true)
            ->has('detail.lainnya', 1));
});

// ---------------------------------------------------------------- Jawab lewat Adiva

function eskalasi(CrmCustomer $c, string $waktu, ?string $dijawab = null): void
{
    DB::table('crm_customer')->where('id', $c->id)->update([
        'eskalasi_terbuka' => json_encode(['alasan' => 'minta harga distributor 200 pcs basreng', 'waktu' => $waktu]),
        'jawaban_supervisor' => $dijawab ? json_encode(['waktu' => $dijawab, 'jawaban' => 'sudah']) : null,
    ]);
}

test('an open question shows with its answer box, and the answer goes to n8n as JAWAB', function () {
    n8nPalsu();
    $this->app->bind(JawabAdiva::class, N8nJawabAdiva::class);
    $hendra = CrmCustomer::factory()->create(['nama' => 'Pak Hendra', 'chat_id' => '6281200000001@c.us']);
    eskalasi($hendra, '2026-09-28 12:48');

    $this->actingAs($this->falah)
        ->get("/percakapan?pilih={$hendra->id}")
        ->assertInertia(fn (Assert $page) => $page->where('percakapan.eskalasi', ['pertanyaan' => 'minta harga distributor 200 pcs basreng', 'sejak' => '12:48']));

    $this->actingAs($this->falah)
        ->post("/percakapan/{$hendra->id}/jawab", ['jawaban' => 'Bisa, Rp9.500 per pack untuk 200 pcs'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', 'Jawaban disampaikan Adiva ke Pak Hendra.');

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'JAWAB' && $r['chat_id'] === '6281200000001@c.us'
        && $r['jawaban'] === 'Bisa, Rp9.500 per pack untuk 200 pcs' && $r['oleh']['nama'] === 'Falah');
    expect(ActivityLog::query()->sole()->aksi)->toBe('Jawab lewat Adiva');
});

test('an answered or old question is not shown', function () {
    $dijawab = CrmCustomer::factory()->create();
    $lama = CrmCustomer::factory()->create();
    eskalasi($dijawab, '2026-09-28 12:48', '2026-09-28 13:00');
    eskalasi($lama, '2026-09-24 09:00');

    foreach ([$dijawab, $lama] as $c) {
        $this->actingAs($this->falah)
            ->get("/percakapan?pilih={$c->id}")
            ->assertInertia(fn (Assert $page) => $page->where('percakapan.eskalasi', null));
    }
});

test('without n8n the answer goes into the chat as typed and the question closes', function () {
    $hendra = CrmCustomer::factory()->create(['nama' => 'Pak Hendra']);
    eskalasi($hendra, '2026-09-28 12:48');

    $this->actingAs($this->falah)->post("/percakapan/{$hendra->id}/jawab", ['jawaban' => 'Bisa kak'])->assertSessionHasNoErrors();
    $this->actingAs($this->falah)->post("/percakapan/{$hendra->id}/jawab", ['jawaban' => ' '])->assertSessionHasErrors(['jawaban' => 'Tulis jawabannya dulu.']);

    expect(Pesan::query()->sole()->only(['pengirim', 'teks']))->toBe(['pengirim' => PengirimPesan::Adiva, 'teks' => 'Bisa kak'])
        ->and($hendra->refresh()->getRawOriginal('eskalasi_terbuka'))->toBeNull();
});

// ---------------------------------------------------------------- the supervisor's short WhatsApp

test('each new notification goes out once as a short WhatsApp, following the switches', function () {
    config(['app.demo' => false]);
    foreach (['ADMIN_WA' => '6281234560007', 'SARAH_WA' => '6281298760646', 'SESI_UTAMA' => 'bangbana-waha', 'NOTIF_WA_MATI' => 'jeda_chat'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
    $wa = new class implements PengirimWa
    {
        /** @var list<array{0: string, 1: string, 2: string}> */
        public array $kirim = [];

        public function kirimTeks(string $sesi, string $chatId, string $teks): string
        {
            $this->kirim[] = [$sesi, $chatId, $teks];

            return 'wa-'.count($this->kirim);
        }

        public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
        {
            return '';
        }

        public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
        {
            return '';
        }
    };
    $this->app->instance(PengirimWa::class, $wa);
    $buat = fn (JenisNotifikasi $jenis, array $isi = []) => Notifikasi::factory()->create([
        'jenis' => $jenis->value, 'kategori' => $jenis->kategori()->value, 'dibaca_at' => null, ...$isi,
    ]);
    $order = $buat(JenisNotifikasi::OrderBaru, ['judul' => 'Order baru menunggu ACC', 'detail' => 'Rina Maharani · ORD-260928-014 · Rp1.283.000']);
    $buat(JenisNotifikasi::OrderKaryawan, ['penerima' => 'SARAH_WA', 'judul' => 'Order karyawan']);
    $mati = $buat(JenisNotifikasi::JedaChat);
    $panel = $buat(JenisNotifikasi::BroadcastSelesai);
    $lama = $buat(JenisNotifikasi::ButuhAdmin, ['waktu' => now()->subMinutes(30)]);

    $this->artisan('notifikasi:kirim-wa')->assertSuccessful();
    $this->artisan('notifikasi:kirim-wa')->assertSuccessful();

    expect($wa->kirim)->toHaveCount(2)
        ->and($wa->kirim[0])->toBe(['bangbana-waha', '6281234560007@c.us', "*Order baru menunggu ACC*\nRina Maharani · ORD-260928-014 · Rp1.283.000\n\nBuka di panel: ".route('notifikasi.buka', $order)])
        ->and($wa->kirim[1][1])->toBe('6281298760646@c.us')
        ->and(Notifikasi::query()->orderBy('id')->pluck('wa_status')->all())->toBe(['TERKIRIM', 'TERKIRIM', 'MATI', 'PANEL', null])
        ->and($mati->refresh()->wa_dikirim_at)->toBeNull()
        ->and($panel->refresh()->wa_status)->toBe('PANEL')
        ->and($lama->refresh()->wa_status)->toBeNull();
});

test('nothing is sent in demo mode', function () {
    Notifikasi::factory()->create(['jenis' => JenisNotifikasi::OrderBaru->value, 'dibaca_at' => null]);

    $this->artisan('notifikasi:kirim-wa')->assertSuccessful();

    expect(Notifikasi::query()->sole()->wa_status)->toBeNull();
});

test('kinds without their own type are told apart for the switches', function () {
    $order = LogOrder::factory()->create(['dobel_dengan' => 'ORD-LAMA']);
    $n = fn (JenisNotifikasi $jenis, string $judul, ?int $orderId = null) => KabarWa::baris(Notifikasi::factory()->make(['jenis' => $jenis->value, 'judul' => $judul, 'detail' => null, 'log_order_id' => $orderId]));

    expect($n(JenisNotifikasi::OrderBaru, 'Order baru menunggu ACC', $order->id))->toBe('dobel')
        ->and($n(JenisNotifikasi::OrderBaru, 'Order baru menunggu ACC'))->toBe('order_baru')
        ->and($n(JenisNotifikasi::BuktiMasuk, 'Bukti transfer menunggu dicek'))->toBe('bukti_belum_dicek')
        ->and($n(JenisNotifikasi::BuktiMasuk, 'Bukti transfer masuk'))->toBe('lampiran')
        ->and($n(JenisNotifikasi::WorkflowError, 'Tabel jeda gagal dibaca'))->toBe('jeda_error')
        ->and($n(JenisNotifikasi::WorkflowError, 'Batas Bayar bermasalah'))->toBe('workflow_error')
        ->and($n(JenisNotifikasi::LaporanHarian, 'Laporan harian'))->toBe('terjadwal');
});

test('Notifikasi WhatsApp rows switch off and on from Pengaturan', function () {
    $this->actingAs($this->falah)->patch('/pengaturan/kabar-wa', ['baris' => 'dobel', 'nyala' => false])
        ->assertInertiaFlash('toast', 'Kabar WhatsApp dimatikan. Notifikasinya tetap masuk panel.');
    $this->actingAs($this->falah)->get('/pengaturan')->assertInertia(fn (Assert $page) => $page->where('kabarWaMati', ['dobel']));
    $this->actingAs($this->falah)->patch('/pengaturan/kabar-wa', ['baris' => 'dobel', 'nyala' => true]);
    $this->actingAs($this->falah)->patch('/pengaturan/kabar-wa', ['baris' => 'semua', 'nyala' => false])->assertSessionHasErrors('baris');

    expect(KabarWa::mati())->toBe([])
        ->and(ActivityLog::query()->count())->toBe(2);
});

// ---------------------------------------------------------------- Instagram & Facebook

function kontak(array $isi = []): SosmedKontak
{
    return SosmedKontak::create([
        'id_pengirim' => '17841400000000001', 'platform' => 'IG', 'status_bot' => 'AKTIF', 'nama' => 'Rina Snack', 'username' => 'rina.snack',
        'pesan_terakhir' => 'kak ready basreng?', 'butuh_admin' => 'YA', 'jumlah_pesan' => 3,
        'waktu_pertama' => '2026-09-28T05:00:00Z', 'waktu_terakhir' => '2026-09-28T06:30:00Z', ...$isi,
    ]);
}

test('the Instagram & Facebook page lists the DMs and opens one', function () {
    $rina = kontak();
    kontak(['id_pengirim' => '900', 'platform' => 'FB', 'nama' => null, 'username' => null, 'butuh_admin' => 'TIDAK',
        'status_bot' => 'JEDA', 'jeda_sampai' => now()->addHour()->toIso8601String(), 'jeda_oleh' => 'Dina', 'waktu_terakhir' => '2026-09-28T04:00:00Z']);
    SosmedPesan::create(['sosmed_kontak_id' => $rina->id, 'waktu' => now(), 'pengirim' => PengirimPesan::Customer, 'teks' => 'kak ready basreng?']);

    $this->actingAs($this->falah)
        ->get("/sosmed?pilih={$rina->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sosmed')
            ->where('tab', [
                ['kode' => 'semua', 'label' => 'Semua', 'n' => 2], ['kode' => 'ig', 'label' => 'Instagram', 'n' => 1],
                ['kode' => 'fb', 'label' => 'Facebook', 'n' => 1], ['kode' => 'admin', 'label' => 'Butuh admin', 'n' => 1],
                ['kode' => 'jeda', 'label' => 'Dijeda', 'n' => 1],
            ])
            ->where('daftar.0.nama', 'Rina Snack')
            ->where('daftar.0.status', ['label' => 'Butuh admin', 'nada' => 'amber'])
            ->where('daftar.1.nama', 'Pengguna Facebook ·900')
            ->where('daftar.1.status.nada', 'gray')
            ->where('kontak.profil', 'https://instagram.com/rina.snack')
            ->where('kontak.pesan.0.teks', 'kak ready basreng?')
            ->where('sambungan.tersambung', false)
            ->where('panel.hitungan.sosmed', 1));
});

test('an admin reply goes out through Meta and pauses the bot', function () {
    $this->app->bind(PengirimSosmed::class, MetaPengirim::class);
    ConfigToko::create(['kunci' => 'META_PAGE_TOKEN', 'nilai' => 'token-halaman']);
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => '17841400000000001', 'message_id' => 'm_balas'])]);
    $rina = kontak();

    $this->actingAs($this->falah)->post("/sosmed/{$rina->id}/balas", ['teks' => 'Ready kak'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', 'Balasan dikirim ke Rina Snack.');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer token-halaman')
        && $r['recipient'] === ['id' => '17841400000000001'] && $r['message'] === ['text' => 'Ready kak'] && ! isset($r['messaging_type']));
    expect(SosmedPesan::query()->sole()->only(['pengirim', 'user_id', 'mid', 'status_kirim']))->toBe([
        'pengirim' => PengirimPesan::Admin, 'user_id' => $this->falah->id, 'mid' => 'm_balas', 'status_kirim' => 'TERKIRIM',
    ])->and($rina->refresh()->dijeda())->toBeTrue()
        ->and($rina->jeda_oleh)->toBe('Falah');
});

test('a reply outside Meta\'s 24-hour window says so', function () {
    $this->app->bind(PengirimSosmed::class, MetaPengirim::class);
    ConfigToko::create(['kunci' => 'META_PAGE_TOKEN', 'nilai' => 'token-halaman']);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'outside allowed window', 'code' => 10, 'error_subcode' => 2018278]], 400)]);
    $pesan = SosmedPesan::create(['sosmed_kontak_id' => kontak(['platform' => 'FB'])->id, 'waktu' => now(), 'pengirim' => PengirimPesan::Admin, 'teks' => 'halo', 'status_kirim' => 'MENUNGGU']);

    expect(fn () => (new KirimPesanSosmed($pesan))->handle(app(PengirimSosmed::class)))
        ->toThrow(PengirimanGagal::class, 'Di luar jendela 24 jam Meta');
    Http::assertSent(fn (Request $r) => $r['messaging_type'] === 'RESPONSE');
});

test('Jeda bot and Lanjutkan bot', function () {
    ConfigToko::create(['kunci' => 'SOSMED_JEDA_JAM', 'nilai' => '6']);
    $rina = kontak();

    $this->actingAs($this->falah)->post("/sosmed/{$rina->id}/jeda")->assertInertiaFlash('toast', 'Adiva dijeda untuk Rina Snack sampai 19:42.');
    expect($rina->refresh()->dijeda())->toBeTrue();

    $this->actingAs($this->falah)->post("/sosmed/{$rina->id}/lanjut");
    expect($rina->refresh()->only(['status_bot', 'jeda_sampai', 'jeda_oleh']))->toBe(['status_bot' => 'AKTIF', 'jeda_sampai' => null, 'jeda_oleh' => null]);
});

test('the connection keeps the secrets to itself, and only a supervisor may change it', function () {
    $isi = [
        'META_PAGE_ID' => '1234567890', 'META_IG_ID' => '17841400000000000', 'META_APP_ID' => '555', 'META_VERIFY_TOKEN' => 'kata-rahasia-webhook',
        'SOSMED_JEDA_JAM' => '6', 'SOSMED_BOT_IG' => 'MATI', 'SOSMED_BOT_FB' => 'AKTIF',
        'META_PAGE_TOKEN_BARU' => 'EAAG-token-asli', 'META_APP_SECRET_BARU' => 'app-secret-asli',
    ];

    $this->actingAs(User::factory()->create(['peran' => Peran::StafCs]))->put('/sosmed/sambungan', $isi)->assertForbidden();
    $this->actingAs($this->falah)->put('/sosmed/sambungan', $isi)->assertSessionHasNoErrors();

    $config = ConfigToko::query()->pluck('nilai', 'kunci');
    expect([$config['META_PAGE_TOKEN'], $config['META_APP_SECRET'], $config['SOSMED_BOT_IG']])->toBe(['EAAG-token-asli', 'app-secret-asli', 'MATI'])
        ->and(ActivityLog::query()->pluck('detail')->implode(' '))->not->toContain('EAAG-token-asli')->toContain('Page access token Meta diganti');

    $this->actingAs($this->falah)
        ->get('/sosmed')
        ->assertDontSee('EAAG-token-asli')
        ->assertDontSee('app-secret-asli')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sambungan.tokenTersimpan', true)
            ->where('sambungan.rahasiaTersimpan', true)
            ->where('sambungan.nilai.SOSMED_BOT_IG', 'MATI'));
});

test('the menu item is hidden in demo mode only', function () {
    $this->actingAs($this->falah)->get('/sosmed')->assertInertia(fn (Assert $page) => $page->where('panel.akses.sosmed', false));

    config(['app.demo' => false]);
    $this->actingAs($this->falah)->get('/sosmed')->assertInertia(fn (Assert $page) => $page->where('panel.akses.sosmed', true));
});

// ---------------------------------------------------------------- what n8n writes

test('n8n_catat_sosmed writes each message once and creates the contact', function () {
    $catat = fn (array $j) => DB::select('SELECT * FROM n8n_catat_sosmed(?::jsonb)', [json_encode($j)]);

    $catat(['id_pengirim' => '178', 'platform' => 'ig', 'pengirim' => 'CUSTOMER', 'teks' => 'halo', 'mid' => 'm_1', 'waktu' => '2026-09-28T06:00:00Z', 'nama' => 'Rina']);
    $catat(['id_pengirim' => '178', 'platform' => 'IG', 'pengirim' => 'CUSTOMER', 'teks' => 'halo', 'mid' => 'm_1']);
    $catat(['id_pengirim' => '178', 'pengirim' => 'ADIVA', 'teks' => 'Halo kak', 'mid' => 'm_2']);

    $kontak = SosmedKontak::query()->sole();
    expect($kontak->only(['platform', 'nama', 'status_bot']))->toBe(['platform' => 'IG', 'nama' => 'Rina', 'status_bot' => 'AKTIF'])
        ->and($kontak->pesan()->orderBy('id')->get()->map->only(['pengirim', 'teks', 'status_kirim'])->all())->toBe([
            ['pengirim' => PengirimPesan::Customer, 'teks' => 'halo', 'status_kirim' => null],
            ['pengirim' => PengirimPesan::Adiva, 'teks' => 'Halo kak', 'status_kirim' => 'TERKIRIM'],
        ]);
});

test('n8n_notifikasi files the new kinds under their tabs', function () {
    foreach (['SOSMED_BUTUH_ADMIN', 'LAMPIRAN_MASUK', 'PENGIRIMAN'] as $jenis) {
        DB::select('SELECT * FROM n8n_notifikasi(?::jsonb)', [json_encode(['jenis' => $jenis, 'judul' => $jenis])]);
    }

    expect(Notifikasi::query()->orderBy('id')->pluck('kategori')->all())->toBe(['BUTUH_ADMIN', 'BUTUH_ADMIN', 'ORDER']);
});
