<?php

use App\Enums\Peran;
use App\Enums\StatusData;
use App\Models\ActivityLog;
use App\Models\HargaKaryawan;
use App\Models\Karyawan;
use App\Models\Kebijakan;
use App\Models\PaketReseller;
use App\Models\Produk;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->falah = User::factory()->falah()->create();
});

test('Tambah produk saves the PRODUK columns and the tiered prices', function () {
    $this->actingAs($this->falah)->post('/katalog/produk', [
        'jenis_produk' => 'Usus Crispy', 'kategori_produk' => 'Usus', 'gramasi' => '100 g', 'satuan' => 'pcs',
        'tier' => [['min' => '1', 'max' => '9', 'harga' => 'Rp12.000'], ['min' => '10', 'max' => '', 'harga' => '11000']],
    ])->assertSessionHasNoErrors();

    $produk = Produk::query()->sole();
    expect($produk->only(['jenis_produk', 'kategori_produk', 'gramasi']))->toBe(['jenis_produk' => 'Usus Crispy', 'kategori_produk' => 'Usus', 'gramasi' => '100 g'])
        ->and($produk->harga_bertingkat)->toEqual(['satuan' => 'pcs', 'tier' => [['min' => 1, 'max' => 9, 'harga' => 12000], ['min' => 10, 'max' => null, 'harga' => 11000]]])
        ->and(ActivityLog::query()->sole()->aksi)->toBe('Tambah produk');
});

test('a product needs a name and tiers that go up', function () {
    $this->actingAs($this->falah)->post('/katalog/produk', ['jenis_produk' => ''])->assertSessionHasErrors(['jenis_produk' => 'Isi nama produk.']);
    $this->actingAs($this->falah)->post('/katalog/produk', [
        'jenis_produk' => 'Usus', 'tier' => [['min' => '1', 'max' => '', 'harga' => ''], ['min' => '10', 'max' => '', 'harga' => '']],
    ])->assertSessionHasErrors(['tier' => 'Tingkat harga harus berurutan dan tidak tumpang tindih.']);
});

test('editing a product keeps it the same row', function () {
    $produk = Produk::factory()->molreng()->create();

    $this->actingAs($this->falah)->put("/katalog/produk/{$produk->id}", ['jenis_produk' => 'Molreng Reguler Baru', 'tier' => []])->assertSessionHasNoErrors();

    expect($produk->refresh()->jenis_produk)->toBe('Molreng Reguler Baru')->and($produk->harga_bertingkat)->toBeNull();
});

test('Impor CSV adds new products, updates known ones by name and skips bad lines', function () {
    Produk::factory()->create(['jenis_produk' => 'Molreng Reguler', 'rasa' => 'lama']);
    $csv = "jenis_produk,kategori_produk,brand,kategori_kemasan,gramasi,rasa,target_pasar,deskripsi,satuan,harga\n"
        ."molreng reguler,Molreng,,Pack,250 g,Pedas,,,pcs,1-9:15000;10+:14000\n"
        ."Usus Crispy,Usus,,Pack,100 g,Original,,,pcs,\n"
        .",Tanpa nama,,,,,,,,\n"
        ."Basreng,Basreng,,,,,,,pcs,murah\n";

    $this->actingAs($this->falah)
        ->post('/katalog/produk/impor', ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertExactJson(['hasil' => '1 ditambah · 1 diperbarui · 2 baris dilewati (baris 4: nama kosong, baris 5: format harga)']);

    expect(Produk::query()->count())->toBe(2)
        ->and(Produk::query()->where('jenis_produk', 'Molreng Reguler')->value('rasa'))->toBe('Pedas')
        ->and(Produk::query()->where('jenis_produk', 'Molreng Reguler')->sole()->harga_bertingkat['tier'][1])->toEqual(['min' => 10, 'max' => null, 'harga' => 14000]);
});

test('Impor CSV handles multi-line cells in quoted fields', function () {
    $csv = "jenis_produk,kategori_produk,brand,kategori_kemasan,gramasi,rasa,target_pasar,deskripsi,satuan,harga\n"
        ."\"Keripik Pedas\",\"Keripik\",,Pack,200 g,Pedas,,\"Baris satu\nBaris dua multi baris\",pcs,1-10:15000\n";

    $this->actingAs($this->falah)
        ->post('/katalog/produk/impor', ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertExactJson(['hasil' => '1 ditambah · 0 diperbarui']);

    $produk = Produk::query()->where('jenis_produk', 'Keripik Pedas')->first();
    expect($produk)->not->toBeNull()
        ->and($produk->deskripsi)->toContain("Baris satu\nBaris dua multi baris");
});

test('a CSV without the column names is refused', function () {
    $this->actingAs($this->falah)
        ->post('/katalog/produk/impor', ['file' => UploadedFile::fake()->createWithContent('produk.csv', "nama,harga\nX,1")], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
});

test('Tambah paket makes the short Isi from the items', function () {
    $this->actingAs($this->falah)->post('/katalog/paket', [
        'kode_paket' => 'PKT-D', 'nama_paket' => 'Paket Coba', 'aktif' => true,
        'isi_detail' => [['nama' => 'Molreng Reguler', 'jumlah' => '10 pcs'], ['nama' => 'Basreng', 'jumlah' => '5 pcs']],
    ])->assertRedirect();

    $paket = PaketReseller::query()->sole();
    expect($paket->isi)->toBe('Molreng Reguler 10 pcs + Basreng 5 pcs')
        ->and($paket->status_data)->toBe(StatusData::Aktif->value);

    $this->actingAs($this->falah)->post('/katalog/paket', ['kode_paket' => 'PKT-D', 'nama_paket' => 'Lain'])->assertSessionHasErrors('kode_paket');
});

test('Tambah kebijakan takes the next KB code', function () {
    Kebijakan::factory()->create(['kode' => 'KB-07']);
    Kebijakan::factory()->create(['kode' => 'KB-03']);

    $this->actingAs($this->falah)->post('/katalog/kebijakan', ['topik' => 'Retur barang', 'jawaban_resmi' => 'Retur 2x24 jam.', 'kata_pemicu' => ['retur']])->assertRedirect();

    expect(Kebijakan::query()->where('topik', 'Retur barang')->sole()->only(['kode', 'status_data', 'kata_pemicu']))
        ->toBe(['kode' => 'KB-08', 'status_data' => 'AKTIF', 'kata_pemicu' => 'retur']);
});

test('employees are added and edited with a normalised, unique number', function () {
    $this->actingAs($this->falah)->post('/katalog/karyawan', ['nama' => 'Yoga', 'nomor_wa' => '0896 1200 0077', 'bagian' => 'Produksi', 'aktif' => true])->assertSessionHasNoErrors();
    $yoga = Karyawan::query()->sole();
    expect($yoga->nomor_wa)->toBe('6289612000077')->and($yoga->status)->toBe('AKTIF');

    $this->actingAs($this->falah)->post('/katalog/karyawan', ['nama' => 'Lain', 'nomor_wa' => '6289612000077'])->assertSessionHasErrors(['nomor_wa' => 'Nomor ini sudah ada di daftar karyawan.']);

    $this->actingAs($this->falah)->put("/katalog/karyawan/{$yoga->id}", ['nama' => 'Yoga P.', 'nomor_wa' => '6289612000077', 'aktif' => false])->assertSessionHasNoErrors();
    expect($yoga->refresh()->only(['nama', 'status']))->toBe(['nama' => 'Yoga P.', 'status' => 'NONAKTIF']);
});

test('employee prices are saved together', function () {
    $basreng = HargaKaryawan::create(['varian_produk' => 'Basreng Original']);
    $kripca = HargaKaryawan::create(['varian_produk' => 'Kripca', 'harga_karyawan' => 70000]);

    $this->actingAs($this->falah)->put('/katalog/karyawan/harga', ['harga' => [
        ['id' => $basreng->id, 'harga' => 'Rp9.000', 'batas' => '4 pack / minggu'],
        ['id' => $kripca->id, 'harga' => '', 'batas' => ''],
    ]])->assertSessionHasNoErrors();

    expect($basreng->refresh()->only(['harga_karyawan', 'batas']))->toBe(['harga_karyawan' => 9000, 'batas' => '4 pack / minggu'])
        ->and($kripca->refresh()->harga_karyawan)->toBeNull();
});

test('only supervisors may change the catalogue, everyone may look', function () {
    $cs = User::factory()->create(['peran' => Peran::StafCs]);
    $paket = PaketReseller::factory()->kantin()->create();

    $this->actingAs($cs)->get('/katalog')->assertOk();
    $this->actingAs($cs)->post('/katalog/produk', ['jenis_produk' => 'X'])->assertForbidden();
    $this->actingAs($cs)->put("/katalog/paket/{$paket->id}", ['kode_paket' => 'X', 'nama_paket' => 'X'])->assertForbidden();
    $this->actingAs($cs)->post('/katalog/kebijakan', ['topik' => 'X'])->assertForbidden();
    $this->actingAs($cs)->post('/katalog/karyawan', ['nama' => 'X', 'nomor_wa' => '6281'])->assertForbidden();
});
