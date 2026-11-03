<?php

use App\Bot\GerbangWahaPengirim;
use App\Bot\KirimKeStaf;
use App\Bot\SinkronChatN8n;
use App\Bot\WahaPengirim;
use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use App\Models\Pesan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.n8n.api_url' => 'http://n8n.test',
        'services.n8n.api_key' => 'test-key',
        'services.waha.gerbang_url' => 'http://n8n.test/webhook/panel-waha',
        'services.waha.gerbang_secret' => 'rahasia-uji',
    ]);
    Cache::forget('sinkron-chat:crm-versi');
    Cache::forget('sinkron-chat:waha-sejak');
    // the messages below are from 2 Nov 2026; demo mode freezes the clock in September
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Jakarta'));
});

/**
 * n8n: CRM_CUSTOMER through the API, the chats' WhatsApp messages through the gateway (only
 * those after "sejak", as WAHA's filter does). Messages are read by reference.
 *
 * @param  array<string, list<array<string, mixed>>>  $wa
 */
function waPalsu(array &$wa): void
{
    Http::fake(function (Request $r) use (&$wa) {
        if (str_contains($r->url(), SinkronChatN8n::TABEL_CRM)) {
            return Http::response(['data' => [
                ['id' => 1, 'chat_id' => '111@lid', 'nama' => 'Rina', 'updatedAt' => '2026-11-03T00:00:00'],
            ], 'nextCursor' => null]);
        }

        if (str_contains($r->url(), 'panel-waha')) {
            $hasil = [];
            foreach ($r->data()['chats'] as $c) {
                $hasil[$c['chatId']] = array_values(array_filter($wa[$c['chatId']] ?? [], fn ($p) => $p['timestamp'] >= $c['sejak']));
            }

            return Http::response(['ok' => true, 'pesan' => $hasil]);
        }

        return Http::response(['data' => [], 'nextCursor' => null]);
    });
}

test('the chats are WhatsApp\'s own messages, each once, replacing the bot\'s memory lines', function () {
    $wa = ['111@lid' => [
        ['id' => 'false_111@lid_A', 'fromMe' => false, 'timestamp' => 1793600000, 'body' => 'Kak ada basreng?'],
        ['id' => 'false_111@lid_B', 'fromMe' => false, 'timestamp' => 1793600005, 'body' => '', 'hasMedia' => true, 'type' => 'image'],
        ['id' => 'true_111@lid_C', 'fromMe' => true, 'source' => 'api', 'timestamp' => 1793600030, 'body' => 'Ada kak'],
        ['id' => 'true_111@lid_D', 'fromMe' => true, 'source' => 'app', 'timestamp' => 1793600090, 'body' => 'Saya bantu ya kak'],
    ]];
    waPalsu($wa);

    $this->artisan('n8n:sinkron-chat')->assertSuccessful();
    // a line copied from RIWAYAT_CHAT earlier
    $rina = CrmCustomer::query()->where('chat_id', '111@lid')->sole();
    Pesan::create(['crm_customer_id' => $rina->id, 'chat_id' => '111@lid', 'waktu' => now(), 'pengirim' => PengirimPesan::Adiva, 'teks' => 'gabungan', 'meta' => ['riwayat_id' => 5]]);
    Cache::forget('sinkron-chat:waha-sejak');

    $this->artisan('n8n:sinkron-chat')->assertSuccessful();

    expect($rina->pesan()->orderBy('waktu')->get()->map(fn (Pesan $p) => [$p->pengirim, $p->teks, $p->lampiran_keterangan])->all())->toBe([
        [PengirimPesan::Customer, 'Kak ada basreng?', null],
        [PengirimPesan::Customer, null, 'Foto'],
        [PengirimPesan::Adiva, 'Ada kak', null],
        [PengirimPesan::Admin, 'Saya bantu ya kak', null],
    ]);

    // a new message arrives: only that one is added
    $wa['111@lid'][] = ['id' => 'false_111@lid_E', 'fromMe' => false, 'timestamp' => 1793600200, 'body' => 'Oke'];
    $this->artisan('n8n:sinkron-chat')->expectsOutputToContain('1 pesan baru')->assertSuccessful();

    expect($rina->pesan()->count())->toBe(5);
});

test('the gateway request is signed and staff codes go through it', function () {
    Http::fake(['n8n.test/webhook/panel-waha' => Http::response(['ok' => true, 'id' => 'true_628@c.us_X'])]);
    config(['app.demo' => false, 'services.waha.kode_staf' => true]);

    $kirim = app(KirimKeStaf::class);
    expect((new ReflectionProperty(KirimKeStaf::class, 'pengirim'))->getValue($kirim))->toBeInstanceOf(GerbangWahaPengirim::class);

    $kirim->kirim('089652609705', 'Kode masuk panel Adiva: 123456', 'bangbana-waha');

    Http::assertSent(function (Request $r) {
        $ts = $r->header('X-Adiva-Timestamp')[0];

        return $r->data()['perintah'] === 'KIRIM_TEKS'
            && $r->data()['chatId'] === '6289652609705@c.us'
            && $r->header('X-Adiva-Signature')[0] === 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), 'rahasia-uji');
    });

    // without the gateway the panel talks to WAHA itself
    config(['services.waha.gerbang_url' => null]);
    expect((new ReflectionProperty(KirimKeStaf::class, 'pengirim'))->getValue(app(KirimKeStaf::class)))->toBeInstanceOf(WahaPengirim::class);
});
