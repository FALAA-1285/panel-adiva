<?php

use App\Bot\KirimUlangInvoice;
use App\Bot\N8nKirimUlangInvoice;
use App\Enums\Peran;
use App\Enums\StatusOrder;
use App\Jobs\KabariKonfigN8n;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\LogOrder;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UserAgent;

beforeEach(function () {
    config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/panel', 'services.n8n.webhook_secret' => 'rahasia']);
});

test('Buka n8n opens the editor from N8N_EDITOR_URL, or stays inert without it', function () {
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->get('/otomasi')->assertInertia(fn (Assert $page) => $page->where('n8nUrl', null));

    config(['services.n8n.editor_url' => 'https://n8n.bangbana.id']);
    $this->actingAs($falah)->get('/otomasi')->assertInertia(fn (Assert $page) => $page->where('n8nUrl', 'https://n8n.bangbana.id'));
});

test('signing in to the panel gives the WhatsApp admin commands back', function () {
    // the new-device code has its own tests (PerangkatBaruTest)
    ConfigToko::create(['kunci' => 'PANEL_KODE_PERANGKAT_BARU', 'nilai' => '0']);
    Queue::fake();
    $dina = User::factory()->create(['wa_dicabut_at' => now()->subHour()]);

    $this->withHeader('User-Agent', UserAgent::WINDOWS)
        ->post('/masuk', ['email' => $dina->email, 'password' => 'password'])
        ->assertRedirect('/');

    expect($dina->refresh()->wa_dicabut_at)->toBeNull();
    Queue::assertPushed(KabariKonfigN8n::class, fn (KabariKonfigN8n $j) => $j->isi['dicabut'] === false);
});

test('Kirim ulang ke customer asks n8n to send the invoice again and is logged', function () {
    $this->app->bind(KirimUlangInvoice::class, N8nKirimUlangInvoice::class);
    Http::fake(['n8n.test/*' => Http::response(['ok' => true])]);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-260927-031', 'invoice_number' => 'INV/2026/09/138']);

    $this->actingAs(User::factory()->falah()->create())
        ->post('/pesanan/ORD-260927-031/kirim-ulang-invoice')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r['perintah'] === 'KIRIM_ULANG_INVOICE'
        && $r['order_id'] === 'ORD-260927-031'
        && $r['invoice_number'] === 'INV/2026/09/138'
        && $r['oleh']['nama'] === 'Falah');
    expect(ActivityLog::query()->sole()->only(['aksi', 'detail']))->toBe(['aksi' => 'Kirim ulang invoice', 'detail' => 'ORD-260927-031 · INV/2026/09/138']);
});

test('a resend n8n refuses, or an order without invoice, shows why', function () {
    $this->app->bind(KirimUlangInvoice::class, N8nKirimUlangInvoice::class);
    Http::fake(['n8n.test/*' => Http::response(['ok' => false, 'alasan' => 'PDF invoice belum dibuat'])]);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-1', 'invoice_number' => 'INV/2026/09/138']);
    LogOrder::factory()->create(['order_id' => 'ORD-2']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post('/pesanan/ORD-1/kirim-ulang-invoice')->assertSessionHasErrors(['invoice' => 'PDF invoice belum dibuat']);
    $this->actingAs($falah)->post('/pesanan/ORD-2/kirim-ulang-invoice')->assertSessionHasErrors(['invoice' => 'Order ini belum punya invoice.']);

    expect(ActivityLog::query()->count())->toBe(0);
});

test('only staff who may ACC can resend an invoice, and the stand-in sends nothing', function () {
    Http::fake();
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-1', 'invoice_number' => 'INV/2026/09/138']);

    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->post('/pesanan/ORD-1/kirim-ulang-invoice')->assertForbidden();
    $this->actingAs(User::factory()->falah()->create())->post('/pesanan/ORD-1/kirim-ulang-invoice')->assertSessionHasNoErrors();

    Http::assertNothingSent();
});

test('Unduh PDF links to the PDF n8n made, never for a draft', function () {
    $falah = User::factory()->falah()->create();
    LogOrder::factory()->status(StatusOrder::Diacc)->create([
        'order_id' => 'ORD-1', 'invoice_number' => 'INV/2026/09/138', 'invoice_pdf_url' => 'https://n8n.test/files/INV-2026-09-138.pdf',
    ]);
    LogOrder::factory()->create(['order_id' => 'ORD-2', 'invoice_pdf_url' => 'https://n8n.test/files/lama.pdf']);

    $this->actingAs($falah)->get('/pesanan/ORD-1')->assertInertia(fn (Assert $page) => $page->where('invoice.pdf', 'https://n8n.test/files/INV-2026-09-138.pdf'));
    $this->actingAs($falah)->get('/pesanan/ORD-2')->assertInertia(fn (Assert $page) => $page->where('invoice.pdf', null));
});
