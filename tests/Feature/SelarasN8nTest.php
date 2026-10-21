<?php

use App\Enums\StatusOrder;
use App\Enums\StatusPrompt;
use App\Models\InvoicePdf;
use App\Models\LogOrder;
use App\Models\PromptVersi;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.n8n.api_url' => 'http://n8n.test', 'services.n8n.api_key' => 'kunci']);
});

test('n8n:sinkron links the workflows by name and files their finished runs once', function () {
    $chatbot = Workflow::factory()->create(['kode' => 'chatbot', 'nama' => 'chatbot bangbana (main)']);
    $batas = Workflow::factory()->create(['kode' => 'batas_bayar', 'nama' => 'Batas Bayar Order']);
    $error = Workflow::factory()->create(['kode' => 'notifikasi_error', 'nama' => 'Notifikasi Error', 'status' => 'NONAKTIF']);
    // the chatbot wrote this run itself, with its route
    WorkflowEvent::factory()->create(['workflow_id' => $chatbot->id, 'jenis' => 'EKSEKUSI', 'status' => 'BERHASIL', 'judul' => 'Pesan dijawab',
        'n8n_execution_id' => '11', 'durasi_ms' => null, 'data' => ['jalur' => 'D', 'pesan' => 1]]);

    Http::fake([
        'n8n.test/api/v1/workflows*' => Http::response(['data' => [
            ['id' => 'LCje', 'name' => 'chatbot bangbana (main)', 'active' => true, 'nodes' => [['type' => 'n8n-nodes-base.code'], ['type' => 'n8n-nodes-base.stickyNote']]],
            ['id' => 'ZVXm', 'name' => 'Bang Bana - Batas Bayar Order', 'active' => false, 'nodes' => [['type' => 'n8n-nodes-base.code']]],
            ['id' => '2w2e', 'name' => 'Bang Bana - Notifikasi Error', 'active' => true, 'nodes' => []],
        ]]),
        'n8n.test/api/v1/executions?workflowId=LCje*' => Http::response(['data' => [
            ['id' => 11, 'status' => 'success', 'startedAt' => '2026-10-08T06:00:00.000Z', 'stoppedAt' => '2026-10-08T06:00:09.500Z'],
            // a message folded into a later run: no row
            ['id' => 12, 'status' => 'success', 'startedAt' => '2026-10-08T06:00:01.000Z', 'stoppedAt' => '2026-10-08T06:00:01.200Z'],
            ['id' => 13, 'status' => 'error', 'startedAt' => '2026-10-08T06:05:00.000Z', 'stoppedAt' => '2026-10-08T06:05:02.000Z'],
            ['id' => 14, 'status' => 'running', 'startedAt' => '2026-10-08T06:06:00.000Z', 'stoppedAt' => null],
        ]]),
        'n8n.test/api/v1/executions?workflowId=ZVXm*' => Http::response(['data' => [
            ['id' => 21, 'status' => 'success', 'startedAt' => '2026-10-08T06:15:00.000Z', 'stoppedAt' => '2026-10-08T06:15:03.000Z'],
        ]]),
        'n8n.test/api/v1/executions*' => Http::response(['data' => []]),
    ]);

    $this->artisan('n8n:sinkron')->expectsOutputToContain('2 eksekusi baru')->assertSuccessful();
    $this->artisan('n8n:sinkron')->expectsOutputToContain('0 eksekusi baru')->assertSuccessful();

    expect($chatbot->refresh()->only(['n8n_workflow_id', 'jumlah_node', 'status']))->toBe(['n8n_workflow_id' => 'LCje', 'jumlah_node' => 1, 'status' => 'AKTIF'])
        ->and($batas->refresh()->status)->toBe('NONAKTIF')
        ->and($error->refresh()->status)->toBe('SIAGA');

    $run = fn (string $id) => WorkflowEvent::query()->where('n8n_execution_id', $id)->first();
    expect($run('11')?->durasi_ms)->toBe(9500)
        ->and($run('11')?->data)->toBe(['jalur' => 'D', 'pesan' => 1])
        ->and($run('12'))->toBeNull()
        ->and($run('13')?->only(['status', 'judul']))->toBe(['status' => 'GAGAL', 'judul' => 'Eksekusi gagal'])
        ->and($run('14'))->toBeNull()
        ->and($run('21')?->only(['status', 'judul', 'workflow_id']))->toBe(['status' => 'BERHASIL', 'judul' => 'Cek batas bayar', 'workflow_id' => $batas->id]);
});

test('n8n:sinkron says what is missing without the API settings', function () {
    config(['services.n8n.api_key' => null]);

    $this->artisan('n8n:sinkron')->expectsOutput('N8N_API_URL dan N8N_API_KEY belum diisi')->assertFailed();
});

test('prompt:impor-n8n turns the node prompt into the active version, one section per block', function () {
    PromptVersi::factory()->create(['versi' => 14, 'status' => StatusPrompt::Aktif]);
    $pemisah = str_repeat('═', 32);
    $berkas = tempnam(sys_get_temp_dir(), 'wf');
    file_put_contents($berkas, json_encode(['nodes' => [[
        'name' => 'Agen AI Penjawab',
        'parameters' => ['options' => ['systemMessage' => "SYSTEM PROMPT — CUSTOMER SERVICE BANG BANA\n\n{$pemisah}\n1. IDENTITAS\n{$pemisah}\nKamu Adiva.\n\nALOKASI BUDGET\n• tanya dulu\n\n{$pemisah}\n2. GAYA BICARA — WAJIB\n{$pemisah}\nSantai.\n"]],
    ]]]));

    $this->artisan('prompt:impor-n8n', ['berkas' => $berkas])->expectsOutput('Prompt v15 aktif · 2 bagian')->assertSuccessful();

    $aktif = PromptVersi::query()->where('status', StatusPrompt::Aktif)->sole();
    expect($aktif->versi)->toBe(15)
        ->and($aktif->bagian)->toEqual([
            ['no' => 1, 'judul' => 'IDENTITAS', 'teks' => "Kamu Adiva.\n\nALOKASI BUDGET\n• tanya dulu"],
            ['no' => 2, 'judul' => 'GAYA BICARA — WAJIB', 'teks' => 'Santai.'],
        ])
        ->and($aktif->teks_lengkap)->toStartWith("## 1. IDENTITAS\nKamu Adiva.")
        ->and(PromptVersi::query()->where('versi', 14)->value('status'))->toBe(StatusPrompt::Arsip);

    // once the node reads the prompt from the panel there is nothing left to import
    file_put_contents($berkas, json_encode(['nodes' => [['name' => 'Agen AI Penjawab', 'parameters' => ['options' => ['systemMessage' => '={{ $json.prompt }}']]]]]));
    $this->artisan('prompt:impor-n8n', ['berkas' => $berkas])->assertFailed();
});

test('an order closed with TUTUP shows as Ditutup under Batal / tolak', function () {
    LogOrder::factory()->status(StatusOrder::Ditutup)->create(['order_id' => 'ORD-TUTUP']);
    LogOrder::factory()->create(['order_id' => 'ORD-JALAN']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pesanan?tab=batal')
        ->assertInertia(fn (Assert $page) => $page
            ->where('baris', fn ($baris) => collect($baris)->pluck('id')->all() === ['ORD-TUTUP'])
            ->where('baris.0.status', ['label' => 'Ditutup', 'nada' => 'gray']));
});

test('Unduh PDF serves the invoice n8n kept, and only to staff', function () {
    $order = LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-1', 'invoice_number' => 'INV/2026/10/001']);
    InvoicePdf::query()->create(['log_order_id' => $order->id, 'nama_file' => 'INV-2026-10-001.pdf', 'isi' => '%PDF-1.4 uji']);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-2', 'invoice_number' => 'INV/2026/10/002']);
    $falah = User::factory()->falah()->create();

    $this->get('/pesanan/ORD-1/invoice.pdf')->assertRedirect('/masuk');
    $this->actingAs($falah)->get('/pesanan/ORD-1/invoice.pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="INV-2026-10-001.pdf"');
    $this->actingAs($falah)->get('/pesanan/ORD-2/invoice.pdf')->assertNotFound();

    $this->actingAs($falah)->get('/pesanan/ORD-1')->assertInertia(fn (Assert $page) => $page->where('invoice.pdf', route('pesanan.invoice-pdf', 'ORD-1')));
});
