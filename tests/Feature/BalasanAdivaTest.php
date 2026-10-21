<?php

use App\Bot\JawabAdiva;
use App\Bot\LokalJawabAdiva;
use App\Bot\PerintahGagal;
use App\Enums\TahapFunnel;
use App\Jobs\BalasanGagalAdiva;
use App\Jobs\KirimBalasanAdiva;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\OrderItem;
use App\Models\Pesan;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PengirimRekam;

/*
 * BOT_DRIVER=lokal, step 3: the panel sends Adiva's answer and writes down what she decided
 * (docs/kontrak-n8n.md §8b). The clock is frozen to Senin, 28 Sep 2026 13:42 WIB.
 */

beforeEach(function () {
    config([
        'services.bot.driver' => 'lokal', 'app.demo' => false,
        'services.waha.url' => 'https://waha.test', 'services.waha.key' => 'kunci',
        'services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia',
    ]);
    ConfigToko::create(['kunci' => 'SESI_UTAMA', 'nilai' => 'bangbana-waha']);
    ConfigToko::create(['kunci' => 'ADMIN_WA', 'nilai' => '6281234560007']);

    $this->wa = PengirimRekam::pasang();
    $this->customer = CrmCustomer::factory()->create(['chat_id' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'nama' => 'Rina Maharani', 'sesi_wa' => 'bangbana-waha']);

    // what RapikanBalasan hands the job
    $this->rb = fn (array $u = []) => [
        'kontak' => 'wa.me/6281234567890', 'chatId' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'session' => 'bangbana-waha',
        'label_nomor_bot' => '', 'balasan' => 'Ada kak 😊 Molreng Rp17.000.', 'balasan_kirim' => 'Ada kak 😊 Molreng Rp17.000.',
        'gelembung_awal' => [], 'perlu_dibalas' => true, 'balasan_otomatis' => false, 'nama' => 'Rina Maharani',
        'tipe_customer' => 'RESELLER', 'tahap_funnel' => 'TANYA_HARGA', 'skor_prospek' => 'WARM', 'minat_produk' => 'Molreng',
        'lokasi_jualan' => '', 'estimasi_modal' => '', 'kota' => 'Malang', 'ringkasan_terakhir' => 'Tanya Molreng',
        'pesan_terakhir_customer' => 'Molreng ada?', 'jumlah_followup' => 0, 'tgl_interaksi_terakhir' => '2026-09-28 13:42',
        'tgl_followup_berikutnya' => '2026-09-28 23:42', 'status_optout' => 'TIDAK', 'butuh_admin' => 'TIDAK',
        'eskalasi_terbuka' => '', 'batal_order_id' => '', 'catatan' => '', 'order_siap' => false,
        'order_id' => 'ORD-20260928134217-7890', 'ganti_order_id' => '', 'rencana_ambil' => '',
        'draft_pesanan' => '{"status":"KOSONG","items":[],"total_produk":0,"budget":0}',
        'order_items' => '', 'order_total' => '', 'peringatan_total' => '', 'order_ongkir' => '', 'nama_penerima' => '',
        'alamat_lengkap' => '', 'kecamatan' => '', 'kode_pos' => '', 'berat_kg' => '', 'kurir_pilihan' => '', 'metode_bayar' => '',
        'no_hp' => '', 'kelurahan' => '', 'kota_kirim' => '', 'provinsi' => '', 'kirim_gambar' => false, 'url_gambar' => '',
        'pilihan_balasan' => [], 'order_items_detail' => [], 'jumlah_pesan' => 1, 'model' => 'gemini-3.6-flash', ...$u,
    ];
    $this->jalankan = fn (array $u = [], ?string $balasanLama = null) => app()->call([new KirimBalasanAdiva(($this->rb)($u), $balasanLama), 'handle']);
});

// ---------------------------------------------------------------- sending

test('the answer reaches the customer and the CRM keeps what Adiva decided', function () {
    Http::fake();

    ($this->jalankan)();

    $this->customer->refresh();

    expect($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0])->toMatchArray(['sesi' => 'bangbana-waha', 'chat_id' => '6281234567890@c.us', 'teks' => 'Ada kak 😊 Molreng Rp17.000.'])
        ->and(Pesan::query()->sole()->only(['jalur', 'status_kirim', 'teks']))->toBe(['jalur' => 'D', 'status_kirim' => 'TERKIRIM', 'teks' => 'Ada kak 😊 Molreng Rp17.000.'])
        ->and($this->customer->tahap_funnel)->toBe(TahapFunnel::TanyaHarga)
        ->and($this->customer->ringkasan_terakhir)->toBe('Tanya Molreng')
        ->and($this->customer->balasan_terakhir_bot)->toBe('Ada kak 😊 Molreng Rp17.000.')
        ->and($this->customer->tahap_sejak)->toHaveKey('TANYA_HARGA')
        ->and(WorkflowEvent::query()->sole()->only(['judul', 'data']))->toEqual(['judul' => 'Pesan dijawab', 'data' => ['jalur' => 'D', 'pesan' => 1, 'model' => 'gemini-3.6-flash', 'ongkir' => false, 'diteruskan' => false]]);
});

test('a long answer comes in bubbles, with typing in between', function () {
    Http::fake();

    ($this->jalankan)(['gelembung_awal' => ['Halo kak 😊', 'Molreng ada kak'], 'balasan_kirim' => 'Mau yang mana kak?']);

    expect(collect($this->wa->terkirim)->pluck('teks')->all())->toBe(['Halo kak 😊', 'Molreng ada kak', 'Mau yang mana kak?'])
        ->and(Pesan::query()->count())->toBe(3);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/startTyping');
});

test('the replies offered are kept with the message', function () {
    Http::fake();

    ($this->jalankan)(['pilihan_balasan' => ['Mau order', 'Lihat harga']]);

    expect(Pesan::query()->sole()->tombol)->toBe(['Mau order', 'Lihat harga']);
});

test('a product photo goes out after the text', function () {
    Http::fake();

    ($this->jalankan)(['kirim_gambar' => true, 'url_gambar' => 'https://cdn.test/molreng.jpg']);

    expect($this->wa->terkirim)->toHaveCount(2)
        ->and($this->wa->terkirim[1]['gambar'])->toBe('https://cdn.test/molreng.jpg')
        ->and(Pesan::query()->where('lampiran_keterangan', 'Foto produk')->count())->toBe(1);
});

test('an auto-reply is not answered, but what Adiva read is still saved', function () {
    Http::fake();

    ($this->jalankan)(['perlu_dibalas' => false, 'balasan' => 'tidak dipakai'], balasanLama: 'Rp17.000 kak');

    expect($this->wa->terkirim)->toBeEmpty()
        ->and($this->customer->refresh()->balasan_terakhir_bot)->toBe('Rp17.000 kak')
        ->and(WorkflowEvent::query()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/stopTyping');
});

test('a CS who took the customer while Adiva was thinking is not interrupted', function () {
    Http::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'sejak' => now()->subMinutes(2), 'sampai' => null]);

    ($this->jalankan)();

    expect($this->wa->terkirim)->toBeEmpty()
        ->and($this->customer->refresh()->ringkasan_terakhir)->toBe('Tanya Molreng');
});

test('a message WhatsApp refuses still saves the CRM, and the supervisor is told', function () {
    Http::fake();
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    ($this->jalankan)();

    expect($this->customer->refresh()->ringkasan_terakhir)->toBe('Tanya Molreng')
        ->and(Notifikasi::query()->where('jenis', 'BALASAN_GAGAL')->sole()->detail)->toContain('Rina Maharani', 'WAHA tidak bisa dihubungi')
        ->and(WorkflowEvent::query()->where('jenis', 'INSIDEN')->sole()->judul)->toBe('Balasan gagal terkirim');
});

test('a question Adiva passes on becomes a notice', function () {
    Http::fake();

    ($this->jalankan)(['butuh_admin' => 'YA', 'catatan' => 'Customer menanyakan potongan ongkir ke Surabaya', 'eskalasi_terbuka' => json_encode(['waktu' => '2026-09-28 13:42', 'alasan' => 'potongan ongkir'])]);

    expect(Notifikasi::query()->where('jenis', 'BUTUH_ADMIN')->sole()->judul)->toBe('Butuh admin: Customer menanyakan potongan ongkir ke Surabaya')
        ->and($this->customer->refresh()->butuh_admin)->toBeTrue()
        ->and(WorkflowEvent::query()->sole()->data['diteruskan'])->toBeTrue();
});

// ---------------------------------------------------------------- the order Adiva took

test('an order Adiva took is written down and the supervisor is told', function () {
    Http::fake();

    ($this->jalankan)([
        'order_siap' => true, 'order_items' => 'Basreng Koin 1 kg Pedas (10)', 'order_total' => '600000', 'order_ongkir' => '38000',
        'order_items_detail' => [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000]],
        'nama_penerima' => 'Rina Maharani', 'alamat_lengkap' => 'Jl. Kalpataru 18', 'kecamatan' => 'Lowokwaru', 'kota_kirim' => 'Malang',
        'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER', 'berat_kg' => '10', 'no_hp' => '081234567890', 'rencana_ambil' => 'transfer besok',
    ]);

    $order = LogOrder::query()->sole();

    expect($order->order_id)->toBe('ORD-20260928134217-7890')
        ->and($order->status_order->value)->toBe('MENUNGGU_KONFIRMASI')
        ->and($order->total_produk)->toBe(600000)
        ->and($order->ongkir)->toBe(38000)
        ->and($order->crm_customer_id)->toBe($this->customer->id)
        ->and($order->catatan_admin)->toBe('RENCANA AMBIL/BAYAR: transfer besok')
        ->and($order->items()->sole()->only(['nama_produk', 'qty', 'harga_satuan', 'subtotal']))->toBe(['nama_produk' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000, 'subtotal' => 600000])
        ->and(Notifikasi::query()->where('jenis', 'ORDER_BARU')->sole()->only(['judul', 'detail', 'penerima']))->toBe(['judul' => 'Order baru menunggu ACC', 'detail' => 'Rina Maharani · ORD-20260928134217-7890 · Rp638.000', 'penerima' => 'ADMIN_WA']);
});

test('an employee order goes to Bu Sarah', function () {
    Http::fake();

    ($this->jalankan)(['order_siap' => true, 'order_items' => 'Basreng (2)', 'order_total' => '120000', 'order_items_detail' => [['nama' => 'Basreng', 'qty' => 2, 'harga_satuan' => 60000]], 'metode_bayar' => 'POTONG GAJI', 'tipe_customer' => 'KARYAWAN', 'nama_penerima' => 'Rina']);

    expect(LogOrder::query()->sole()->status_order->value)->toBe('KARYAWAN')
        ->and(Notifikasi::query()->where('jenis', 'ORDER_KARYAWAN')->sole()->penerima)->toBe('SARAH_WA');
});

test('the same order again while the first still waits is not written twice', function () {
    Http::fake();
    $order = LogOrder::factory()->create([
        'order_id' => 'ORD-260928-001', 'chat_id' => '6281234567890@c.us', 'crm_customer_id' => $this->customer->id, 'tanggal' => now()->subHours(2),
        'status_order' => 'MENUNGGU_KONFIRMASI', 'total_produk' => 600000, 'ongkir' => 38000, 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
    ]);
    OrderItem::create(['log_order_id' => $order->id, 'urutan' => 1, 'nama_produk' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000, 'subtotal' => 600000]);

    ($this->jalankan)([
        'order_siap' => true, 'order_items' => 'Basreng Koin 1 kg Pedas (10)', 'order_total' => '600000', 'order_ongkir' => '38000',
        'order_items_detail' => [['nama' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000]], 'kurir_pilihan' => 'JNE', 'metode_bayar' => 'TRANSFER',
    ]);

    expect(LogOrder::query()->count())->toBe(1)
        ->and(Notifikasi::query()->where('jenis', 'ORDER_BARU')->count())->toBe(0);
});

test('a revision closes the order it replaces and says so', function () {
    Http::fake();
    $lama = LogOrder::factory()->create([
        'order_id' => 'ORD-260928-001', 'chat_id' => '6281234567890@c.us', 'crm_customer_id' => $this->customer->id, 'tanggal' => now()->subHours(2),
        'status_order' => 'MENUNGGU_KONFIRMASI', 'total_produk' => 17000, 'ongkir' => 0, 'catatan_admin' => 'dicatat Adiva',
    ]);
    OrderItem::create(['log_order_id' => $lama->id, 'urutan' => 1, 'nama_produk' => 'Molreng', 'qty' => 1, 'harga_satuan' => 17000, 'subtotal' => 17000]);

    ($this->jalankan)([
        'order_siap' => true, 'ganti_order_id' => 'ORD-260928-001', 'order_items' => 'Basreng (2)', 'order_total' => '120000',
        'order_items_detail' => [['nama' => 'Basreng', 'qty' => 2, 'harga_satuan' => 60000]], 'nama_penerima' => 'Rina',
    ]);

    $lama->refresh();

    expect($lama->status_order->value)->toBe('DIBATALKAN')
        ->and($lama->catatan_admin)->toContain('DIGANTI oleh ORD-20260928134217-7890')
        ->and(LogOrder::query()->count())->toBe(2)
        ->and(Notifikasi::query()->where('judul', 'like', 'Catatan untuk%')->sole()->detail)->toContain('REVISI dari ORD-260928-001', 'ditutup otomatis');
});

// ---------------------------------------------------------------- Adiva could not answer

test('when Adiva fails twice the customer gets an apology and the supervisor a notice', function () {
    Http::fake();
    $pesan = ['session' => 'bangbana-waha', 'payload' => ['id' => 'A1', 'from' => '6281234567890@c.us', 'body' => 'Molreng ada?', '_data' => ['notifyName' => 'Rina Maharani']]];

    app()->call([new BalasanGagalAdiva($pesan, '429 quota exceeded'), 'handle']);

    expect($this->wa->terkirim[0]['teks'])->toStartWith('Mohon maaf ya Kak, balasannya sempat tertunda')
        ->and(Notifikasi::query()->sole()->detail)->toContain('Rina Maharani', 'Customer sudah dikirimi pesan maaf', 'batas pemakaian AI')
        ->and(WorkflowEvent::query()->where('jenis', 'INSIDEN')->sole()->judul)->toBe('Adiva gagal menjawab')
        ->and(WorkflowEvent::query()->where('status', 'ERROR')->sole()->judul)->toBe('Error tertangani');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/stopTyping');
});

test('when even the apology will not go the supervisor is told that too', function () {
    Http::fake();
    PengirimRekam::pasang(tolak: 'WAHA mati');

    app()->call([new BalasanGagalAdiva(['session' => 'bangbana-waha', 'payload' => ['id' => 'A1', 'from' => '6281234567890@c.us', 'body' => 'Halo']], ''), 'handle']);

    expect(Notifikasi::query()->sole()->detail)->toContain('Pesan maaf ke customer juga GAGAL terkirim');
});

// ---------------------------------------------------------------- Jawab lewat Adiva

test('the supervisor\'s answer is worded by n8n, sent by the panel, and closes the question', function () {
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'teks' => 'Kak Rina, soal ongkir Surabaya tadi sudah kami cek ya: ada potongan Rp2.000 pakai J&T 🙏'])]);
    $this->customer->forceFill([
        'eskalasi_terbuka' => json_encode(['waktu' => '2026-09-28 11:00', 'alasan' => 'Customer menanyakan potongan ongkir Surabaya']),
        'pesan_terakhir_customer' => 'ada potongan ongkir?', 'balasan_terakhir_bot' => 'Kami cek dulu ya kak',
    ])->save();
    $falah = User::factory()->falah()->create();

    app(LokalJawabAdiva::class)->sampaikan($this->customer, 'bisa kak, ada potongan Rp2.000 pakai J&T', $falah);

    $this->customer->refresh();

    expect($this->wa->terkirim[0]['teks'])->toBe('Kak Rina, soal ongkir Surabaya tadi sudah kami cek ya: ada potongan Rp2.000 pakai J&T 🙏')
        ->and(Pesan::query()->sole()->user_id)->toBe($falah->id)
        ->and($this->customer->eskalasi_terbuka)->toBeNull()
        ->and($this->customer->ringkasan_terakhir)->toContain('Supervisor menjawab', 'bisa kak, ada potongan Rp2.000')
        ->and(json_decode((string) $this->customer->jawaban_supervisor, true))->toMatchArray(['pertanyaan' => 'Customer menanyakan potongan ongkir Surabaya', 'jawaban' => 'bisa kak, ada potongan Rp2.000 pakai J&T']);

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'JAWAB' && $r['pertanyaan'] === 'Customer menanyakan potongan ongkir Surabaya' && $r['jawaban'] === 'bisa kak, ada potongan Rp2.000 pakai J&T');
});

test('a worded answer that invents or drops a number is not used', function (string $teks) {
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'teks' => $teks])]);

    app(LokalJawabAdiva::class)->sampaikan($this->customer, 'bisa kak, ada potongan Rp2.000', User::factory()->falah()->create());

    // the standard sentence carries the supervisor's answer as it was written
    expect($this->wa->terkirim[0]['teks'])->toBe("Kak Rina, tadi sudah kami tanyakan ke supervisor kami ya 🙏\n\nBisa kak, ada potongan Rp2.000");
})->with([
    'a number that was not there' => ['Kak Rina, ada potongan Rp2.000 dan gratis ongkir di atas Rp500.000 ya'],
    'the number is missing' => ['Kak Rina, ada potongan ongkirnya ya'],
    'an internal word' => ['Kak Rina, setelah di-ACC admin ada potongan Rp2.000 ya'],
    'too short' => ['Bisa kak'],
    'nothing at all' => [''],
]);

test('a reply the customer cannot be sent is reported as a failed command', function () {
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'teks' => ''])]);
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    expect(fn () => app(LokalJawabAdiva::class)->sampaikan($this->customer, 'bisa kak', User::factory()->falah()->create()))
        ->toThrow(PerintahGagal::class, 'WhatsApp menolak kiriman');

    expect($this->customer->refresh()->jawaban_supervisor)->toBeNull();
});

test('BOT_DRIVER=lokal uses the panel for "Jawab lewat Adiva"', function () {
    $this->app->offsetUnset(JawabAdiva::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(JawabAdiva::class))->toBeInstanceOf(LokalJawabAdiva::class);
});

// ---------------------------------------------------------------- the daily reminder

test('the morning reminder lists who the bot is still paused for', function () {
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'nama' => 'Rina Maharani', 'sejak' => now()->subDays(2), 'sampai' => now()->addHour(), 'alasan' => 'nego harga']);
    JedaBot::create(['nomor' => '628999', 'chat_id' => '628999@c.us', 'nama' => 'Budi', 'sejak' => now(), 'sampai' => now()->subHour()]);
    JedaBot::create(['nomor' => '628777', 'chat_id' => '628777@c.us', 'nama' => 'Sudah selesai', 'sejak' => now()->subDay(), 'selesai_at' => now()]);

    $this->artisan('jeda:ingatkan')->assertSuccessful();

    $kabar = Notifikasi::query()->sole();

    expect($kabar->jenis)->toBe('PENGINGAT_JEDA')
        ->and($kabar->judul)->toBe('Pengingat daftar jeda')
        // the notice carries the first 300 characters, as it always did
        ->and($kabar->detail)->toContain('2 customer belum dilayani', '1. *Rina Maharani*  (2 hari)', 'otomatis aktif: 2026-09-28 14:42', 'alasan: nego harga', '2. *Budi*  (hari ini)')
        ->not->toContain('Sudah selesai')
        ->and(mb_strlen($kabar->detail))->toBe(300);
});

test('nothing is written when nobody is paused', function () {
    $this->artisan('jeda:ingatkan')->assertSuccessful();

    expect(Notifikasi::query()->count())->toBe(0);
});
