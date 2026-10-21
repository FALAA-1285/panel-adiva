<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\StatusPrompt;
use App\Models\ActivityLog;
use App\Models\PromptVersi;
use App\Models\UjiAdiva;
use App\Models\User;
use App\Models\Workflow;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Workflow::factory()->create(['kode' => 'chatbot', 'pengaturan' => ['model_utama' => 'gemini-3.6-flash', 'temperature' => 0.4]]);
});

test('guests are sent to the login page', function () {
    $this->get('/prompt')->assertRedirect('/masuk');
});

test('only supervisors may open the prompt and change versions', function (Peran $peran) {
    $staf = User::factory()->create(['peran' => $peran]);
    $arsip = PromptVersi::factory()->create();
    PromptVersi::factory()->draf()->create();

    $this->actingAs($staf)->get('/prompt')->assertForbidden();
    $this->actingAs($staf)->post('/prompt/terbitkan')->assertForbidden();
    $this->actingAs($staf)->delete('/prompt/draf')->assertForbidden();
    $this->actingAs($staf)->post("/prompt/{$arsip->id}/pulihkan")->assertForbidden();
})->with([Peran::StafCs, Peran::PjKaryawan]);

test('the sections the draft changes are marked and the first one is open', function () {
    $falah = User::factory()->falah()->create();
    $dina = User::factory()->dina()->create();
    PromptVersi::factory()->aktif()->for($dina, 'pembuat')->create([
        'versi' => 14,
        'diterbitkan_at' => '2026-09-29 10:00',
        'bagian' => [
            ['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'sama'],
            ['no' => 1, 'judul' => 'Eskalasi admin', 'teks' => "a\nresi karangan", 'keterangan' => 'kapan Adiva memanggil admin'],
            ['no' => 2, 'judul' => 'Kategorisasi', 'teks' => 'lama'],
        ],
    ]);
    PromptVersi::factory()->draf()->for($falah, 'pembuat')->create([
        'versi' => 15,
        'ringkasan' => 'Eskalasi order besar',
        'bagian' => [
            ['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'sama'],
            ['no' => 1, 'judul' => 'Eskalasi admin', 'teks' => "a\nbaru\nresi atau harga karangan", 'keterangan' => 'kapan Adiva memanggil admin'],
            ['no' => 2, 'judul' => 'Kategorisasi', 'teks' => 'baru'],
        ],
    ]);

    $this->actingAs($falah)
        ->get('/prompt')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prompt')
            ->where('aktif', ['versi' => 14, 'keterangan' => '29 Sep · Dina'])
            ->where('draf', ['versi' => 15, 'ringkasan' => 'Eskalasi order besar'])
            ->where('bagian.0.diubah', false)
            ->where('bagian.1.diubah', true)
            ->where('bagian.2.diubah', true)
            ->where('terpilih.no', 1)
            ->where('terpilih.keterangan', 'kapan Adiva memanggil admin')
            ->where('terpilih.baris', [
                ['jenis' => 'sama', 'no' => 1, 'teks' => 'a'],
                ['jenis' => 'hapus', 'no' => null, 'teks' => 'resi karangan'],
                ['jenis' => 'tambah', 'no' => 2, 'teks' => 'baru'],
                ['jenis' => 'tambah', 'no' => 3, 'teks' => 'resi atau harga karangan'],
            ])
            ->where('terpilih.tambah', 2)
            ->where('terpilih.hapus', 1)
            ->where('model', 'gemini-3.6-flash · temperature 0,4')
            ->where('riwayat.0.keterangan', 'Anda · belum diterbitkan')
            ->where('riwayat.1.keterangan', '29 Sep · Dina'));

    // another supervisor sees whose draft it is
    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/prompt')
        ->assertInertia(fn (Assert $page) => $page->where('riwayat.0.keterangan', 'Falah · belum diterbitkan'));
});

test('a section can be opened by number', function () {
    PromptVersi::factory()->aktif()->create();
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get('/prompt?bagian=1')
        ->assertInertia(fn (Assert $page) => $page->where('terpilih.judul', 'Identitas'));

    // an unknown number opens the first section
    $this->actingAs($falah)
        ->get('/prompt?bagian=99')
        ->assertInertia(fn (Assert $page) => $page->where('terpilih.no', 0));
});

test('without a draft nothing is compared', function () {
    PromptVersi::factory()->aktif()->create();
    UjiAdiva::create(['bagian' => 'PROMPT', 'pertanyaan' => 'uji versi lama']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/prompt')
        ->assertInertia(fn (Assert $page) => $page
            ->where('draf', null)
            ->where('bagian.0.diubah', false)
            ->where('bagian.1.diubah', false)
            ->where('terpilih.baris', [['jenis' => 'sama', 'no' => 1, 'teks' => 'Baca semua bagian.']])
            ->where('terpilih.tambah', null)
            ->where('terpilih.hapus', null)
            ->where('uji', null));
});

test('the last test of the current draft is shown', function () {
    $draf = PromptVersi::factory()->draf()->create();
    UjiAdiva::create(['bagian' => 'PROMPT', 'pertanyaan' => 'lama', 'prompt_versi_id' => $draf->id]);
    UjiAdiva::create([
        'bagian' => 'PROMPT',
        'pertanyaan' => 'bisa harga khusus 300 pcs?',
        'jawaban' => 'Adiva teruskan ke admin dulu ya.',
        'hasil' => ['butuh_admin' => true, 'tahap' => 'NEGO', 'alasan' => 'kerja sama distributor besar'],
        'prompt_versi_id' => $draf->id,
    ]);
    UjiAdiva::create(['bagian' => 'KATALOG', 'pertanyaan' => 'bagian lain']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/prompt')
        ->assertInertia(fn (Assert $page) => $page->where('uji', [
            'pertanyaan' => 'bisa harga khusus 300 pcs?',
            'jawaban' => 'Adiva teruskan ke admin dulu ya.',
            'butuhAdmin' => true,
            'tahap' => 'Nego',
            'alasan' => 'kerja sama distributor besar',
        ]));
});

test('publishing makes the draft the active version', function () {
    $falah = User::factory()->falah()->create();
    $lama = PromptVersi::factory()->aktif()->create(['versi' => 14]);
    $draf = PromptVersi::factory()->draf()->create(['versi' => 15, 'ringkasan' => 'Eskalasi order besar']);

    $this->actingAs($falah)->post('/prompt/terbitkan')->assertRedirect();

    expect($lama->refresh()->status)->toBe(StatusPrompt::Arsip)
        ->and($draf->refresh()->status)->toBe(StatusPrompt::Aktif)
        ->and($draf->diterbitkan_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($draf->user_id)->toBe($falah->id)
        ->and(ActivityLog::query()->sole()->only(['kategori', 'aksi', 'detail']))->toBe([
            'kategori' => KategoriAktivitas::Pengaturan->value, 'aksi' => 'Terbitkan prompt', 'detail' => 'v15 · Eskalasi order besar',
        ]);

    // nothing left to publish
    $this->actingAs($falah)->post('/prompt/terbitkan')->assertNotFound();
});

test('the draft can be thrown away', function () {
    $draf = PromptVersi::factory()->draf()->create(['versi' => 15, 'ringkasan' => 'Eskalasi order besar']);
    $uji = UjiAdiva::create(['bagian' => 'PROMPT', 'pertanyaan' => 'uji', 'prompt_versi_id' => $draf->id]);

    $this->actingAs(User::factory()->falah()->create())->delete('/prompt/draf')->assertRedirect();

    expect(PromptVersi::query()->count())->toBe(0)
        ->and($uji->refresh()->prompt_versi_id)->toBeNull()
        ->and(ActivityLog::query()->sole()->aksi)->toBe('Buang draf prompt');
});

test('restoring copies an archived version into the draft', function () {
    $falah = User::factory()->falah()->create();
    $bagianLama = [['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'versi 13']];
    $v13 = PromptVersi::factory()->create(['versi' => 13, 'ringkasan' => 'Satu order aktif saja', 'bagian' => $bagianLama]);
    $aktif = PromptVersi::factory()->aktif()->create(['versi' => 14]);

    // no draft yet: a new one after the newest version
    $this->actingAs($falah)->post("/prompt/{$v13->id}/pulihkan")->assertRedirect();
    $draf = PromptVersi::query()->draf()->sole();

    // toEqual: jsonb keeps its own key order
    expect($draf->only(['versi', 'ringkasan', 'bagian', 'user_id']))->toEqual([
        'versi' => 15, 'ringkasan' => 'Satu order aktif saja', 'bagian' => $bagianLama, 'user_id' => $falah->id,
    ])->and(ActivityLog::query()->sole()->detail)->toBe('v13 → draf v15');

    // a draft already there is overwritten, not duplicated
    $draf->update(['ringkasan' => 'diubah', 'bagian' => []]);
    $this->actingAs($falah)->post("/prompt/{$v13->id}/pulihkan");

    expect(PromptVersi::query()->draf()->sole()->only(['versi', 'ringkasan', 'bagian']))->toEqual([
        'versi' => 15, 'ringkasan' => 'Satu order aktif saja', 'bagian' => $bagianLama,
    ]);

    // only archived versions
    $this->actingAs($falah)->post("/prompt/{$aktif->id}/pulihkan")->assertNotFound();
});
