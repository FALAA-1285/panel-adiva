<?php

use App\Bot\WahaPengirim;
use App\Enums\JenisNotifikasi;
use App\Enums\PengirimPesan;
use App\Enums\StatusBroadcast;
use App\Enums\TipeCustomer;
use App\Jobs\KirimBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\JedaBot;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Models\User;
use App\Support\AturanBroadcast;
use App\Support\PenerimaBroadcast;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PengirimRekam;

beforeEach(function () {
    // the real clock and queue (demo mode has its own test below)
    config(['app.demo' => false]);
    $this->travelTo(Carbon::parse('2026-09-28 13:42'));
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    Queue::fake();
});

/**
 * A campaign that is sending, with its chain named giliran(1) and one waiting row per customer.
 *
 * @param  array<string, mixed>  $isi
 * @param  list<CrmCustomer>  $customer
 */
function kampanyeKirim(array $isi = [], array $customer = []): Broadcast
{
    $b = Broadcast::create([
        'nama' => 'Promo Molreng', 'pesan' => 'Halo kak {nama}', 'status' => StatusBroadcast::Mengirim, 'kecepatan' => 'AMAN',
        'jumlah_target' => count($customer), 'mulai_at' => now(), 'giliran' => giliran(1), ...$isi,
    ]);

    foreach ($customer as $c) {
        BroadcastPenerima::create(['broadcast_id' => $b->id, 'crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'sesi_wa' => $c->sesi_wa, 'status' => BroadcastPenerima::MENUNGGU]);
    }

    return $b;
}

function langkah(Broadcast $b, PengirimRekam $rekam, ?string $giliran = null, int $gagal = 0): void
{
    (new KirimBroadcast($b->id, $giliran ?? giliran(1), $gagal))->handle($rekam);
}

/**
 * The name of a sending chain (broadcast.giliran is a uuid).
 */
function giliran(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

test('one message goes out as the customer gets it, then the next is queued after the wait', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'kota' => 'Malang', 'minat_produk' => 'Molreng', 'sesi_wa' => 'bangbana-2']);
    $b = kampanyeKirim([
        'pesan' => 'Halo kak {nama}, {minat_produk} sudah ready di {kota}.',
        'opsi' => ['tombol' => ['Mau order', 'Lihat paket reseller'], 'stop' => true],
    ], [$rina, CrmCustomer::factory()->create()]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam);

    expect($rekam->terkirim)->toBe([[
        'sesi' => 'bangbana-2',
        'chat_id' => $rina->chat_id,
        'teks' => "Halo kak Rina, Molreng sudah ready di Malang.\n\n1. Mau order\n2. Lihat paket reseller\n\nBalas STOP bila tidak ingin menerima info lagi",
        'gambar' => null,
    ]]);
    $baris = $b->penerima()->where('crm_customer_id', $rina->id)->sole();
    expect($baris->status)->toBe(BroadcastPenerima::TERKIRIM)
        ->and($baris->terkirim_at?->format('H:i'))->toBe('13:42')
        ->and($baris->getAttribute('wa_message_id'))->toBe('rekam-1');

    // in Rina's thread, marked as the campaign's
    expect(Pesan::query()->sole()->only(['pengirim', 'broadcast_id', 'wa_message_id', 'meta']))->toBe([
        'pengirim' => PengirimPesan::Adiva, 'broadcast_id' => $b->id, 'wa_message_id' => 'rekam-1', 'meta' => ['otomatis' => 'BROADCAST'],
    ]);

    $tunggu = now()->diffInSeconds($b->refresh()->berikutnya_at);
    expect($tunggu)->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(45);
    Queue::assertPushed(KirimBroadcast::class, fn (KirimBroadcast $j) => $j->giliran === giliran(1) && $j->delay == $b->berikutnya_at);
});

test('a campaign with a product photo sends the photo with the text as caption', function () {
    $gambar = GambarProduk::create(['title' => 'Molreng Reguler', 'image_url' => 'https://cdn.test/molreng.jpg', 'mime_type' => 'image/jpeg']);
    $b = kampanyeKirim(['gambar_produk_id' => $gambar->id], [CrmCustomer::factory()->create(['nama' => 'Kopma UB'])]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam);

    expect($rekam->terkirim[0]['gambar'])->toBe('https://cdn.test/molreng.jpg')
        ->and($rekam->terkirim[0]['teks'])->toBe('Halo kak Kopma')
        ->and(Pesan::query()->sole()->lampiran_keterangan)->toBe('Foto produk · Molreng Reguler');
});

test('WAHA gets the photo through sendImage', function () {
    config(['services.waha.url' => 'https://waha.test', 'services.waha.key' => 'kunci']);
    Http::fake(['waha.test/api/sendImage' => Http::response(['id' => 'true_x_1'])]);

    $id = (new WahaPengirim)->kirimGambar('bangbana-waha', '6281@c.us', 'https://cdn.test/molreng.jpg', 'image/jpeg', 'Halo kak');

    expect($id)->toBe('true_x_1');
    Http::assertSent(fn (Request $r) => $r->data() === [
        'session' => 'bangbana-waha', 'chatId' => '6281@c.us',
        'file' => ['mimetype' => 'image/jpeg', 'url' => 'https://cdn.test/molreng.jpg', 'filename' => 'molreng.jpg'],
        'caption' => 'Halo kak',
    ]);
});

test('outside 08.00–20.00 nothing is sent until the next morning', function (string $jam, string $besok) {
    $this->travelTo(Carbon::parse("2026-09-28 {$jam}"));
    $b = kampanyeKirim(customer: [CrmCustomer::factory()->create()]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam);

    expect($rekam->terkirim)->toBe([])
        ->and($b->refresh()->berikutnya_at?->format('Y-m-d H:i'))->toBe($besok);
    Queue::assertPushed(KirimBroadcast::class);
})->with([
    'malam' => ['20:00', '2026-09-29 08:00'],
    'subuh' => ['05:30', '2026-09-28 08:00'],
]);

test('after 300 messages today every campaign waits for tomorrow', function () {
    $lain = kampanyeKirim(['nama' => 'Lain', 'giliran' => giliran(0)]);
    BroadcastPenerima::query()->insert(collect(range(1, AturanBroadcast::BATAS_HARIAN))->map(fn (int $i) => [
        'broadcast_id' => $lain->id, 'chat_id' => "628{$i}@c.us", 'status' => BroadcastPenerima::TERKIRIM, 'terkirim_at' => now()->subHour(),
    ])->all());
    $b = kampanyeKirim(customer: [CrmCustomer::factory()->create()]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam);

    expect($rekam->terkirim)->toBe([])
        ->and($b->refresh()->berikutnya_at?->format('Y-m-d H:i'))->toBe('2026-09-29 08:00');
});

test('a failed message is noted and the campaign pauses itself after 5 in a row', function () {
    $b = kampanyeKirim(customer: CrmCustomer::factory()->count(3)->create()->all());
    $rekam = new PengirimRekam(tolak: 'WAHA menolak (422): session STOPPED');

    langkah($b, $rekam, gagal: 3);
    expect($b->refresh()->status)->toBe(StatusBroadcast::Mengirim)
        ->and($b->penerima()->where('status', BroadcastPenerima::GAGAL)->value('keterangan_gagal'))->toBe('WAHA menolak (422): session STOPPED');
    Queue::assertPushed(KirimBroadcast::class, fn (KirimBroadcast $j) => $j->gagalBeruntun === 4);

    langkah($b, $rekam, gagal: 4);
    expect($b->refresh()->status)->toBe(StatusBroadcast::Dijeda)
        ->and($b->catatan)->toBe('Dijeda otomatis: 5 gagal beruntun')
        ->and($b->penerima()->where('status', BroadcastPenerima::MENUNGGU)->count())->toBe(1);
});

test('opted-out and paused customers are skipped without counting as failures', function () {
    $stop = CrmCustomer::factory()->create(['status_optout' => true]);
    $dijeda = CrmCustomer::factory()->create();
    JedaBot::create(['chat_id' => $dijeda->chat_id, 'sejak' => now(), 'oleh' => 'Falah']);
    $b = kampanyeKirim(customer: [$stop, $dijeda, CrmCustomer::factory()->create()]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam, gagal: 4);
    langkah($b, $rekam, gagal: 4);

    expect($b->refresh()->status)->toBe(StatusBroadcast::Mengirim)
        ->and($b->penerima()->orderBy('id')->pluck('keterangan_gagal')->all())->toBe(['dilewati: opt-out', 'dilewati: sedang dijeda', null])
        ->and($rekam->terkirim)->toBe([]);
});

test('the last message finishes the campaign and reports to the supervisor', function () {
    $b = kampanyeKirim(['nama' => 'Restock Kripca'], [CrmCustomer::factory()->create()]);

    langkah($b, new PengirimRekam);

    expect($b->refresh()->status)->toBe(StatusBroadcast::Selesai)
        ->and($b->selesai_at?->format('H:i'))->toBe('13:42')
        ->and(Notifikasi::query()->sole()->only(['jenis', 'judul', 'detail', 'broadcast_id']))->toBe([
            'jenis' => JenisNotifikasi::BroadcastSelesai->value, 'judul' => 'Broadcast selesai',
            'detail' => 'Restock Kripca · 1 terkirim · 0 balas · 0 order', 'broadcast_id' => $b->id,
        ]);
    Queue::assertNothingPushed();
});

test('a scheduled campaign locks its recipients when it starts', function () {
    $filter = PenerimaBroadcast::normalisasi(['tipe' => ['RESELLER'], 'interaksi' => '90_HARI']);
    $b = kampanyeKirim(['status' => StatusBroadcast::Terjadwal, 'filter' => $filter, 'jadwal_at' => now()->addHour(), 'mulai_at' => null, 'jumlah_target' => 1]);
    $reseller = fn () => CrmCustomer::factory()->create(['tipe_customer' => TipeCustomer::Reseller, 'tgl_interaksi_terakhir' => now()->subDay()]);
    $reseller();
    $rekam = new PengirimRekam;

    // not yet: wait for jadwal_at
    langkah($b, $rekam);
    expect($rekam->terkirim)->toBe([])
        ->and($b->refresh()->berikutnya_at?->format('H:i'))->toBe('14:42')
        ->and($b->penerima()->count())->toBe(0);

    // a reseller who joined in the meantime is in
    $reseller();
    $this->travel(1)->hours();
    langkah($b, $rekam);

    expect($b->refresh()->status)->toBe(StatusBroadcast::Mengirim)
        ->and($b->jumlah_target)->toBe(2)
        ->and($b->mulai_at?->format('H:i'))->toBe('14:42')
        ->and($rekam->terkirim)->toHaveCount(1);
});

test('a chain that was replaced stops', function () {
    $b = kampanyeKirim(['giliran' => giliran(2)], [CrmCustomer::factory()->create()]);
    $rekam = new PengirimRekam;

    langkah($b, $rekam, giliran(3));

    expect($rekam->terkirim)->toBe([]);
    Queue::assertNothingPushed();
});

test('sending now and sending again start a new chain', function () {
    $falah = User::factory()->falah()->create();
    $b = kampanyeKirim(['status' => StatusBroadcast::Selesai, 'giliran' => giliran(1)]);
    BroadcastPenerima::create(['broadcast_id' => $b->id, 'chat_id' => '6281@c.us', 'status' => BroadcastPenerima::GAGAL, 'keterangan_gagal' => 'nomor tidak aktif']);

    $this->actingAs($falah)->post("/broadcast/{$b->id}/kirim-ulang")->assertRedirect();

    expect($b->refresh()->giliran)->not->toBe(giliran(1))
        ->and($b->status)->toBe(StatusBroadcast::Mengirim);
    Queue::assertPushed(KirimBroadcast::class, fn (KirimBroadcast $j) => $j->broadcastId === $b->id && $j->giliran === $b->giliran);
});

test('broadcast:lanjutkan restarts a chain that is late and leaves the others', function () {
    $macet = kampanyeKirim(['nama' => 'Macet', 'berikutnya_at' => now()->subMinutes(10)]);
    $jalan = kampanyeKirim(['nama' => 'Jalan', 'berikutnya_at' => now()->addSeconds(20)]);
    kampanyeKirim(['nama' => 'Selesai', 'status' => StatusBroadcast::Selesai]);

    $this->artisan('broadcast:lanjutkan')->expectsOutput('Dilanjutkan: Macet')->assertSuccessful();

    expect($macet->refresh()->giliran)->not->toBe(giliran(1))
        ->and($jalan->refresh()->giliran)->toBe(giliran(1));
    Queue::assertPushed(KirimBroadcast::class, 1);
});

test('in demo mode the next message goes at once and a long wait is not queued', function () {
    config(['app.demo' => true]);

    KirimBroadcast::jadwalkan(1, giliran(1), now()->addSeconds(30));
    Queue::assertPushed(KirimBroadcast::class, fn (KirimBroadcast $j) => $j->delay === null);

    KirimBroadcast::jadwalkan(1, giliran(1), now()->addHours(18));
    Queue::assertPushed(KirimBroadcast::class, 1);
});

test('the demo campaigns are not restarted', function () {
    config(['app.demo' => true]);
    kampanyeKirim(['berikutnya_at' => null]);

    $this->artisan('broadcast:lanjutkan')->assertSuccessful();

    Queue::assertNothingPushed();
});
