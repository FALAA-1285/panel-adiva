<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\StatusWorkflow;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\Pesan;
use App\Models\TemplateFollowup;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach ([
        'BATAS_BAYAR_JAM' => '5', 'BATAS_KONFIRMASI_JAM' => '24', 'BATAS_BAYAR_PICKUP_JAM' => '24', 'JEDA_FOLLOWUP_JAM' => '10',
        'JEDA_KESAN_HARI' => '2', 'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu',
        'ARSIP_ORDER_HARI' => '90', 'ARSIP_CUSTOMER_HARI' => '365',
    ] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
});

test('guests are sent to the login page', function () {
    $this->get('/otomasi')->assertRedirect('/masuk');
});

test('only supervisors may open Otomasi and switch templates', function (Peran $peran) {
    $staf = User::factory()->create(['peran' => $peran]);
    $fu = TemplateFollowup::factory()->create();

    $this->actingAs($staf)->get('/otomasi')->assertForbidden();
    $this->actingAs($staf)->patch("/otomasi/template/{$fu->id}", ['aktif' => false])->assertForbidden();
})->with([Peran::StafCs, Peran::PjKaryawan]);

test('each workflow shows its latest run', function () {
    $arsip = Workflow::factory()->create(['nama' => 'Arsip Data', 'jumlah_node' => 16]);
    $laporan = Workflow::factory()->create(['nama' => 'Laporan Harian']);
    $error = Workflow::factory()->create(['nama' => 'Notifikasi Error', 'status' => StatusWorkflow::Siaga->value]);
    WorkflowEvent::factory()->for($arsip)->pada('2026-09-20 01:00')->create(['detail' => 'lama']);
    WorkflowEvent::factory()->for($arsip)->pada('2026-09-27 01:00')->create(['detail' => '18 order, 4 customer']);
    WorkflowEvent::factory()->for($laporan)->pada('2026-09-27 20:00')->create(['detail' => 'terkirim']);
    // not a run: a report or a connection check does not count
    WorkflowEvent::factory()->for($laporan)->pada('2026-09-28 09:00')->create(['jenis' => 'LAPORAN']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('otomasi')
            ->where('workflow.0.node', '16 node')
            // more than 24 hours ago: a date, not "Kemarin"
            ->where('workflow.0.terakhir', ['waktu' => '27 Sep 01:00', 'catatan' => '18 order, 4 customer'])
            ->where('workflow.1.terakhir', ['waktu' => 'Kemarin 20:00', 'catatan' => 'terkirim'])
            ->where('workflow.2.terakhir', null)
            ->where('workflow.2.status', ['label' => 'Siaga', 'nada' => 'blue']));
});

test('the numbers at the top count today only', function () {
    $batasBayar = Workflow::factory()->create(['kode' => 'batas_bayar']);
    Workflow::factory()->create(['nama' => 'Notifikasi Error', 'status' => StatusWorkflow::Siaga->value]);
    Workflow::factory()->create(['status' => StatusWorkflow::Nonaktif->value]);
    WorkflowEvent::factory()->for($batasBayar)->gagal()->pada('2026-09-28 07:15')->create();
    WorkflowEvent::factory()->for($batasBayar)->gagal()->pada('2026-09-27 07:15')->create();
    WorkflowEvent::factory()->for($batasBayar)->pada('2026-09-28 08:15')->create(['data' => ['ditutup' => 1]]);
    WorkflowEvent::factory()->for($batasBayar)->pada('2026-09-28 09:15')->create(['data' => ['ditutup' => 2]]);
    WorkflowEvent::factory()->for($batasBayar)->pada('2026-09-27 09:15')->create(['data' => ['ditutup' => 5]]);
    foreach ([['SAPAAN', '2026-09-28 08:30'], ['SAPAAN', '2026-09-28 09:30'], ['KESAN', '2026-09-28 09:30'], ['SAPAAN', '2026-09-27 09:30']] as [$jenis, $waktu]) {
        Pesan::factory()->pada($waktu)->create(['meta' => ['otomatis' => $jenis]]);
    }
    Pesan::factory()->pada('2026-09-28 10:00')->create(); // an AI answer, not automated

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page
            ->where('ringkasan', [
                ['label' => 'Workflow aktif', 'nilai' => '2', 'meta' => 'Notifikasi Error siaga · 1 error hari ini'],
                ['label' => 'Pesan otomatis hari ini', 'nilai' => '3', 'meta' => '2 sapaan prospek · 1 tanya kesan'],
                ['label' => 'Order ditutup otomatis', 'nilai' => '3', 'meta' => 'belum di-ACC lewat 24 jam kerja · 09.15'],
                ['label' => 'Jam kerja otomatis', 'nilai' => '07.00–21.00', 'meta' => 'Minggu libur · sekarang di jam kerja'],
            ]));
});

test('a holiday is outside working hours', function () {
    ConfigToko::query()->where('kunci', 'HARI_LIBUR_OTOMATIS')->update(['nilai' => 'Minggu,Senin']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page->where('ringkasan.3.meta', 'Minggu, Senin libur · sekarang di luar jam kerja'));
});

test('rule texts are built from CONFIG_TOKO', function () {
    ConfigToko::query()->where('kunci', 'BATAS_BAYAR_JAM')->update(['nilai' => '6']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page->where('aturan', [
            'batasBayar' => 6, 'batasKonfirmasi' => 24, 'batasPickup' => 24, 'jedaFollowup' => 10,
            'malam' => '21.00–07.00', 'arsipOrder' => 90, 'arsipCustomer' => 365,
        ]));
});

test('first follow-ups are listed in funnel order with their wait', function () {
    TemplateFollowup::factory()->create(['kode' => 'FU-04', 'tahap' => 'SELESAI', 'kondisi_pemicu' => 'bukan order karyawan', 'acuan_waktu' => 'SELESAI']);
    TemplateFollowup::factory()->create(['kode' => 'FU-01', 'tahap' => 'TANYA_PRODUK,TANYA_HARGA,NEGO']);
    TemplateFollowup::factory()->create(['kode' => 'FU-03', 'tahap' => 'CART_DITINGGAL', 'acuan_waktu' => 'ORDER_DITUTUP']);
    TemplateFollowup::factory()->create(['kode' => 'FU-05', 'tahap' => 'BARU']);
    TemplateFollowup::factory()->create(['kode' => 'FU-01-2', 'tahap' => 'TANYA_PRODUK', 'status_aktif' => false]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page
            ->where('template', fn ($t) => collect($t)->pluck('kode')->all() === ['FU-05', 'FU-01', 'FU-03', 'FU-04'])
            ->where('template.1.tahap', 'TANYA_PRODUK · TANYA_HARGA · NEGO')
            ->where('template.1.jeda', '10 jam setelah chat terakhir')
            ->where('template.2.jeda', '10 jam setelah order ditutup')
            ->where('template.3.jeda', '2 hari setelah SELESAI')
            ->where('template.3.tahap', 'SELESAI · bukan order karyawan'));
});

test('a template switch is saved at once and logged', function () {
    $fu = TemplateFollowup::factory()->create(['kode' => 'FU-05']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->patch("/otomasi/template/{$fu->id}", ['aktif' => false])->assertRedirect();
    $this->actingAs($falah)->patch("/otomasi/template/{$fu->id}", ['aktif' => false])->assertRedirect();

    expect($fu->refresh()->status_aktif)->toBeFalse()
        ->and(ActivityLog::query()->get(['kategori', 'aksi', 'detail'])->toArray())->toBe([
            ['kategori' => KategoriAktivitas::Otomasi->value, 'aksi' => 'Ubah template sapaan', 'detail' => 'FU-05 · aktif → nonaktif'],
        ]);

    $this->actingAs($falah)->patch("/otomasi/template/{$fu->id}", ['aktif' => 'mungkin'])->assertSessionHasErrors('aktif');
});

test('the latest daily report and supervisor messages are shown', function () {
    $pantau = Workflow::factory()->create(['nama' => 'Pantau Koneksi WhatsApp']);
    $laporan = Workflow::factory()->create(['nama' => 'Laporan Harian']);
    WorkflowEvent::factory()->for($laporan)->pada('2026-09-26 20:00')->create(['jenis' => 'LAPORAN', 'data' => ['pesan' => 'lama']]);
    WorkflowEvent::factory()->for($laporan)->pada('2026-09-27 20:00')->create(['jenis' => 'LAPORAN', 'data' => ['pesan' => "*LAPORAN HARIAN*\n_Periode_"]]);
    WorkflowEvent::factory()->for($pantau)->pada('2026-09-28 08:00')->create(['jenis' => 'KONEKSI', 'data' => ['jenis_pesan' => 'NOMOR_PUTUS', 'pesan_supervisor' => 'pagi']]);
    WorkflowEvent::factory()->for($pantau)->pada('2026-09-28 11:30')->create(['jenis' => 'KONEKSI', 'data' => ['jenis_pesan' => 'NOMOR_PUTUS', 'pesan_supervisor' => str_repeat('a', 200)]]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/otomasi')
        ->assertInertia(fn (Assert $page) => $page
            ->where('laporan', ['teks' => "*LAPORAN HARIAN*\n_Periode_", 'jam' => '20.00', 'hari' => 'kemarin'])
            ->has('pesanSistem', 1)
            ->where('pesanSistem.0.judul', 'Nomor bot terputus')
            ->where('pesanSistem.0.waktu', 'Hari ini 11:30')
            ->where('pesanSistem.0.workflow', 'Pantau Koneksi WhatsApp')
            ->where('pesanSistem.0.teks', str_repeat('a', 150).'…'));
});
