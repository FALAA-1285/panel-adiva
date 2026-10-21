<?php

use App\Bot\N8nTanyaAdiva;
use App\Bot\TanyaAdiva;
use App\Enums\StatusOrder;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach (['BATAS_BAYAR_JAM' => '5', 'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }

    $this->falah = User::factory()->falah()->create();
});

test('an order command tells what happened, once', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'metode_bayar' => 'TRANSFER']);

    $this->actingAs($this->falah)
        ->post('/pesanan/ORD-260928-014/aksi', ['aksi' => 'ACC'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', 'ACC ORD-260928-014 · invoice PDF dibuat & dikirim.');

    // the page after the redirect shows it, the next one does not
    $this->actingAs($this->falah)
        ->get('/pesanan/ORD-260928-014')
        ->assertInertia(fn (Assert $page) => $page->hasFlash('toast', 'ACC ORD-260928-014 · invoice PDF dibuat & dikirim.'));
    $this->actingAs($this->falah)
        ->get('/pesanan/ORD-260928-014')
        ->assertInertia(fn (Assert $page) => $page->missingFlash('toast'));
});

test('a refused command leaves no toast, only the error', function () {
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-DIACC']);

    $this->actingAs($this->falah)
        ->post('/pesanan/ORD-DIACC/aksi', ['aksi' => 'ACC'])
        ->assertSessionHasErrors('aksi')
        ->assertInertiaFlashMissing('toast');
});

test('pausing names the customer and until when', function () {
    CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'nomor_wa' => '6281355660042']);

    $this->actingAs($this->falah)
        ->post('/jeda', ['customer' => 'bu wati', 'durasi' => '1 jam'])
        ->assertInertiaFlash('toast', 'Adiva dijeda untuk Bu Wati sampai 14:42.');
});

test('Tandai semua dibaca says so', function () {
    Notifikasi::factory()->belumDibaca()->create();

    $this->actingAs($this->falah)
        ->post('/notifikasi/baca-semua')
        ->assertInertiaFlash('toast', 'Semua notifikasi ditandai dibaca.');
});

test('Tanya Adiva shows its answer in the panel, without a toast', function () {
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia']);
    $this->app->bind(TanyaAdiva::class, N8nTanyaAdiva::class);
    Http::fake(['n8n.test/*' => Http::response(['ok' => true, 'jawaban' => 'Halo kak.', 'tool' => []])]);

    $this->actingAs($this->falah)
        ->post('/katalog/uji', ['bagian' => 'KATALOG', 'pertanyaan' => 'halo'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', false);
});
