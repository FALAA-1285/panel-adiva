<?php

use App\Accurate\BacaAccurate;
use App\Models\AksesAccurate;

beforeEach(function () {
    config([
        'services.accurate.cron_key' => 'kunci-rahasia-uji',
        'services.accurate.token' => null,
    ]);
});

test('cron endpoint requires valid key when configured', function () {
    $this->postJson('/cron/accurate-sinkron')
        ->assertStatus(401)
        ->assertJson(['sukses' => false, 'pesan' => 'Kunci cron tidak valid.']);

    $this->postJson('/cron/accurate-sinkron', [], ['X-Cron-Key' => 'kunci-salah'])
        ->assertStatus(401);
});

test('cron endpoint fails if accurate is not connected', function () {
    AksesAccurate::query()->delete();

    $this->postJson('/cron/accurate-sinkron', [], ['X-Cron-Key' => 'kunci-rahasia-uji'])
        ->assertStatus(422)
        ->assertJson(['sukses' => false, 'pesan' => 'Accurate belum tersambung.']);
});

test('cron endpoint executes sync successfully when key is valid', function () {
    AksesAccurate::create([
        'access_token' => 'akses-token',
        'disambungkan_at' => now(),
    ]);

    app()->bind(BacaAccurate::class, fn () => new class implements BacaAccurate
    {
        public function barang(): array
        {
            return [
                [
                    'accurate_id' => 101,
                    'kode' => 'BRG-1',
                    'nama' => 'Barang Uji 1',
                    'barcode' => '8991234567890',
                    'kategori' => 'KILOAN',
                    'satuan' => 'KG',
                    'harga' => 15000,
                    'nonaktif' => false,
                ],
            ];
        }
    });

    $this->postJson('/cron/accurate-sinkron', [], ['X-Cron-Key' => 'kunci-rahasia-uji'])
        ->assertSuccessful()
        ->assertJson([
            'sukses' => true,
            'status' => 'BERHASIL',
            'jumlah' => 1,
        ]);
});
