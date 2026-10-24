<?php

use App\Enums\JenisNotifikasi;
use App\Models\ConfigToko;
use App\Models\Notifikasi;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2']);

    foreach ([
        ['chatbot', 'chatbot bangbana (main)', 'Setiap pesan masuk', 'AKTIF'],
        ['batas_bayar', 'Batas Bayar Order', 'Tiap jam, menit 15', 'AKTIF'],
        ['pantau_koneksi', 'Pantau Koneksi WhatsApp', 'Tiap 15 menit', 'AKTIF'],
        ['laporan_harian', 'Laporan Harian', 'Tiap hari 20.00', 'AKTIF'],
        ['notifikasi_error', 'Notifikasi Error', 'Saat workflow lain gagal', 'SIAGA'],
    ] as [$kode, $nama, $jadwal, $status]) {
        Workflow::create(['kode' => $kode, 'nama' => $nama, 'jadwal' => $jadwal, 'status' => $status]);
    }

    Workflow::query()->where('kode', 'chatbot')->update(['pengaturan' => ['model_utama' => 'gemini-3.6-flash', 'model_cadangan' => 'gemini-flash-latest', 'tunggu_gabung_detik' => 8]]);
});

/**
 * @param  array<string, mixed>  $isi
 */
function jalan(string $kode, string $waktu, string $status = 'BERHASIL', array $isi = []): WorkflowEvent
{
    return WorkflowEvent::create([
        'workflow_id' => Workflow::query()->where('kode', $kode)->value('id'),
        'waktu' => $waktu, 'jenis' => 'EKSEKUSI', 'status' => $status, 'judul' => 'Jalan', ...$isi,
    ]);
}

test('guests are sent to the login page', function () {
    $this->get('/monitoring')->assertRedirect('/masuk');
});

test('only supervisors see Monitoring', function () {
    $this->actingAs(User::factory()->create())->get('/monitoring')->assertForbidden();
    $this->actingAs(User::factory()->supervisor()->create())->get('/monitoring')->assertOk();
});

test('the figures count every workflow run and the chatbot replies', function () {
    jalan('chatbot', '2026-09-28 10:00', isi: ['durasi_ms' => 10000, 'data' => ['model' => 'gemini-3.6-flash', 'model_ms' => 1200]]);
    jalan('chatbot', '2026-09-28 10:05', isi: ['durasi_ms' => 12000, 'data' => ['model' => 'gemini-flash-latest', 'model_ms' => 1400, 'ongkir' => true]]);
    jalan('chatbot', '2026-09-28 10:10', isi: ['durasi_ms' => 14000, 'data' => ['model' => 'gemini-3.6-flash', 'model_ms' => 1800]]);
    jalan('chatbot', '2026-09-28 11:00', 'ERROR');
    jalan('batas_bayar', '2026-09-28 07:15', 'GAGAL');
    jalan('pantau_koneksi', '2026-09-28 13:30');
    // more than 24 hours ago: only in "7 hari"
    jalan('chatbot', '2026-09-26 10:00');
    WorkflowEvent::create(['waktu' => '2026-09-28 11:47', 'jenis' => 'INSIDEN', 'status' => 'PERLU_TINDAKAN', 'judul' => 'AI gagal total', 'data' => ['jenis' => 'AI_GAGAL_TOTAL']]);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get('/monitoring')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring')
            ->where('rentang', '24j')
            ->where('angka', [
                ['label' => 'Eksekusi workflow', 'nilai' => '6', 'meta' => '66,7% sukses · 2 error'],
                ['label' => 'Waktu balas (median)', 'nilai' => '12 dtk', 'meta' => 'termasuk tunggu gabung 8 dtk'],
                ['label' => 'Model cadangan dipakai', 'nilai' => '1×', 'meta' => '33,3% jawaban · 1 gagal total'],
                ['label' => 'Perlu tindakan', 'nilai' => '1', 'meta' => 'balasan belum terkirim ke customer'],
            ])
            // chatbot replies and every run with an error, by hour
            ->where('perJam.7', ['jam' => '07', 'sukses' => 0, 'error' => 1])
            ->where('perJam.10', ['jam' => '10', 'sukses' => 3, 'error' => 0])
            ->where('perJam.11', ['jam' => '11', 'sukses' => 0, 'error' => 1])
            ->where('perJam.13', ['jam' => '13', 'sukses' => 0, 'error' => 0])
            ->where('integrasi.utama', ['label' => 'Gemini 3.6 Flash (utama)', 'nilai' => 'p50 1,4 dtk'])
            ->where('integrasi.cadangan', ['label' => 'Gemini Flash Latest (cadangan)', 'nilai' => 'dipakai 1× hari ini', 'dipakai' => true])
            ->where('integrasi.ongkir', '1 cek hari ini')
            ->where('integrasi.workflow', '5 aktif · 1 error hari ini'));

    $this->actingAs($falah)
        ->get('/monitoring?rentang=7h')
        ->assertInertia(fn (Assert $page) => $page->where('angka.0.nilai', '7'));
});

test('incoming messages are counted per route', function () {
    // each chatbot run records the route and how many messages it took in
    jalan('chatbot', '2026-09-28 10:00', isi: ['data' => ['jalur' => 'D', 'pesan' => 2]]);
    jalan('chatbot', '2026-09-28 10:05', isi: ['data' => ['jalur' => 'D', 'pesan' => 1]]);
    jalan('chatbot', '2026-09-28 10:10', isi: ['data' => ['jalur' => 'A', 'pesan' => 1]]);
    jalan('chatbot', '2026-09-28 10:15', 'ERROR', ['data' => ['jalur' => 'DIABAIKAN', 'pesan' => 1]]);
    // more than 24 hours ago
    jalan('chatbot', '2026-09-26 10:00', isi: ['data' => ['jalur' => 'D', 'pesan' => 5]]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/monitoring')
        ->assertInertia(fn (Assert $page) => $page->where('jalur', [
            ['label' => 'D · Chat dijawab AI', 'n' => 3, 'diabaikan' => false],
            ['label' => 'A · Perintah admin', 'n' => 1, 'diabaikan' => false],
            ['label' => 'C · Lampiran / bukti', 'n' => 0, 'diabaikan' => false],
            ['label' => 'B · Ambil alih (jeda)', 'n' => 0, 'diabaikan' => false],
            ['label' => 'Diabaikan (sistem/grup)', 'n' => 1, 'diabaikan' => true],
        ]));
});

test('the safety net lists today\'s incidents, newest first', function () {
    WorkflowEvent::create(['waktu' => '2026-09-28 13:21', 'jenis' => 'INSIDEN', 'status' => 'PERLU_TINDAKAN', 'judul' => 'Balasan gagal terkirim · Nisa Aulia', 'detail' => 'WAHA timeout', 'aksi' => 'Kirim manual']);
    WorkflowEvent::create(['waktu' => '2026-09-28 12:02', 'jenis' => 'INSIDEN', 'status' => 'TERTANGANI', 'judul' => 'Model utama error (kuota)', 'aksi' => 'Lihat']);
    WorkflowEvent::create(['waktu' => '2026-09-27 12:02', 'jenis' => 'INSIDEN', 'status' => 'TERTANGANI', 'judul' => 'Kemarin']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/monitoring')
        ->assertInertia(fn (Assert $page) => $page
            ->has('insiden', 2)
            ->where('insiden.0', ['jam' => '13:21', 'judul' => 'Balasan gagal terkirim · Nisa Aulia', 'detail' => 'WAHA timeout', 'perluTindakan' => true, 'aksi' => 'Kirim manual'])
            ->where('insiden.1.aksi', null));
});

test('integrations show each number and an outage today', function () {
    $pantau = Workflow::query()->where('kode', 'pantau_koneksi')->value('id');
    WorkflowEvent::create(['workflow_id' => $pantau, 'waktu' => '2026-09-28 11:30', 'jenis' => 'KONEKSI', 'status' => 'PUTUS', 'judul' => 'Putus', 'data' => ['sesi' => 'bangbana-2', 'pesan_supervisor' => 'NOMOR BOT TERPUTUS']]);
    WorkflowEvent::create(['workflow_id' => $pantau, 'waktu' => '2026-09-28 11:52', 'jenis' => 'KONEKSI', 'status' => 'TERHUBUNG', 'judul' => 'Tersambung', 'data' => ['sesi' => 'bangbana-2']]);
    WorkflowEvent::create(['workflow_id' => $pantau, 'waktu' => '2026-09-28 13:30', 'jenis' => 'KONEKSI', 'status' => 'TERHUBUNG', 'judul' => 'Tersambung', 'data' => ['sesi' => 'bangbana-waha']]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/monitoring')
        ->assertInertia(fn (Assert $page) => $page
            ->where('integrasi.nomor', [
                ['label' => 'WAHA · Nomor Utama', 'sesi' => 'bangbana-waha', 'terhubung' => true, 'status' => 'Terhubung'],
                ['label' => 'WAHA · Nomor Lawang', 'sesi' => 'bangbana-2', 'terhubung' => true, 'status' => 'Terhubung'],
            ])
            ->where('integrasi.catatan', 'Dicek tiap 15 menit · terakhir 13:30. Nomor Lawang sempat putus 11:30–11:52, supervisor sudah dikabari.'));
});

test('the schedule shows each automated job', function () {
    jalan('pantau_koneksi', '2026-09-28 13:30');
    jalan('batas_bayar', '2026-09-28 13:15');
    jalan('notifikasi_error', '2026-09-28 07:15');
    WorkflowEvent::create(['workflow_id' => Workflow::query()->where('kode', 'laporan_harian')->value('id'), 'waktu' => '2026-09-27 20:00', 'jenis' => 'LAPORAN', 'status' => 'TERKIRIM', 'judul' => 'Laporan harian']);
    Notifikasi::create(['waktu' => '2026-09-28 09:00', 'jenis' => JenisNotifikasi::PengingatJeda->value, 'kategori' => JenisNotifikasi::PengingatJeda->kategori()->value, 'penerima' => 'ADMIN_WA', 'judul' => 'Pengingat daftar jeda']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/monitoring')
        ->assertInertia(fn (Assert $page) => $page->where('jadwal', [
            ['label' => 'Pantau koneksi WhatsApp', 'nilai' => 'tiap 15 menit · 13:30'],
            ['label' => 'Batas bayar & tutup order', 'nilai' => 'tiap jam :15 · 13:15'],
            // no Prospek Ulang workflow in this test
            ['label' => 'Sapaan prospek & tanya kesan', 'nilai' => 'belum dibuat di n8n'],
            ['label' => 'Pengingat daftar jeda', 'nilai' => '09.00 · terkirim'],
            ['label' => 'Laporan harian', 'nilai' => '20.00 · kemarin terkirim'],
            ['label' => 'Arsip data', 'nilai' => 'belum dibuat di n8n'],
            ['label' => 'Notifikasi error', 'nilai' => 'siaga · 1 kali hari ini'],
            ['label' => 'Pengirim broadcast', 'nilai' => 'belum dibuat di n8n'],
        ]));
});

test('a handled chatbot error is not a workflow error on Otomasi', function () {
    jalan('chatbot', '2026-09-28 11:00', 'ERROR');
    jalan('batas_bayar', '2026-09-28 07:15', 'GAGAL');

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page->where('ringkasan.0.meta', 'Notifikasi Error siaga · 1 error hari ini'));
});
