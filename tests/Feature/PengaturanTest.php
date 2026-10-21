<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\PromptVersi;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IsianPengaturan;

beforeEach(function () {
    IsianPengaturan::simpanAwal();
});

test('guests are sent to the login page', function () {
    $this->get('/pengaturan')->assertRedirect('/masuk');
});

test('only supervisors may open and save Pengaturan', function (Peran $peran) {
    $staf = User::factory()->create(['peran' => $peran]);

    $this->actingAs($staf)->get('/pengaturan')->assertForbidden();
    $this->actingAs($staf)->put('/pengaturan', IsianPengaturan::form())->assertForbidden();
    $this->actingAs($staf)->get('/katalog')->assertInertia(fn (Assert $page) => $page->where('panel.akses.pengaturan', false));
})->with([Peran::StafCs, Peran::PjKaryawan]);

test('the page shows every setting, without the API key itself', function () {
    PromptVersi::create(['versi' => 14, 'ringkasan' => 'v14', 'status' => 'AKTIF', 'bagian' => array_map(fn ($no) => ['no' => $no, 'judul' => "Bagian {$no}", 'teks' => ''], range(0, 17))]);

    $respons = $this->actingAs(User::factory()->falah()->create())->get('/pengaturan');

    $respons->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pengaturan')
            ->where('nilai.ADMIN_WA', '6281234560007')
            ->where('nilai.HARI_LIBUR_OTOMATIS', ['Minggu'])
            ->where('nilai.KURIR_DIKECUALIKAN', ['SAPLite'])
            ->where('nilai.REKENING', '')
            ->where('ai.AI_TEMPERATURE', '0.4')
            ->where('ai.AI_INGATAN_CHAT', '60')
            ->where('invoiceTerakhir', 'INV/2026/09/141')
            ->where('apiKeyTersimpan', true)
            ->where('promptBagian', 18));

    expect($respons->getContent())->not->toContain('kunci-rahasia-mengantar');
});

test('staff are listed with what their number may do on WhatsApp', function () {
    $falah = User::factory()->falah()->create();
    User::factory()->create(['name' => 'Bu Sarah', 'nomor_wa' => '6281298760646', 'peran' => Peran::PjKaryawan]);
    User::factory()->create(['name' => 'Rizky', 'nonaktif_at' => now()]);

    $this->actingAs($falah)
        ->get('/pengaturan')
        ->assertInertia(fn (Assert $page) => $page
            ->has('staf', 2)
            ->where('staf.0.nomor', '0812-••••-••••')
            ->where('staf.0.diWhatsapp', 'Semua perintah')
            ->where('staf.1.nomor', '0812-••••-0646')
            ->where('staf.1.diWhatsapp', 'Order karyawan')
            ->where('pj', 'Bu Sarah'));
});

test('bot numbers show their latest connection check', function () {
    $pantau = Workflow::create(['kode' => 'pantau_koneksi', 'nama' => 'Pantau Koneksi WhatsApp']);
    $cek = fn (string $sesi, string $waktu, string $status) => WorkflowEvent::create([
        'workflow_id' => $pantau->id, 'waktu' => $waktu, 'jenis' => 'KONEKSI', 'status' => $status, 'judul' => $sesi, 'data' => ['sesi' => $sesi],
    ]);
    $cek('bangbana-2', '2026-09-28 11:30', 'PUTUS');
    $cek('bangbana-2', '2026-09-28 13:30', 'TERHUBUNG');
    $cek('bangbana-waha', '2026-09-28 13:15', 'PUTUS');

    $this->actingAs(User::factory()->falah()->create())
        ->get('/pengaturan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('nomorBot', [
                ['sesi' => 'bangbana-waha', 'label' => 'Nomor Utama', 'status' => ['label' => 'Putus', 'nada' => 'red']],
                ['sesi' => 'bangbana-2', 'label' => 'Nomor Lawang', 'status' => ['label' => 'Terhubung', 'nada' => 'green']],
            ])
            ->where('terakhirDicek', '13:30'));
});

test('Simpan perubahan writes and logs only what changed', function () {
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->put('/pengaturan', IsianPengaturan::form([
            'SARAH_WA' => '0812 9876 0000',
            'BATAS_BAYAR_JAM' => '6',
            'HARI_LIBUR_OTOMATIS' => ['Minggu', '2026-12-25'],
            'KURIR_DIKECUALIKAN' => [],
            'ai' => ['AI_TEMPERATURE' => '0.7'],
        ]))
        ->assertRedirect();

    $nilai = ConfigToko::query()->pluck('nilai', 'kunci');
    expect($nilai['SARAH_WA'])->toBe('6281298760000')
        ->and($nilai['BATAS_BAYAR_JAM'])->toBe('6')
        ->and($nilai['HARI_LIBUR_OTOMATIS'])->toBe('Minggu,2026-12-25')
        ->and($nilai['KURIR_DIKECUALIKAN'])->toBe('')
        ->and(Workflow::query()->where('kode', 'chatbot')->value('pengaturan')['temperature'])->toBe(0.7);

    // empty fields that had no row are not "changes"
    expect(ActivityLog::query()->where('kategori', KategoriAktivitas::Pengaturan->value)->orderBy('id')->pluck('detail')->all())->toBe([
        'PJ program karyawan: 6281298760646 → 6281298760000',
        'Batas bayar (kirim): 5 → 6 jam kerja',
        'Hari libur: Minggu → Minggu,2026-12-25',
        'Kurir disembunyikan: SAPLite → —',
        'Temperature: 0.4 → 0.7',
    ]);

    $this->actingAs($falah)
        ->get('/pengaturan')
        ->assertInertia(fn (Assert $page) => $page->where('disimpan', 'Disimpan 28 Sep 13:42 · Falah'));
});

test('saving the same values writes nothing', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->put('/pengaturan', IsianPengaturan::form())
        ->assertRedirect();

    expect(ActivityLog::query()->count())->toBe(0);
});

test('invalid values are refused with a message per field', function (array $ubah, string $kolom, ?string $pesan) {
    $respons = $this->actingAs(User::factory()->falah()->create())
        ->put('/pengaturan', IsianPengaturan::form($ubah))
        ->assertSessionHasErrors($kolom);

    if ($pesan) {
        expect(session('errors')->first($kolom))->toBe($pesan);
    }
})->with([
    'jam kerja' => [['JAM_PESAN_OTOMATIS' => '7-21'], 'JAM_PESAN_OTOMATIS', 'Tulis seperti 07:00-21:00.'],
    'hari libur' => [['HARI_LIBUR_OTOMATIS' => ['Libur']], 'HARI_LIBUR_OTOMATIS.0', 'Tulis nama hari (Minggu) atau tanggal seperti 2026-12-25.'],
    'tanggal libur' => [['HARI_LIBUR_OTOMATIS' => ['2026-02-30']], 'HARI_LIBUR_OTOMATIS.0', null],
    'batas bayar' => [['BATAS_BAYAR_JAM' => '0'], 'BATAS_BAYAR_JAM', 'Batas bayar (kirim) minimal 1.'],
    'link ulasan' => [['LINK_ULASAN' => 'bukan tautan'], 'LINK_ULASAN', null],
    'nomor' => [['ADMIN_WA' => '12345'], 'ADMIN_WA', 'Tulis nomor WhatsApp seperti 6281234567890.'],
    'jam pickup' => [['MENGANTAR_JAM_PICKUP' => '25:00'], 'MENGANTAR_JAM_PICKUP', null],
    'sesi' => [['SESI_UTAMA' => 'bangbana-9'], 'SESI_UTAMA', null],
    'temperature' => [['ai' => ['AI_TEMPERATURE' => '1.5']], 'ai.AI_TEMPERATURE', 'Temperature harus antara 0 dan 1.'],
    'model' => [['ai' => ['AI_MODEL_UTAMA' => 'gpt-lain']], 'ai.AI_MODEL_UTAMA', null],
]);
