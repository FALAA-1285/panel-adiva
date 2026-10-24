<?php

use App\Bot\KabarKonfig;
use App\Bot\N8nKabarKonfig;
use App\Jobs\KabariKonfigN8n;
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
