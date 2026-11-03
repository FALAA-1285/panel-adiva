<?php

use App\Bot\KirimKeStaf;
use App\Bot\SinkronChatN8n;
use App\Bot\TiruanPengirim;
use App\Bot\WahaPengirim;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\TahapFunnel;
use App\Models\CrmCustomer;
use App\Models\Pesan;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config([
        'services.n8n.api_url' => 'http://n8n.test',
        'services.n8n.api_key' => 'test-key',
        'services.n8n.sinkron_chat' => true,
    ]);
});

/**
 * n8n's rows API: RIWAYAT_CHAT honours the "id > n" filter, as the real one does. The rows are
 * read by reference, so a test can add a line before the next run.
 *
 * @param  list<array<string, mixed>>  $riwayat
 * @param  list<array<string, mixed>>  $crm
 */
function tabelChatPalsu(array &$riwayat, array $crm): void
{
    Http::fake(function (Request $r) use (&$riwayat, $crm) {
        if (str_contains($r->url(), SinkronChatN8n::TABEL_CRM)) {
            return Http::response(['data' => $crm, 'nextCursor' => null]);
        }

        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $dari = json_decode((string) ($q['filter'] ?? '{}'), true)['filters'][0]['value'] ?? 0;

        return Http::response(['data' => array_values(array_filter($riwayat, fn ($b) => $b['id'] > $dari)), 'nextCursor' => null]);
    });
}

test('the chatbot\'s chats and customers are copied in, each line once', function () {
    $riwayat = [
        ['id' => 1, 'chat_id' => '111@lid', 'peran' => 'customer', 'teks' => 'Kak ada basreng?', 'waktu_ms' => 1791852343143],
        ['id' => 2, 'chat_id' => '111@lid', 'peran' => 'adiva', 'teks' => 'Ada kak', 'waktu_ms' => 1791852350000],
        ['id' => 3, 'chat_id' => '222@lid', 'peran' => 'customer', 'teks' => 'Halo', 'waktu_ms' => 1791852360000],
    ];
    tabelChatPalsu($riwayat, [
        ['id' => 9, 'chat_id' => '111@lid', 'nama' => 'Rina', 'tipe_customer' => 'RESELLER', 'tahap_funnel' => 'REPEAT', 'butuh_admin' => 'YA', 'tgl_interaksi_terakhir' => '2026-10-13 07:45', 'createdAt' => '2026-10-13T00:00:00', 'updatedAt' => '2026-10-13T00:00:00'],
    ]);

    $this->artisan('n8n:sinkron-chat')->assertSuccessful();

    $rina = CrmCustomer::query()->where('chat_id', '111@lid')->firstOrFail();
    expect($rina->nama)->toBe('Rina')
        ->and($rina->tahap_funnel)->toBe(TahapFunnel::OrderDibuat)
        ->and($rina->butuh_admin)->toBeTrue()
        ->and($rina->pesan()->orderBy('waktu')->get()->map(fn (Pesan $p) => [$p->pengirim, $p->teks])->all())
        ->toBe([[PengirimPesan::Customer, 'Kak ada basreng?'], [PengirimPesan::Adiva, 'Ada kak']])
        ->and($rina->pesan()->first()->waktu->utc()->toIso8601String())->toBe('2026-10-13T00:45:43+00:00')
        // a chat with no CRM row yet still gets its customer
        ->and(CrmCustomer::query()->where('chat_id', '222@lid')->firstOrFail()->pesan()->count())->toBe(1);

    // the next run adds only what is new
    $riwayat[] = ['id' => 4, 'chat_id' => '222@lid', 'peran' => 'adiva', 'teks' => 'Halo kak', 'waktu_ms' => 1791852370000];
    $this->artisan('n8n:sinkron-chat')->assertSuccessful();

    expect(Pesan::query()->count())->toBe(4);
});

test('n8n is only read', function () {
    $kosong = [];
    tabelChatPalsu($kosong, []);

    $this->artisan('n8n:sinkron-chat')->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

test('the cron endpoint checks the key, the switch and runs at most every 30 seconds', function () {
    $kosong = [];
    tabelChatPalsu($kosong, []);
    RateLimiter::clear('cron-chat-sinkron');
    config(['services.accurate.cron_key' => 'rahasia']);

    $this->postJson('/cron/chat-sinkron')->assertUnauthorized();
    $this->postJson('/cron/chat-sinkron', [], ['X-Cron-Key' => 'rahasia'])->assertOk()->assertJson(['sukses' => true, 'pesan' => 0]);
    $this->postJson('/cron/chat-sinkron', [], ['X-Cron-Key' => 'rahasia'])->assertStatus(429);

    config(['services.n8n.sinkron_chat' => false]);
    $this->postJson('/cron/chat-sinkron', [], ['X-Cron-Key' => 'rahasia'])->assertStatus(422);
});

test('Percakapan only shows the chats while they come from n8n', function () {
    config(['app.demo' => false]);
    $falah = User::factory()->create(['peran' => Peran::Supervisor]);
    $c = CrmCustomer::factory()->create();

    $this->actingAs($falah)->get('/percakapan')->assertInertia(fn ($page) => $page->where('hanyaLihat', true));
    $this->actingAs($falah)->post("/percakapan/{$c->id}/balas", ['teks' => 'Halo'])->assertSessionHasErrors('teks');
    $this->actingAs($falah)->post("/percakapan/{$c->id}/reset-memori")->assertSessionHasErrors('teks');

    expect(Pesan::query()->count())->toBe(0)->and($c->fresh()->mem_gen)->toBe(1);

    config(['services.n8n.sinkron_chat' => false]);
    $this->actingAs($falah)->get('/percakapan')->assertInertia(fn ($page) => $page->where('hanyaLihat', false));
});

test('staff codes go out through WAHA when WAHA_KODE_STAF is on, the bot staying a stand-in', function () {
    $pengirim = fn () => (new ReflectionProperty(KirimKeStaf::class, 'pengirim'))->getValue(app(KirimKeStaf::class));

    config(['app.demo' => false, 'services.bot.driver' => 'tiruan', 'services.waha.kode_staf' => true]);
    expect($pengirim())->toBeInstanceOf(WahaPengirim::class);

    config(['services.waha.kode_staf' => false]);
    expect($pengirim())->toBeInstanceOf(TiruanPengirim::class);

    config(['app.demo' => true, 'services.waha.kode_staf' => true]);
    expect($pengirim())->toBeInstanceOf(TiruanPengirim::class);
});
