<?php

use App\Bot\N8nTanyaAdiva;
use App\Bot\TanyaAdiva;
use App\Enums\TahapFunnel;
use App\Models\Kebijakan;
use App\Models\Produk;
use App\Models\PromptVersi;
use App\Models\UjiAdiva;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia']);
    $this->app->bind(TanyaAdiva::class, N8nTanyaAdiva::class);
    $this->falah = User::factory()->falah()->create();
});

test('Tanya Adiva on Katalog is answered by n8n in test mode and saved with the tools she used', function () {
    $kripca = Produk::factory()->create(['jenis_produk' => 'Kripca 1,8 kg']);
    Http::fake(['n8n.test/*' => Http::response([
        'ok' => true,
        'jawaban' => 'Untuk Kripca 1,8 kg pembelian 30 bal harganya Rp41.000 per bal ya kak.',
        'tool' => ['Cari_Produk_per_Kategori (Keripik kaca)'],
        'penilaian' => ['tipe' => 'RESELLER', 'tahap' => 'TANYA_HARGA', 'skor' => 'WARM'],
        'produk_id' => $kripca->id,
    ])]);

    $this->actingAs($this->falah)
        ->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'kripca 1,8 kg kalau ambil 30 harganya brp?'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'UJI_ADIVA'
        && $r['bagian'] === 'KATALOG'
        && $r['pertanyaan'] === 'kripca 1,8 kg kalau ambil 30 harganya brp?'
        && $r['prompt'] === null
        && $r['pengaturan_ai'] !== null
        && $r->hasHeader('X-Adiva-Signature'));

    expect(UjiAdiva::query()->sole()->only(['bagian', 'jawaban', 'catatan', 'produk_id', 'user_id']))->toBe([
        'bagian' => 'KATALOG',
        'jawaban' => 'Untuk Kripca 1,8 kg pembelian 30 bal harganya Rp41.000 per bal ya kak.',
        'catatan' => 'Tool dipakai: Cari_Produk_per_Kategori (Keripik kaca) · dinilai Reseller · Tanya harga · WARM',
        'produk_id' => $kripca->id,
        'user_id' => $this->falah->id,
    ]);
});

test('Tanya Adiva on Kebijakan asks about the policy open in the editor', function () {
    $ongkir = Kebijakan::factory()->ongkir()->create();
    Http::fake(['n8n.test/*' => Http::response([
        'ok' => true, 'jawaban' => 'Adiva cek dulu ongkirnya ya kak.',
        'tool' => ['KB_Kebijakan (Ongkir & ekspedisi)', 'Cek_Ongkir_Mengantar'],
    ])]);

    $this->actingAs($this->falah)->post('/katalog/uji', ['bagian' => 'KEBIJAKAN', 'pertanyaan' => 'ongkir ke blitar brp kak?', 'kebijakan_id' => $ongkir->id]);

    Http::assertSent(fn (Request $r) => $r['kebijakan_id'] === $ongkir->id);
    expect(UjiAdiva::query()->sole()->only(['kebijakan_id', 'catatan']))->toBe([
        'kebijakan_id' => $ongkir->id,
        'catatan' => 'Tool dipakai: KB_Kebijakan (Ongkir & ekspedisi) lalu Cek_Ongkir_Mengantar',
    ]);
});

test('Uji draf sends the draft prompt and keeps what Adiva decided', function () {
    PromptVersi::factory()->aktif()->create(['versi' => 14]);
    $draf = PromptVersi::factory()->draf()->create(['versi' => 15, 'bagian' => [
        ['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva.'],
        ['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'Baca semua bagian.'],
    ]]);
    Http::fake(['n8n.test/*' => Http::response([
        'ok' => true, 'jawaban' => 'Siap kak, Adiva teruskan ke admin dulu ya.',
        'hasil' => ['butuh_admin' => true, 'tahap' => 'NEGO', 'alasan' => 'kerja sama distributor besar'],
    ])]);

    $this->actingAs($this->falah)->post('/prompt/uji', ['pertanyaan' => 'bisa harga khusus 300 pcs?'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r['bagian'] === 'PROMPT'
        && $r['prompt'] === ['versi' => 15, 'teks' => "## 0. Baca dulu\nBaca semua bagian.\n\n## 1. Identitas\nKamu Adiva."]);
    $uji = UjiAdiva::query()->sole();
    expect($uji->prompt_versi_id)->toBe($draf->id)
        ->and($uji->hasil)->toEqual(['butuh_admin' => true, 'tahap' => 'NEGO', 'alasan' => 'kerja sama distributor besar']);

    $this->actingAs($this->falah)->get('/prompt')
        ->assertInertia(fn ($page) => $page->where('uji.jawaban', 'Siap kak, Adiva teruskan ke admin dulu ya.')
            ->where('uji.butuhAdmin', true)
            ->where('uji.tahap', TahapFunnel::Nego->label()));
});

test('n8n refusing or not answering is shown under the question', function () {
    Http::fake(['n8n.test/*' => Http::sequence()
        ->push(['ok' => false, 'alasan' => 'Model sedang sibuk, coba lagi.'])
        ->push('Bad gateway', 502)]);

    $this->actingAs($this->falah)->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'halo'])
        ->assertSessionHasErrors(['pertanyaan' => 'Model sedang sibuk, coba lagi.']);
    $this->actingAs($this->falah)->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'halo'])
        ->assertSessionHasErrors(['pertanyaan' => 'n8n tidak menjawab, perintah belum dijalankan. Coba lagi.']);

    expect(UjiAdiva::query()->count())->toBe(0);
});

test('a draft test needs a draft, and a question', function () {
    Http::fake();

    $this->actingAs($this->falah)->post('/prompt/uji', ['pertanyaan' => 'halo'])
        ->assertSessionHasErrors(['pertanyaan' => 'Belum ada draf untuk diuji.']);
    $this->actingAs($this->falah)->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => ''])
        ->assertSessionHasErrors(['pertanyaan' => 'Tulis pertanyaan customer dulu.']);

    Http::assertNothingSent();
});

test('only supervisors may test Adiva', function () {
    $this->actingAs(User::factory()->create())->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'halo'])->assertForbidden();
    $this->actingAs(User::factory()->create())->post('/prompt/uji', ['pertanyaan' => 'halo'])->assertForbidden();
});

test('the stand-in says nobody really answered', function () {
    $this->app->forgetInstance(TanyaAdiva::class);
    $this->app->offsetUnset(TanyaAdiva::class);
    (new AppServiceProvider($this->app))->register();
    Http::fake();

    $this->actingAs($this->falah)->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'halo']);

    expect(UjiAdiva::query()->sole()->jawaban)->toBe('(Mode tiruan) Adiva belum tersambung ke n8n, pertanyaan ini tidak dijawab sungguhan.')
        ->and(UjiAdiva::query()->sole()->catatan)->toBeNull();
    Http::assertNothingSent();
});
