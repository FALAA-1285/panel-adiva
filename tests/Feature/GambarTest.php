<?php

use App\Enums\PengirimPesan;
use App\Models\GambarProduk;
use App\Models\Pesan;
use App\Models\Produk;
use App\Models\User;
use App\Support\VarianProduk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->supervisor()->create());
});

test('guests are sent to the login page', function () {
    auth()->logout();

    $this->get('/katalog/gambar')->assertRedirect('/masuk');
});

test('photos and slots are listed with this month’s sends', function () {
    $foto = GambarProduk::create(['title' => 'Molreng Reguler Bawang Original', 'tags' => 'molreng, bawang', 'filename' => 'molreng-bawang.jpg', 'lebar' => 1080, 'tinggi' => 1080, 'ukuran_byte' => 184 * 1024]);
    GambarProduk::create(['title' => 'Usus Crispy', 'tags' => 'usus']);
    Pesan::factory()->fotoProduk($foto)->pada('2026-09-03 10:00')->create();
    Pesan::factory()->fotoProduk($foto)->pada('2026-09-27 10:00')->create();
    Pesan::factory()->fotoProduk($foto)->pada('2026-08-30 10:00')->create();
    Pesan::factory()->fotoProduk($foto)->pada('2026-09-10 10:00')->create(['pengirim' => PengirimPesan::Admin]);

    $this->get('/katalog/gambar')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/gambar')
            ->where('tab.gambar', 2)
            ->where('gambar.0.dikirim', 2)
            ->where('gambar.0.adaFile', true)
            ->where('gambar.0.ukuranKb', 184)
            ->where('gambar.1.adaFile', false));
});

test('product variants are product × flavour', function () {
    Produk::factory()->molreng()->create();
    Produk::create(['jenis_produk' => 'Basreng Pedas Daun Jeruk', 'rasa' => 'Pedas daun jeruk']);
    Produk::create(['jenis_produk' => 'Paket Sampel', 'rasa' => null]);

    expect(array_column(VarianProduk::semua(), 'judul'))
        ->toBe(['Molreng Reguler Bawang Original', 'Molreng Reguler Pedas', 'Basreng Pedas Daun Jeruk', 'Paket Sampel']);
});

test('the detail panel autosaves single fields', function () {
    $produk = Produk::factory()->molreng()->create();
    $gambar = GambarProduk::create(['title' => 'Molreng Reguler Bawang Original', 'tags' => 'molreng', 'aktif' => true]);

    $this->patch("/katalog/gambar/{$gambar->id}", ['tags' => 'molreng, bawang'])->assertRedirect();
    $this->patch("/katalog/gambar/{$gambar->id}", ['aktif' => false]);
    $this->patch("/katalog/gambar/{$gambar->id}", ['title' => 'Molreng Reguler Pedas', 'produk_id' => $produk->id]);

    $gambar->refresh();
    expect($gambar->tags)->toBe('molreng, bawang')
        ->and($gambar->aktif)->toBeFalse()
        ->and($gambar->title)->toBe('Molreng Reguler Pedas')
        ->and($gambar->produk_id)->toBe($produk->id);
});

test('uploading a new photo stores the file with its size', function () {
    $this->post('/katalog/gambar', ['foto' => UploadedFile::fake()->image('seblak-pedas.jpg', 1080, 810)])->assertRedirect();

    $gambar = GambarProduk::sole();
    expect($gambar->title)->toBe('seblak-pedas')
        ->and($gambar->filename)->toBe('seblak-pedas.jpg')
        ->and([$gambar->lebar, $gambar->tinggi])->toBe([1080, 810])
        ->and($gambar->image_url)->toContain('/storage/gambar-produk/');
    Storage::disk('public')->assertExists($gambar->path);
});

test('replacing a photo removes the old file, deleting removes the row and file', function () {
    $gambar = GambarProduk::create(['title' => 'Usus Crispy']);

    $this->post("/katalog/gambar/{$gambar->id}/file", ['foto' => UploadedFile::fake()->image('usus-1.png')]);
    $lama = $gambar->fresh()->path;
    $this->post("/katalog/gambar/{$gambar->id}/file", ['foto' => UploadedFile::fake()->image('usus-2.png')]);
    $baru = $gambar->fresh()->path;

    Storage::disk('public')->assertMissing($lama);
    Storage::disk('public')->assertExists($baru);

    $this->delete("/katalog/gambar/{$gambar->id}")->assertRedirect();

    expect(GambarProduk::count())->toBe(0);
    Storage::disk('public')->assertMissing($baru);
});

test('only images can be uploaded', function () {
    $this->post('/katalog/gambar', ['foto' => UploadedFile::fake()->create('katalog.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('foto');

    expect(GambarProduk::count())->toBe(0);
});
