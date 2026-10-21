<?php

use App\Enums\StatusData;
use App\Enums\StatusOrder;
use App\Models\LogOrder;
use App\Models\PaketReseller;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/katalog/paket')->assertRedirect('/masuk');
});

test('packages are read from the database with this month’s sales', function () {
    $paket = PaketReseller::factory()->kantin()->create();
    LogOrder::factory()->pada('2026-09-05 10:00')->status(StatusOrder::Selesai)->denganPaket($paket, 10)->create();
    LogOrder::factory()->pada('2026-09-20 10:00')->status(StatusOrder::Selesai)->denganPaket($paket, 4)->create();
    LogOrder::factory()->pada('2026-08-30 10:00')->status(StatusOrder::Selesai)->denganPaket($paket, 7)->create();
    LogOrder::factory()->pada('2026-09-21 10:00')->status(StatusOrder::Dibatalkan)->denganPaket($paket, 3)->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->get('/katalog/paket')
        ->assertInertia(fn (Assert $page) => $page
            ->component('katalog/paket')
            ->has('paket', 1)
            ->where('paket.0.kode', 'PKT-A')
            ->where('paket.0.isi', 'Molreng 20 + Basreng 20')
            ->where('paket.0.hargaTeks', null)
            ->where('paket.0.aktif', true)
            ->where('paket.0.terjual', 14));
});

test('saving the editor updates the package and who changed it', function () {
    $paket = PaketReseller::factory()->kantin()->create();
    $falah = User::factory()->supervisor()->create(['name' => 'Falah']);

    $this->actingAs($falah)
        ->put("/katalog/paket/{$paket->id}", [
            'kode_paket' => 'PKT-A',
            'nama_paket' => 'Paket Kantin Hemat',
            'isi_detail' => [['nama' => 'Basreng Pedas Daun Jeruk', 'jumlah' => '25 pcs']],
            'harga' => 'Rp240.000',
            'berat' => '± 1 kg',
            'tawarkan_ketika' => 'Modal di bawah Rp2 jt',
            'aktif' => false,
        ])
        ->assertRedirect();

    $paket->refresh();
    expect($paket->nama_paket)->toBe('Paket Kantin Hemat')
        ->and($paket->harga)->toBe(240000)
        ->and($paket->isi_detail)->toBe([['nama' => 'Basreng Pedas Daun Jeruk', 'jumlah' => '25 pcs']])
        ->and($paket->status_data)->toBe(StatusData::Nonaktif->value)
        ->and($paket->diubah_oleh_id)->toBe($falah->id);
});

test('package codes stay unique', function () {
    PaketReseller::factory()->kantin()->create();
    $lain = PaketReseller::factory()->kantin()->create(['kode_paket' => 'PKT-B', 'nama_paket' => 'Paket Warung Campur']);

    $this->actingAs(User::factory()->supervisor()->create())
        ->put("/katalog/paket/{$lain->id}", ['kode_paket' => 'PKT-A', 'nama_paket' => 'Paket Warung Campur'])
        ->assertSessionHasErrors('kode_paket');
});
