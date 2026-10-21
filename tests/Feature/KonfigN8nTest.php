<?php

use App\Bot\CekOngkir;
use App\Bot\KabarKonfig;
use App\Bot\N8nCekOngkir;
use App\Bot\N8nKabarKonfig;
use App\Jobs\KabariKonfigN8n;
use App\Models\ConfigToko;
use App\Models\PromptVersi;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsianPengaturan;

beforeEach(function () {
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia']);
    $this->falah = User::factory()->falah()->create();
});

test('a prompt version keeps its whole text for n8n', function () {
    $versi = PromptVersi::factory()->create(['bagian' => [
        ['no' => 2, 'judul' => 'Gaya bicara', 'teks' => "Pakai kak.\n"],
        ['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva.'],
    ]]);

    expect($versi->refresh()->teks_lengkap)->toBe("## 1. Identitas\nKamu Adiva.\n\n## 2. Gaya bicara\nPakai kak.");

    $versi->update(['bagian' => [['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva dari Bang Bana.']]]);
    expect($versi->refresh()->teks_lengkap)->toBe("## 1. Identitas\nKamu Adiva dari Bang Bana.");

    // the demo seeder runs without model events
    $tanpaEvent = PromptVersi::withoutEvents(fn () => PromptVersi::factory()->create());
    expect($tanpaEvent->refresh()->teks_lengkap)->toBe("## 0. Baca dulu\nBaca semua bagian.\n\n## 1. Identitas\nKamu Adiva.");
});

test('publishing a prompt tells n8n which version is now active', function () {
    Queue::fake();
    PromptVersi::factory()->aktif()->create(['versi' => 14]);
    PromptVersi::factory()->draf()->create(['versi' => 15]);

    $this->actingAs($this->falah)->post('/prompt/terbitkan')->assertRedirect();

    Queue::assertPushed(KabariKonfigN8n::class, fn (KabariKonfigN8n $j) => $j->apa === 'PROMPT' && $j->isi === ['versi' => 15]);
});

test('changing the AI settings tells n8n, saving them unchanged does not', function () {
    Queue::fake();
    IsianPengaturan::simpanAwal();

    $this->actingAs($this->falah)->put('/pengaturan', IsianPengaturan::form())->assertSessionHasNoErrors();
    Queue::assertNothingPushed();

    $this->actingAs($this->falah)->put('/pengaturan', IsianPengaturan::form(['ai' => ['AI_TEMPERATURE' => '0.7']]))->assertSessionHasNoErrors();
    Queue::assertPushed(KabariKonfigN8n::class, fn (KabariKonfigN8n $j) => $j->apa === 'PENGATURAN_AI' && $j->isi['pengaturan']['temperature'] === 0.7);
});

test('the notice goes to n8n signed as KONFIG_BERUBAH', function () {
    $this->app->bind(KabarKonfig::class, N8nKabarKonfig::class);
    Http::fake(['n8n.test/*' => Http::response(['ok' => true])]);

    (new KabariKonfigN8n('PROMPT', ['versi' => 15]))->handle(app(KabarKonfig::class));

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'KONFIG_BERUBAH' && $r['apa'] === 'PROMPT' && $r['versi'] === 15 && $r->hasHeader('X-Adiva-Signature'));
});

test('Uji cek ongkir asks n8n from the warehouse and puts the rates on one line', function () {
    $this->app->bind(CekOngkir::class, N8nCekOngkir::class);
    ConfigToko::create(['kunci' => 'MENGANTAR_KOTA_ASAL', 'nilai' => 'SAWOJAJAR, KEDUNGKANDANG, MALANG, JAWA TIMUR']);
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'tarif' => [
        ['kurir' => 'JNE', 'layanan' => 'REG', 'harga' => 38000, 'estimasi' => '2–3 hari'],
        ['kurir' => 'SiCepat', 'layanan' => 'REG', 'harga' => 35000],
    ]])]);

    $this->actingAs($this->falah)->postJson('/pengaturan/uji-ongkir')
        ->assertOk()
        ->assertExactJson(['hasil' => 'Ke Dinoyo, Malang · 1 kg: JNE REG Rp38.000 (2–3 hari) · SiCepat REG Rp35.000']);

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'CEK_ONGKIR'
        && $r['asal'] === 'SAWOJAJAR, KEDUNGKANDANG, MALANG, JAWA TIMUR'
        && $r['tujuan'] === 'DINOYO, LOWOKWARU, MALANG, JAWA TIMUR'
        && $r['berat_kg'] === 1);
});

test('Uji cek ongkir says why it failed', function () {
    $this->app->bind(CekOngkir::class, N8nCekOngkir::class);
    Http::fake(['n8n.test/*' => Http::sequence()
        ->push(['ok' => false, 'alasan' => 'API key Mengantar ditolak'])
        ->push(['ok' => true, 'tarif' => []])]);

    $this->actingAs($this->falah)->postJson('/pengaturan/uji-ongkir')->assertJsonValidationErrors(['ongkir' => 'API key Mengantar ditolak']);
    $this->actingAs($this->falah)->postJson('/pengaturan/uji-ongkir')->assertJsonValidationErrors(['ongkir' => 'Mengantar tidak memberi tarif untuk tujuan uji.']);
});

test('the stand-in rates are the ones Adiva quotes in the design, and only supervisors may check', function () {
    Http::fake();

    $this->actingAs($this->falah)->postJson('/pengaturan/uji-ongkir')
        ->assertExactJson(['hasil' => 'Ke Dinoyo, Malang · 1 kg: JNE REG Rp38.000 (2–3 hari) · SiCepat REG Rp35.000 · GoSend Instant Rp27.000']);
    $this->actingAs(User::factory()->create())->postJson('/pengaturan/uji-ongkir')->assertForbidden();
    Http::assertNothingSent();
});
