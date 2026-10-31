<?php

use App\Console\Commands\SinkronKatalogN8n;
use App\Models\GambarProduk;
use App\Models\Produk;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.n8n.api_url' => 'http://n8n.test',
        'services.n8n.api_key' => 'test-key',
    ]);
});

test('n8n:sinkron-katalog fetches and updates produk and gambar_produk from n8n data tables', function () {
    Http::fake([
        'n8n.test/api/v1/data-tables/'.SinkronKatalogN8n::N8N_TABEL_PRODUK.'/rows*' => Http::response([
            'data' => [
                [
                    'id' => 1,
                    'jenis_produk' => 'BASRENG STIK 1,8 KG',
                    'kategori_produk' => 'BASRENG',
                    'brand' => 'CIKUJANG',
                    'kategori_kemasan' => 'LOSS',
                    'gramasi' => '1,8 KILOGRAM',
                    'deskripsi' => 'Target Grosir: Khusus los-losan. Target Reseller: Repack.',
                    'rasa' => 'ORIGINAL, PEDAS',
                    'informasi_harga' => "Harga bertingkat berdasarkan jumlah pembelian:\n- 1 - 20 Pcs: Rp 92.000\n- 21 - 49 Pcs: Rp 89.000\n- ≥ 50 Pcs: Rp 87.000",
                ],
                [
                    'id' => 37,
                    'jenis_produk' => 'KERUPUK RUJAK',
                    'kategori_produk' => 'KERUPUK RUJAK',
                    'brand' => 'BANG BANA',
                    'kategori_kemasan' => 'PACK',
                    'gramasi' => '150 GRAM',
                    'deskripsi' => 'Cocok untuk konsumsi pribadi dan oleh-oleh.',
                    'rasa' => 'RUJAK',
                    'informasi_harga' => 'Rp 8.000 / pack',
                ],
            ],
        ]),
        'n8n.test/api/v1/data-tables/'.SinkronKatalogN8n::N8N_TABEL_GAMBAR.'/rows*' => Http::response([
            'data' => [
                [
                    'id' => 1,
                    'title' => 'Produk Best Seller Bang Bana',
                    'description_for_ai' => 'Katalog produk terlaris',
                    'tags' => 'best-seller, rekomendasi',
                    'filename' => '2.png',
                    'image_url' => 'https://drive.google.com/thumbnail?id=1zUmOhL6&sz=w1600',
                    'gdrive_file_id' => '1zUmOhL6',
                    'mime_type' => 'image/png',
                ],
                [
                    'id' => 10,
                    'title' => 'BASRENG STIK ORIGINAL 1,8 KG',
                    'description_for_ai' => 'Foto basreng stik kemasan loss',
                    'tags' => 'basreng, stik, loss',
                    'filename' => 'BASRENG STIK ORIGINAL LOSS.png',
                    'image_url' => 'https://drive.google.com/thumbnail?id=123&sz=w1600',
                    'gdrive_file_id' => '123',
                    'mime_type' => 'image/png',
                ],
                [
                    'id' => 69,
                    'title' => 'KERUPUK RUJAK',
                    'description_for_ai' => 'Foto kerupuk rujak',
                    'tags' => 'kerupuk, rujak',
                    'filename' => 'KERUPUK RUJAK.png',
                    'image_url' => 'https://drive.google.com/thumbnail?id=456&sz=w1600',
                    'gdrive_file_id' => '456',
                    'mime_type' => 'image/png',
                ],
            ],
        ]),
    ]);

    $this->artisan('n8n:sinkron-katalog')
        ->expectsOutputToContain('Ditemukan: 2 produk dan 3 gambar dari n8n.')
        ->expectsOutputToContain('Sinkronisasi katalog berhasil.')
        ->assertSuccessful();

    expect(Produk::query()->count())->toBe(2)
        ->and(GambarProduk::query()->count())->toBe(3);

    $p1 = Produk::query()->find(1);
    expect($p1)->not->toBeNull()
        ->and($p1->jenis_produk)->toBe('BASRENG STIK 1,8 KG')
        ->and($p1->target_pasar)->toBe('Grosir, Reseller')
        ->and($p1->harga_bertingkat['satuan'])->toBe('pcs')
        ->and($p1->harga_bertingkat['tier'])->toHaveCount(3)
        ->and($p1->harga_bertingkat['tier'][0]['harga'])->toBe(92000);

    $g1 = GambarProduk::query()->find(1);
    expect($g1->produk_id)->toBeNull(); // General catalog banner

    $g10 = GambarProduk::query()->find(10);
    expect($g10->produk_id)->toBe(1); // Linked to BASRENG STIK 1,8 KG

    $g69 = GambarProduk::query()->find(69);
    expect($g69->produk_id)->toBe(37); // Linked to KERUPUK RUJAK
});
