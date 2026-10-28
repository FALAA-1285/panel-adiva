<?php

use App\Bot\AlurPesanMasuk;
use App\Bot\KenaliPengirim;
use App\Bot\LampiranMasuk;
use App\Bot\PesanMasuk;
use App\Bot\SaringPesan;
use App\Enums\StatusOrder;
use App\Jobs\GabungPesanMasuk;
use App\Jobs\ProsesPesanMasuk;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\OrderItem;
use App\Models\Pesan;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Support\CatatPesanMasuk;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PengirimRekam;

/*
 * BOT_DRIVER=lokal: WAHA reports every WhatsApp message to the panel (docs/kontrak-n8n.md §8b).
 * The clock is frozen to Senin, 28 Sep 2026 13:42 WIB by demo mode.
 */

beforeEach(function () {
    config([
        'services.bot.driver' => 'lokal', 'app.demo' => false,
        'services.waha.url' => 'https://waha.test', 'services.waha.key' => 'kunci-waha', 'services.waha.webhook_secret' => 'rahasia-waha',
        'services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia-n8n',
    ]);
    ConfigToko::create(['kunci' => 'ADMIN_WA', 'nilai' => '6281234560007']);
    ConfigToko::create(['kunci' => 'SARAH_WA', 'nilai' => '6281298760646']);

    $this->wa = PengirimRekam::pasang();
    $this->kampanye = fn () => Broadcast::create(['nama' => 'Promo', 'pesan' => 'Halo', 'status' => 'SELESAI', 'kecepatan' => 'AMAN', 'jumlah_target' => 1])->id;
    $this->customer = CrmCustomer::factory()->create(['chat_id' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'nama' => 'Rina Maharani']);

    // a WAHA "message" event; $payload overrides what the message carries
    $this->pesan = fn (array $payload = [], string $sesi = 'bangbana-waha') => [
        'event' => 'message', 'session' => $sesi,
        'payload' => [
            'id' => 'false_6281234567890@c.us_A1', 'from' => '6281234567890@c.us', 'fromMe' => false, 'body' => 'Halo kak, Molreng ada?',
            'hasMedia' => false, '_data' => ['notifyName' => 'Rina Maharani'], ...$payload,
        ],
    ];
    $this->alur = fn (array $body) => app(AlurPesanMasuk::class)->proses(PesanMasuk::dariWebhook($body));
    $this->order = function (StatusOrder $status = StatusOrder::Diacc, array $ubah = []) {
        $order = LogOrder::factory()->status($status)->create([
            'order_id' => 'ORD-260928-014', 'chat_id' => '6281234567890@c.us', 'nomor_wa' => '6281234567890', 'crm_customer_id' => $this->customer->id,
            'nama_penerima' => 'Rina Maharani', 'total_produk' => 1245000, 'ongkir' => 38000, 'kurir_pilihan' => 'JNE', 'catatan_admin' => 'ACC 2026-09-28 10:00', ...$ubah,
        ]);
        OrderItem::create(['log_order_id' => $order->id, 'urutan' => 1, 'nama_produk' => 'Basreng Koin 1 kg Pedas', 'qty' => 10, 'harga_satuan' => 60000, 'subtotal' => 600000]);

        return $order;
    };
});

// ---------------------------------------------------------------- the webhook

test('a signed message is queued and WAHA is answered at once', function () {
    Queue::fake();
    $isi = ($this->pesan)();

    $this->postJson('/api/webhook/waha', $isi, ['X-Webhook-Hmac' => hash_hmac('sha512', json_encode($isi), 'rahasia-waha')])
        ->assertOk()->assertJson(['ok' => true]);

    Queue::assertPushed(ProsesPesanMasuk::class, fn ($job) => $job->body['payload']['id'] === 'false_6281234567890@c.us_A1');
});

test('a wrong or missing signature is refused and nothing is queued', function (?string $tanda, ?string $rahasia) {
    Queue::fake();
    config(['services.waha.webhook_secret' => $rahasia]);

    $this->postJson('/api/webhook/waha', ($this->pesan)(), $tanda === null ? [] : ['X-Webhook-Hmac' => $tanda])->assertUnauthorized();

    Queue::assertNothingPushed();
})->with([
    'no signature' => [null, 'rahasia-waha'],
    'wrong signature' => ['salah', 'rahasia-waha'],
    'no secret set: nothing is accepted' => [hash_hmac('sha512', 'x', ''), ''],
]);

test('sha256 is accepted when WAHA says so', function () {
    Queue::fake();
    $isi = ($this->pesan)();

    $this->postJson('/api/webhook/waha', $isi, ['X-Webhook-Hmac' => hash_hmac('sha256', json_encode($isi), 'rahasia-waha'), 'X-Webhook-Hmac-Algorithm' => 'sha256'])->assertOk();

    Queue::assertPushed(ProsesPesanMasuk::class);
});

test('when the panel does not run the bot the message is left to n8n', function (string $driver, bool $demo) {
    Queue::fake();
    config(['services.bot.driver' => $driver, 'app.demo' => $demo]);

    $this->postJson('/api/webhook/waha', ($this->pesan)())->assertStatus(202);

    Queue::assertNothingPushed();
})->with([['langsung', false], ['tiruan', false], ['lokal', true]]);

test('events other than a message are ignored, and a read tick marks the campaign message read', function () {
    Queue::fake();
    $penerima = BroadcastPenerima::create([
        'broadcast_id' => ($this->kampanye)(), 'chat_id' => '6281234567890@c.us', 'status' => 'TERKIRIM', 'terkirim_at' => now()->subHour(), 'wa_message_id' => 'true_6281234567890@c.us_B1',
    ]);
    $kirim = fn (array $isi) => $this->postJson('/api/webhook/waha', $isi, ['X-Webhook-Hmac' => hash_hmac('sha512', json_encode($isi), 'rahasia-waha')])->assertOk();

    $kirim(['event' => 'session.status', 'session' => 'bangbana-waha', 'payload' => ['status' => 'WORKING']]);
    $kirim(['event' => 'message.ack', 'session' => 'bangbana-waha', 'payload' => ['id' => 'true_6281234567890@c.us_B1', 'ack' => 2]]);

    expect($penerima->refresh()->dibaca_at)->toBeNull();

    $kirim(['event' => 'message.ack', 'session' => 'bangbana-waha', 'payload' => ['id' => 'true_6281234567890@c.us_B1', 'ack' => 3, 'ackName' => 'READ']]);

    expect($penerima->refresh()->dibaca_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- what kind of message it is

test('messages are sorted into the path they take', function (array $payload, bool $admin, string $jalur, bool $abaikan) {
    $pilahan = SaringPesan::pilah(PesanMasuk::dariWebhook(($this->pesan)($payload)), $admin);

    expect($pilahan->jalur)->toBe($jalur)->and($pilahan->abaikan)->toBe($abaikan);
})->with([
    'a customer asking' => [[], false, SaringPesan::CHAT, false],
    'a photo' => [['hasMedia' => true, 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg'], 'body' => ''], false, SaringPesan::LAMPIRAN, false],
    'no text at all' => [['body' => ''], false, SaringPesan::LAMPIRAN, false],
    'a group' => [['from' => '1203630@g.us'], false, SaringPesan::CHAT, false],
    'a group photo is not a proof' => [['from' => '1203630@g.us', 'hasMedia' => true, 'body' => ''], false, SaringPesan::CHAT, false],
    'the bot\'s own echo' => [['fromMe' => true], false, SaringPesan::CHAT, false],
    'a status' => [['from' => 'status@broadcast', 'hasMedia' => true], false, SaringPesan::CHAT, true],
    'a newsletter' => [['from' => '1203@newsletter'], false, SaringPesan::CHAT, true],
    'a reaction' => [['_data' => ['type' => 'reaction']], false, SaringPesan::CHAT, true],
    'a revoked message' => [['_data' => ['type' => 'revoked']], false, SaringPesan::CHAT, true],
    'a picture that lost its media' => [['body' => str_repeat('A1b2', 100)], false, SaringPesan::CHAT, true],
    'an admin\'s order command' => [['body' => 'ACC ORD-260928-014'], true, SaringPesan::PERINTAH_ADMIN, false],
    'an admin writing *bold* batal kirim' => [['body' => '*batal kirim* INV/2026/09/007'], true, SaringPesan::PERINTAH_ADMIN, false],
    'an admin\'s pause command' => [['body' => 'jeda rina 2 jam'], true, SaringPesan::PERINTAH_ADMIN, false],
    'an admin\'s help' => [['body' => 'bantu'], true, SaringPesan::PERINTAH_ADMIN, false],
    'an admin talking' => [['body' => 'baik, saya cek dulu'], true, SaringPesan::ADMIN_LAIN, false],
    'an admin who says "acc" mid-sentence' => [['body' => 'tolong acc ya'], true, SaringPesan::ADMIN_LAIN, false],
]);

test('a location, a contact card and a sticker become a line the AI can read', function (array $payload, string $teks) {
    $pilahan = SaringPesan::pilah(PesanMasuk::dariWebhook(($this->pesan)($payload)), false);

    expect($pilahan->jalur)->toBe(SaringPesan::CHAT)->and($pilahan->abaikan)->toBeFalse()
        ->and($pilahan->pesan->body())->toBe($teks)
        ->and($pilahan->pesan->payload['hasMedia'])->toBeFalse();
})->with([
    'a location' => [['_data' => ['type' => 'location'], 'location' => ['latitude' => -7.9, 'longitude' => 112.6, 'name' => 'Toko Bana']], '[Customer mengirim lokasi: Toko Bana | titik peta: https://maps.google.com/?q=-7.9,112.6]'],
    'a contact card' => [['_data' => ['type' => 'vcard'], 'vCards' => ["BEGIN:VCARD\nFN:Bu Sarah\nTEL;type=CELL:+62 812-9876-0646\nEND:VCARD"]], '[Customer mengirim kartu kontak: Bu Sarah +62 812-9876-0646]'],
    'a sticker' => [['_data' => ['type' => 'sticker'], 'hasMedia' => true], '[Customer mengirim stiker]'],
]);

test('staff are found by number however it is written, and by the number behind an @lid', function (string $dari, ?string $pn, bool $admin) {
    User::factory()->create(['nomor_wa' => '0857-1234-0036']);
    User::factory()->create(['nomor_wa' => '6289612000077', 'wa_dicabut_at' => now()]);
    User::factory()->create(['nomor_wa' => '6281111222233', 'nonaktif_at' => now()]);

    $pesan = PesanMasuk::dariWebhook(($this->pesan)(['from' => $dari]));

    expect(app(KenaliPengirim::class)->admin($pesan, $pn))->toBe($admin);
})->with([
    'a customer' => ['6281234567890@c.us', null, false],
    'the supervisor (ADMIN_WA)' => ['6281234560007@c.us', null, true],
    'staff written 0857-…' => ['6285712340036@c.us', null, true],
    'staff behind an @lid' => ['99887766@lid', '6285712340036@c.us', true],
    'an @lid nobody knows' => ['99887766@lid', null, false],
    'an @lid that is a customer' => ['99887766@lid', '6281234567890@c.us', false],
    'staff whose WhatsApp access was taken away' => ['6289612000077@c.us', null, false],
    'staff who left' => ['6281111222233@c.us', null, false],
]);

test('the number behind an @lid is asked from WAHA, and only for an @lid', function () {
    Queue::fake();
    User::factory()->create(['nomor_wa' => '6285712340036']);
    Http::fake(['waha.test/*' => Http::response(['lid' => '99887766@lid', 'pn' => '6285712340036@c.us'])]);

    ($this->alur)(($this->pesan)(['from' => '99887766@lid', 'body' => 'ACC ORD-1']));
    ($this->alur)(($this->pesan)(['body' => 'Halo']));

    // the admin was recognised from the number WAHA gave, and pointed to the panel
    expect($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0]['chat_id'])->toBe('99887766@lid')
        ->and($this->wa->terkirim[0]['teks'])->toBe('Perintah lewat WhatsApp sudah dipindah ke panel Adiva. ACC, jeda, catat order, dan jawaban ke customer sekarang dijalankan dari panel.');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/bangbana-waha/lids/99887766%40lid' && $r->hasHeader('X-Api-Key', 'kunci-waha'));
});

test('an admin who is not giving a command is left alone', function () {
    Queue::fake();
    User::factory()->create(['nomor_wa' => '6285712340036']);

    ($this->alur)(($this->pesan)(['from' => '6285712340036@c.us', 'body' => 'baik, saya cek dulu']));

    expect($this->wa->terkirim)->toBeEmpty()->and(Pesan::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- D1: the pause

test('a customer\'s chat is kept and handed on after the wait for the next message', function () {
    Queue::fake();
    $this->customer->update(['sesi_wa' => 'bangbana-waha']);
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama']);

    ($this->alur)(($this->pesan)());

    expect(Pesan::query()->sole()->only(['pengirim', 'jalur', 'teks', 'wa_message_id']))->toMatchArray(['jalur' => 'D', 'teks' => 'Halo kak, Molreng ada?', 'wa_message_id' => 'false_6281234567890@c.us_A1'])
        ->and(DB::table('buffer_pesan')->where('chat_id', '6281234567890@c.us')->count())->toBe(1)
        ->and($this->wa->terkirim)->toBeEmpty();
    // 8 seconds is the wait when the setting is empty
    Queue::assertPushed(GabungPesanMasuk::class, fn ($job) => $job->delay->equalTo(now()->addSeconds(8)));
});

test('a message WAHA delivers twice is kept and answered once', function () {
    Queue::fake();

    ($this->alur)(($this->pesan)());
    ($this->alur)(($this->pesan)());

    expect(Pesan::query()->count())->toBe(1)
        ->and(DB::table('buffer_pesan')->count())->toBe(1);
    Queue::assertPushed(GabungPesanMasuk::class, 1);
});

test('the database refuses a second line with the same WhatsApp id', function () {
    // lines without an id (Adiva, admin, system) are not affected
    Pesan::factory()->count(2)->create(['wa_message_id' => null]);
    Pesan::factory()->create(['wa_message_id' => 'false_6281234567890@c.us_A1']);

    expect(Pesan::query()->whereNull('wa_message_id')->count())->toBe(2)
        ->and(fn () => Pesan::factory()->create(['wa_message_id' => 'false_6281234567890@c.us_A1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the wait for the next message comes from the AI settings', function () {
    Queue::fake();
    Workflow::factory()->create(['kode' => 'chatbot', 'pengaturan' => ['tunggu_gabung_detik' => 12]]);

    ($this->alur)(($this->pesan)());

    Queue::assertPushed(GabungPesanMasuk::class, fn ($job) => $job->delay->equalTo(now()->addSeconds(12)));
});

test('a message WAHA reports twice is stored once', function () {
    Queue::fake();

    ($this->alur)(($this->pesan)());
    ($this->alur)(($this->pesan)());

    expect(Pesan::query()->count())->toBe(1);
});

test('messages the bot passes over are counted as ignored on Monitoring', function (array $payload) {
    Queue::fake();

    ($this->alur)(($this->pesan)($payload));

    expect(WorkflowEvent::query()->sole()->only(['judul', 'data']))->toBe(['judul' => 'Pesan diabaikan', 'data' => ['jalur' => 'DIABAIKAN', 'pesan' => 1]])
        ->and(Pesan::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'a group' => [['from' => '1203630@g.us']],
    'its own echo' => [['fromMe' => true]],
    'a reaction' => [['_data' => ['type' => 'reaction']]],
]);

test('while a CS holds the customer the bot stays quiet, tells the customer once and the supervisor each time', function () {
    Queue::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'nama' => 'Rina Maharani', 'sejak' => now()->subHour(), 'sampai' => now()->addHour(), 'oleh' => 'Falah', 'sudah_diberi_tahu' => false]);

    ($this->alur)(($this->pesan)());
    ($this->alur)(($this->pesan)(['id' => 'false_6281234567890@c.us_A2', 'body' => 'kak, kok belum dibalas?']));

    expect($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0]['teks'])->toBe("Halo kak 🙏\nPesan kakak sudah kami terima dan saat ini sedang dibantu langsung oleh tim kami.\n\nMohon ditunggu sebentar ya kak, nanti kami balas dari chat ini 😊")
        ->and(Pesan::query()->where('jalur', 'B')->count())->toBe(2)
        ->and(Pesan::query()->whereNull('jalur')->sole()->pengirim->value)->toBe('ADIVA')
        ->and(JedaBot::query()->sole()->sudah_diberi_tahu)->toBeTrue()
        ->and(Notifikasi::query()->where('jenis', 'JEDA_CHAT')->count())->toBe(1)
        ->and(Notifikasi::query()->where('jenis', 'JEDA_CHAT')->sole()->detail)->toBe('Rina Maharani · Halo kak, Molreng ada?')
        ->and(WorkflowEvent::query()->where('judul', 'Chat saat dijeda')->count())->toBe(2)
        ->and(DB::table('buffer_pesan')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a customer is told again if the first notice could not be sent', function () {
    Queue::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'sejak' => now()->subHour(), 'sampai' => null, 'sudah_diberi_tahu' => false]);
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    ($this->alur)(($this->pesan)());

    expect(JedaBot::query()->sole()->sudah_diberi_tahu)->toBeFalse()
        ->and(Notifikasi::query()->where('jenis', 'JEDA_CHAT')->count())->toBe(1);
});

test('a pause that has run out ends by Sistem, the supervisor is told and the bot answers', function () {
    Queue::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'nama' => 'Rina Maharani', 'sejak' => now()->subHours(3), 'sampai' => now()->subHour()]);

    ($this->alur)(($this->pesan)());

    $jeda = JedaBot::query()->sole();

    expect($jeda->selesai_at->format('H:i'))->toBe('12:42')
        ->and($jeda->diakhiri_oleh)->toBe('Sistem')
        ->and(Notifikasi::query()->where('jenis', 'JEDA_KEDALUWARSA')->sole()->detail)->toBe('Rina Maharani · supervisor dikabari')
        ->and(Pesan::query()->sole()->jalur)->toBe('D')
        ->and($this->wa->terkirim)->toBeEmpty();
    Queue::assertPushed(GabungPesanMasuk::class);
});

test('a pause ending at this very minute still holds', function () {
    Queue::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'sejak' => now()->subHour(), 'sampai' => now()->setSeconds(0), 'sudah_diberi_tahu' => true]);

    ($this->alur)(($this->pesan)());

    Queue::assertNothingPushed();
    expect(JedaBot::query()->sole()->selesai_at)->toBeNull();
});

test('a pause that was ended by LANJUT does not hold', function () {
    Queue::fake();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'sejak' => now()->subHour(), 'sampai' => null, 'selesai_at' => now()->subMinutes(5)]);

    ($this->alur)(($this->pesan)());

    Queue::assertPushed(GabungPesanMasuk::class);
});

// ---------------------------------------------------------------- D2: merging, then Adiva

test('several messages in a row become one, handed to Adiva by the last one\'s job', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sekarang = (int) (microtime(true) * 1000);
    foreach ([['A1', 'Halo kak', 4000], ['A2', 'Molreng ada?', 3000], ['A3', '  ', 2000], ['A4', 'yang 500 gr', 1000]] as [$id, $teks, $lalu]) {
        DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => "false_6281234567890@c.us_{$id}", 'teks' => $teks, 'waktu_ms' => $sekarang - $lalu]);
    }
    DB::table('buffer_pesan')->insert(['chat_id' => '6289999@c.us', 'message_id' => 'x', 'teks' => 'orang lain', 'waktu_ms' => $sekarang - 500]);
    $terakhir = ($this->pesan)(['id' => 'false_6281234567890@c.us_A4']);

    app()->call([new GabungPesanMasuk($terakhir, '6281234567890@c.us'), 'handle']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://n8n.test/webhook/panel'
        && $r['perintah'] === 'PESAN_MASUK'
        && $r['chat_id'] === '6281234567890@c.us' && $r['nomor_wa'] === '6281234567890' && $r['nama_wa'] === 'Rina Maharani'
        && $r['pesan'] === "Halo kak\nMolreng ada?\nyang 500 gr" && $r['jumlah_pesan'] === 4
        && $r['message_id'] === 'false_6281234567890@c.us_A4' && $r['session'] === 'bangbana-waha' && $r['waktu_pesan'] === '2026-09-28 13:42'
        && $r['pn'] === '6281234567890@c.us' && $r['sarah_wa'] === '6281298760646' && $r['label_nomor_bot'] === ''
        && Str::isUuid($r['request_id']));
    // the chat is ticked read and shown "typing…" from the bot's number
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/sendSeen' && $r['messageId'] === 'false_6281234567890@c.us_A4' && $r['session'] === 'bangbana-waha');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/startTyping' && $r['chatId'] === '6281234567890@c.us');
    // what was merged is gone; another customer's message stays
    expect(DB::table('buffer_pesan')->pluck('chat_id')->all())->toBe(['6289999@c.us']);
});

test('only the last message goes on: the job of an earlier one does nothing', function () {
    Http::fake();
    $sekarang = (int) (microtime(true) * 1000);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Halo', 'waktu_ms' => $sekarang - 3000]);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A2', 'teks' => 'Molreng ada?', 'waktu_ms' => $sekarang - 1000]);

    app()->call([new GabungPesanMasuk(($this->pesan)(['id' => 'false_6281234567890@c.us_A1'])), 'handle']);

    Http::assertNothingSent();
    expect(DB::table('buffer_pesan')->count())->toBe(2);
});

test('an empty buffer means another run has already taken the messages', function () {
    Http::fake();

    app()->call([new GabungPesanMasuk(($this->pesan)()), 'handle']);

    Http::assertNothingSent();
});

test('a message left in the buffer by a run that died is not merged in', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sekarang = (int) (microtime(true) * 1000);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'lama', 'teks' => 'pesan kemarin', 'waktu_ms' => $sekarang - 6 * 60 * 1000]);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Halo', 'waktu_ms' => $sekarang - 500]);

    app()->call([new GabungPesanMasuk(($this->pesan)()), 'handle']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://n8n.test/webhook/panel' && $r['pesan'] === 'Halo' && $r['jumlah_pesan'] === 1);
});

test('a message that arrives while the earlier ones are handed over keeps its place', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sekarang = (int) (microtime(true) * 1000);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Halo', 'waktu_ms' => $sekarang - 500]);
    $susulan = DB::table('buffer_pesan')->insertGetId(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A2', 'teks' => 'susulan', 'waktu_ms' => $sekarang + 5000]);

    // A2 is newer than A1, so A1 is not the last: nothing is handed over and nothing deleted
    app()->call([new GabungPesanMasuk(($this->pesan)()), 'handle']);

    expect(DB::table('buffer_pesan')->where('id', $susulan)->exists())->toBeTrue()->and(DB::table('buffer_pesan')->count())->toBe(2);
});

test('the same message handed over again is one request to n8n', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $kirimAn = function () {
        DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Halo', 'waktu_ms' => (int) (microtime(true) * 1000) - 500]);
        app()->call([new GabungPesanMasuk(($this->pesan)()), 'handle']);
    };

    $kirimAn();
    $kirimAn();

    $id = collect(Http::recorded())->map(fn ($par) => $par[0])->filter(fn (Request $r) => $r->url() === 'https://n8n.test/webhook/panel')->map(fn (Request $r) => $r['request_id'])->unique();

    expect($id)->toHaveCount(1);
});

test('when n8n does not answer the customer\'s message is not lost silently', function () {
    Http::fake(['n8n.test/*' => Http::response('Bad gateway', 502), 'waha.test/*' => Http::response([])]);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Molreng ada?', 'waktu_ms' => (int) (microtime(true) * 1000) - 500]);

    app()->call([new GabungPesanMasuk(($this->pesan)()), 'handle']);

    expect(Notifikasi::query()->where('jenis', 'BALASAN_GAGAL')->sole()->only(['judul', 'crm_customer_id']))->toBe(['judul' => 'Pesan customer belum sampai ke Adiva', 'crm_customer_id' => $this->customer->id]);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://waha.test/api/stopTyping');
});

test('the AI is told which of the bot\'s numbers the customer wrote to, when there are several', function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2']);
    Http::fake(['*' => Http::response(['ok' => true])]);
    DB::table('buffer_pesan')->insert(['chat_id' => '6281234567890@c.us', 'message_id' => 'false_6281234567890@c.us_A1', 'teks' => 'Halo', 'waktu_ms' => (int) (microtime(true) * 1000) - 500]);

    app()->call([new GabungPesanMasuk(($this->pesan)(sesi: 'bangbana-2')), 'handle']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://n8n.test/webhook/panel' && $r['label_nomor_bot'] === 'Nomor Lawang');
});

// ---------------------------------------------------------------- C: attachments

test('a transfer proof marks the order, tells the supervisor and thanks the customer', function () {
    $order = ($this->order)();
    $foto = ['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/files/bukti.jpg', 'mimetype' => 'image/jpeg']];

    ($this->alur)(($this->pesan)($foto));

    $order->refresh();

    expect($order->catatan_admin)->toBe('ACC 2026-09-28 10:00 | BUKTI MASUK 2026-09-28 13:42')
        ->and($order->bukti_masuk_at)->not->toBeNull()
        ->and($order->riwayat()->sole()->teks)->toBe('Bukti transfer masuk · ditandai BUKTI MASUK')
        ->and(Notifikasi::query()->where('jenis', 'BUKTI_MASUK')->sole()->only(['judul', 'detail', 'log_order_id']))->toBe(['judul' => 'Bukti transfer masuk', 'detail' => 'Rina Maharani · ORD-260928-014', 'log_order_id' => $order->id])
        ->and(Notifikasi::query()->where('jenis', 'LAMPIRAN_MASUK')->count())->toBe(0)
        ->and(Pesan::query()->where('jalur', 'C')->sole()->only(['teks', 'lampiran_url', 'lampiran_keterangan']))->toBe(['teks' => '[Lampiran]', 'lampiran_url' => 'https://waha.test/files/bukti.jpg', 'lampiran_keterangan' => 'Lampiran dari customer'])
        ->and($this->wa->terkirim)->toHaveCount(1)
        ->and($this->wa->terkirim[0]['teks'])->toBe('Bukti pembayarannya sudah kami terima ya Kak Rina, sedang kami cek dulu ya. Nanti kami kabari lagi di sini begitu pembayarannya dikonfirmasi supervisor kami.')
        ->and(WorkflowEvent::query()->sole()->data)->toBe(['jalur' => 'C', 'pesan' => 1]);
});

test('a second proof for the same order is told to the supervisor, not marked again', function () {
    ($this->order)(ubah: ['catatan_admin' => 'ACC 2026-09-28 10:00 | BUKTI MASUK 2026-09-28 11:00']);

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/f2.jpg', 'mimetype' => 'image/jpeg']]));

    $kabar = Notifikasi::query()->where('jenis', 'LAMPIRAN_MASUK')->sole();

    expect($kabar->detail)->toContain('BUKTI TRANSFER MASUK', 'Order: ORD-260928-014 (DIACC)', 'Total tagihan (yang harus ditransfer): Rp638.000', 'tidak akan dibatalkan otomatis')
        ->not->toContain('*')
        ->and(Notifikasi::query()->where('jenis', 'BUKTI_MASUK')->count())->toBe(0);
});

test('the supervisor is not told to type a WhatsApp command that no longer exists', function () {
    ($this->order)(StatusOrder::MenungguKonfirmasi, ['catatan_admin' => null]);

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']]));

    // a proof for an order not yet ACC'd is marked BUKTI MASUK; the supervisor sees it in the panel
    expect(Notifikasi::query()->where('jenis', 'BUKTI_MASUK')->count())->toBe(1);

    $pesan = LampiranMasuk::susun(PesanMasuk::dariWebhook(($this->pesan)(['hasMedia' => true, 'body' => 'ini buktinya', 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']])), LogOrder::query()->get());

    expect($pesan->pesanAdmin)->toContain('tekan *ACC* di panel → Pesanan')->not->toContain('Balas:')->not->toContain('BANTU');
});

test('a voice note is not a proof; the customer is asked to type', function () {
    $order = ($this->order)();

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', '_data' => ['type' => 'ptt', 'notifyName' => 'Rina Maharani'], 'media' => ['url' => 'https://waha.test/vn.ogg', 'mimetype' => 'audio/ogg; codecs=opus']]));

    expect($order->refresh()->bukti_masuk_at)->toBeNull()
        ->and(Notifikasi::query()->where('jenis', 'LAMPIRAN_MASUK')->sole()->detail)->toContain('Voice note dari customer', 'Order berjalan: ORD-260928-014 (DIACC)')
        ->and($this->wa->terkirim[0]['teks'])->toBe("Maaf kak, pesan suaranya belum bisa kami dengarkan lewat chat ini 🙏\nBoleh diketik saja pesannya ya kak, supaya bisa langsung kami bantu 😊");
});

test('an attachment from someone with no order waiting is passed on without guessing', function (string $caption, string $balasan) {
    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => $caption, 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']]));

    expect(Notifikasi::query()->where('jenis', 'LAMPIRAN_MASUK')->sole()->detail)->toContain('Lampiran masuk dari customer', 'belum tentu bukti transfer')
        ->and($this->wa->terkirim[0]['teks'])->toBe($balasan);
})->with([
    'with a caption' => ['ini fotonya kak', "Fotonya sudah kami terima kak 🙏\nKami teruskan ke tim kami dulu ya, nanti dibalas di chat ini."],
    'without' => ['', "Lampirannya sudah kami terima, terima kasih Kak 🙏\n\nKalau ini bukti transfer, supervisor kami akan mengeceknya. Kalau Kakak mau menanyakan produk atau harga, tulis saja pertanyaannya di sini ya 😊"],
]);

test('a proof that arrives after the deadline closed the order still finds it, for 3 days', function (StatusOrder $status, string $catatan, bool $ketemu) {
    $order = ($this->order)($status, ['catatan_admin' => $catatan, 'updated_at' => now()->subDays(10)]);

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']]));

    $kabar = Notifikasi::query()->where('jenis', 'LAMPIRAN_MASUK')->first();

    // it is told to the supervisor with the order, but never marked: Batas Bayar has closed it already
    expect($order->refresh()->bukti_masuk_at)->toBeNull();

    if ($ketemu) {
        expect($kabar->detail)->toContain('DIBATALKAN otomatis', 'ORD-260928-014');
    } else {
        expect($kabar->detail)->toContain('Tidak ada order yang menunggu pembayaran');
    }
})->with([
    'BATAL OTOMATIS 2 days ago' => [StatusOrder::BatalOtomatis, 'ACC 2026-09-26 10:00 | BATAL OTOMATIS 2026-09-26 16:00', true],
    'the older way of writing it' => [StatusOrder::Dibatalkan, 'ACC 2026-09-26 10:00 | BATAL OTOMATIS 2026-09-26 16:00', true],
    'BATAL OTOMATIS 5 days ago' => [StatusOrder::BatalOtomatis, 'ACC 2026-09-22 10:00 | BATAL OTOMATIS 2026-09-23 16:00', false],
    'cancelled by the customer' => [StatusOrder::Dibatalkan, 'ACC 2026-09-27 10:00 | DIBATALKAN (permintaan customer) 2026-09-27 12:00', false],
]);

test('with a CS holding the chat an attachment is recorded and told, but not answered', function () {
    ($this->order)();
    JedaBot::create(['nomor' => '6281234567890', 'chat_id' => '6281234567890@c.us', 'sejak' => now()->subHour(), 'sampai' => null]);

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']]));

    expect($this->wa->terkirim)->toBeEmpty()
        ->and(Notifikasi::query()->where('jenis', 'BUKTI_MASUK')->count())->toBe(1);
});

test('a reply the customer cannot be sent becomes a notice', function () {
    ($this->order)();
    PengirimRekam::pasang(tolak: 'WAHA tidak bisa dihubungi');

    ($this->alur)(($this->pesan)(['hasMedia' => true, 'body' => '', 'media' => ['url' => 'https://waha.test/f.jpg', 'mimetype' => 'image/jpeg']]));

    expect(Notifikasi::query()->where('jenis', 'BALASAN_GAGAL')->sole()->detail)->toContain('WAHA tidak bisa dihubungi');
});

// ---------------------------------------------------------------- the chat line

test('the first answer to a campaign is noticed, and STOP switches the customer off', function (string $balasan, bool $stop) {
    $penerima = BroadcastPenerima::create(['broadcast_id' => ($this->kampanye)(), 'chat_id' => '6281234567890@c.us', 'status' => 'TERKIRIM', 'terkirim_at' => now()->subDay()]);

    CatatPesanMasuk::dariCustomer('6281234567890@c.us', 'bangbana-waha', $balasan, 'false_6281234567890@c.us_B2', 'D');

    $penerima->refresh();

    expect($penerima->dibalas_at)->not->toBeNull()->and($penerima->balasan)->toBe($balasan)->and($penerima->minta_stop)->toBe($stop)
        ->and($this->customer->refresh()->status_optout)->toBe($stop);

    // only the first answer counts
    CatatPesanMasuk::dariCustomer('6281234567890@c.us', 'bangbana-waha', 'stop', 'false_6281234567890@c.us_B3', 'D');

    expect($penerima->refresh()->balasan)->toBe($balasan);
})->with([['Boleh minta daftar harganya kak?', false], ['STOP', true], ['  berhenti. ', true]]);

test('a campaign sent more than 3 days ago is not answered', function () {
    $penerima = BroadcastPenerima::create(['broadcast_id' => ($this->kampanye)(), 'chat_id' => '6281234567890@c.us', 'status' => 'TERKIRIM', 'terkirim_at' => now()->subDays(4)]);

    CatatPesanMasuk::dariCustomer('6281234567890@c.us', null, 'STOP', null, 'D');

    expect($penerima->refresh()->dibalas_at)->toBeNull()->and($this->customer->refresh()->status_optout)->toBeFalse();
});

test('a worked-through message that fails tells the supervisor', function () {
    $job = new ProsesPesanMasuk(($this->pesan)());

    $job->failed(new RuntimeException('database mati'));

    expect(Notifikasi::query()->sole()->only(['jenis', 'judul']))->toBe(['jenis' => 'WORKFLOW_ERROR', 'judul' => 'Pesan masuk gagal diproses'])
        ->and(Notifikasi::query()->sole()->detail)->toContain('6281234567890@c.us', 'Halo kak, Molreng ada?', 'database mati');
});

test('only the sessions listed as the panel bot numbers are answered', function () {
    // peralihan: nomor lama masih dipegang n8n, nomor baru dipegang panel
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-panel=Nomor Panel=panel']);

    $kirim = function (array $isi) {
        $badan = json_encode($isi);

        return $this->postJson('/api/webhook/waha', $isi, [
            'X-Webhook-Hmac' => hash_hmac('sha512', $badan, 'rahasia-waha'),
        ]);
    };

    Queue::fake();

    // nomor lama: diabaikan, supaya panel tidak ikut menjawab customer di sana
    $kirim(($this->pesan)([], 'bangbana-waha'))
        ->assertStatus(202)
        ->assertJsonPath('diabaikan', 'sesi bukan nomor bot panel');
    Queue::assertNothingPushed();

    // nomor panel: diproses seperti biasa
    $kirim(($this->pesan)([], 'bangbana-panel'))->assertOk();
    Queue::assertPushed(ProsesPesanMasuk::class);
});

test('without DAFTAR_NOMOR_BOT every session is still accepted', function () {
    Queue::fake();

    $isi = ($this->pesan)([], 'sesi-apa-saja');
    $this->postJson('/api/webhook/waha', $isi, [
        'X-Webhook-Hmac' => hash_hmac('sha512', json_encode($isi), 'rahasia-waha'),
    ])->assertOk();

    Queue::assertPushed(ProsesPesanMasuk::class);
});
