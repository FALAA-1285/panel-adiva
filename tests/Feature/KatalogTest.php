<?php

use App\Models\GambarProduk;
use App\Models\Karyawan;
use App\Models\Kebijakan;
use App\Models\PaketReseller;
use App\Models\Produk;
use App\Models\UjiAdiva;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/katalog')->assertRedirect('/masuk');
});

test('the product table is read from the database', function () {
    $molreng = Produk::factory()->molreng()->create();
    Produk::factory()->molreng()->create(['jenis_produk' => 'Usus Crispy', 'kategori_produk' => 'Usus', 'gramasi' => '100 g']);
    GambarProduk::create(['produk_id' => $molreng->id, 'title' => 'Molreng Reguler Bawang Original', 'filename' => 'molreng-bawang.jpg']);
    // a slot without a file does not count as a photo
    GambarProduk::create(['produk_id' => Produk::query()->where('jenis_produk', 'Usus Crispy')->value('id'), 'title' => 'Usus Crispy']);

    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/produk')
            ->has('produk', 2)
            ->where('produk.0', fn ($p) => collect($p)->except('isian')->all() === [
                'id' => $molreng->id,
                'nama' => 'Molreng Reguler',
                'kategori' => 'Molreng',
                'kemasan' => 'Pack 250 g',
                'rasa' => 'Bawang original, pedas',
                'tier' => '1–9 · 10–49 · 50+ pcs',
                'harga' => 'Harga belum diisi',
                'target' => 'Kantin sekolah, warung',
                'adaFoto' => true,
            ])
            ->where('produk.0.isian.jenis_produk', 'Molreng Reguler')
            ->where('produk.1.adaFoto', false));
});

test('filled-in tier prices replace the placeholder', function () {
    Produk::factory()->molreng()->create(['harga_bertingkat' => [
        'satuan' => 'bal',
        'tier' => [['min' => 1, 'max' => 29, 'harga' => 92000], ['min' => 30, 'max' => null, 'harga' => 87000]],
    ]]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page
            ->where('produk.0.tier', '1–29 · 30+ bal')
            ->where('produk.0.harga', 'Rp92.000 · Rp87.000'));
});

test('a tier without a price shows a dash instead of hiding the others', function () {
    Produk::factory()->molreng()->create(['harga_bertingkat' => [
        'satuan' => 'bal',
        'tier' => [['min' => 1, 'max' => 29, 'harga' => 92000], ['min' => 30, 'max' => null, 'harga' => null]],
    ]]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page->where('produk.0.harga', 'Rp92.000 · —'));
});

test('the tabs count every part of the catalogue', function () {
    Produk::factory()->molreng()->create();
    PaketReseller::create(['kode_paket' => 'PKT-A', 'nama_paket' => 'Paket Kantin']);
    Kebijakan::create(['kode' => 'KB-01', 'topik' => 'Minimal order']);
    Kebijakan::create(['kode' => 'KB-02', 'topik' => 'Diskon']);
    Karyawan::create(['nama' => 'Yoga']);

    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', ['produk' => 1, 'paket' => 1, 'gambar' => 0, 'kebijakan' => 2, 'karyawan' => 1, 'toko' => 0]));
});

test('the latest catalogue test is shown and its product is highlighted', function () {
    $kripca = Produk::factory()->molreng()->create(['jenis_produk' => 'Kripca 1,8 kg']);
    UjiAdiva::create(['bagian' => 'KATALOG', 'pertanyaan' => 'lama', 'produk_id' => null]);
    UjiAdiva::create(['bagian' => 'KEBIJAKAN', 'pertanyaan' => 'bagian lain']);
    UjiAdiva::create([
        'bagian' => 'KATALOG',
        'pertanyaan' => 'kripca 1,8 kg kalau ambil 30 harganya brp?',
        'jawaban' => 'Untuk Kripca 1,8 kg …',
        'catatan' => 'Tool dipakai: Cari_Produk_per_Kategori (Keripik kaca)',
        'produk_id' => $kripca->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/katalog')
        ->assertInertia(fn (Assert $page) => $page
            ->where('uji.pertanyaan', 'kripca 1,8 kg kalau ambil 30 harganya brp?')
            ->where('uji.produkId', $kripca->id));
});
