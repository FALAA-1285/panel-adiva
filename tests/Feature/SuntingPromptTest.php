<?php

use App\Enums\Peran;
use App\Enums\StatusPrompt;
use App\Models\ActivityLog;
use App\Models\PromptVersi;
use App\Models\User;

beforeEach(function () {
    $this->falah = User::factory()->falah()->create();
    $this->aktif = PromptVersi::factory()->aktif()->create(['versi' => 14, 'bagian' => [
        ['no' => 0, 'judul' => 'Baca dulu', 'teks' => 'Baca semua bagian.'],
        ['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva.'],
    ]]);
});

test('editing a section without a draft starts one from the active version', function () {
    $this->actingAs($this->falah)
        ->put('/prompt/bagian/1', ['teks' => 'Kamu Adiva dari Bang Bana.', 'ringkasan' => ''])
        ->assertRedirect('/prompt?bagian=1');

    $draf = PromptVersi::query()->draf()->sole();
    expect($draf->versi)->toBe(15)
        ->and($draf->ringkasan)->toBe('Ubah bagian 1. Identitas')
        ->and($draf->teksBagian(1))->toBe('Kamu Adiva dari Bang Bana.')
        ->and($draf->teksBagian(0))->toBe('Baca semua bagian.')
        ->and($draf->teks_lengkap)->toContain('Kamu Adiva dari Bang Bana.')
        ->and($this->aktif->refresh()->teksBagian(1))->toBe('Kamu Adiva.')
        ->and(ActivityLog::query()->sole()->only(['aksi', 'detail']))->toBe(['aksi' => 'Ubah draf prompt', 'detail' => 'v15 · 1. Identitas']);
});

test('editing again changes the same draft and its summary', function () {
    $draf = PromptVersi::factory()->draf()->create(['versi' => 15, 'ringkasan' => 'Lama', 'bagian' => $this->aktif->bagian]);

    $this->actingAs($this->falah)->put('/prompt/bagian/0', ['teks' => 'Baca pelan-pelan.', 'ringkasan' => 'Perjelas bagian 0']);

    expect(PromptVersi::query()->draf()->count())->toBe(1)
        ->and($draf->refresh()->teksBagian(0))->toBe('Baca pelan-pelan.')
        ->and($draf->ringkasan)->toBe('Perjelas bagian 0')
        ->and($draf->status)->toBe(StatusPrompt::Draf);
});

test('a section must exist and keep some text, and only supervisors may edit', function () {
    $this->actingAs($this->falah)->put('/prompt/bagian/9', ['teks' => 'x'])->assertNotFound();
    $this->actingAs($this->falah)->put('/prompt/bagian/1', ['teks' => ''])->assertSessionHasErrors(['teks' => 'Teks bagian tidak boleh kosong.']);
    $this->actingAs(User::factory()->create(['peran' => Peran::StafCs]))->put('/prompt/bagian/1', ['teks' => 'x'])->assertForbidden();
});

test('Teks penuh shows the draft, or the active version without one', function () {
    $this->actingAs($this->falah)->get('/prompt/teks')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSeeText("## 1. Identitas\nKamu Adiva.", false);

    PromptVersi::factory()->draf()->create(['versi' => 15, 'bagian' => [['no' => 1, 'judul' => 'Identitas', 'teks' => 'Kamu Adiva versi draf.']]]);

    $this->actingAs($this->falah)->get('/prompt/teks')->assertSeeText('Kamu Adiva versi draf.');
});
