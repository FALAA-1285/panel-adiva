<?php

use App\Enums\Peran;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Models\ActivityLog;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;

beforeEach(function () {
    $this->falah = User::factory()->falah()->create();
});

/**
 * @return array<string, mixed>
 */
function isianPenilaian(array $ubah = []): array
{
    return [
        'tipe_customer' => 'RESELLER', 'tahap_funnel' => 'TANYA_HARGA', 'skor_prospek' => 'WARM', 'kota' => 'Malang',
        'minat_produk' => 'Molreng', 'lokasi_jualan' => 'Kantin', 'estimasi_modal' => 'Rp1.500.000', 'butuh_admin' => false, ...$ubah,
    ];
}

test('changing the assessment saves it, and a new stage counts as a manual move', function () {
    $c = CrmCustomer::factory()->create(['nama' => 'Rina', 'tahap_funnel' => TahapFunnel::TanyaHarga]);

    $this->actingAs($this->falah)->patch("/pelanggan/{$c->id}/penilaian", isianPenilaian(['tahap_funnel' => 'NEGO', 'skor_prospek' => 'HOT']))->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->tahap_funnel)->toBe(TahapFunnel::Nego)
        ->and($c->skor_prospek->value)->toBe('HOT')
        ->and($c->estimasi_modal)->toBe(1500000)
        ->and($c->tahap_manual_oleh_id)->toBe($this->falah->id)
        ->and($c->tahap_sejak)->toHaveKey('NEGO')
        ->and($c->riwayat()->sole()->teks)->toBe('Tahap → '.TahapFunnel::Nego->labelSingkat().' · diubah manual oleh Falah')
        ->and(ActivityLog::query()->sole()->only(['aksi', 'detail']))->toMatchArray(['aksi' => 'Ubah penilaian']);
});

test('the same stage writes no history, and staff without the right are refused', function () {
    $c = CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::TanyaHarga]);

    $this->actingAs($this->falah)->patch("/pelanggan/{$c->id}/penilaian", isianPenilaian());
    expect($c->riwayat()->count())->toBe(0);

    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->patch("/pelanggan/{$c->id}/penilaian", isianPenilaian())->assertForbidden();
});

test('Tandai sudah ditangani clears the admin flag', function () {
    $c = CrmCustomer::factory()->create(['butuh_admin' => true, 'alasan_butuh_admin' => 'Nego harga']);

    $this->actingAs($this->falah)->patch("/pelanggan/{$c->id}/tangani")->assertRedirect();

    expect($c->refresh()->only(['butuh_admin', 'alasan_butuh_admin']))->toBe(['butuh_admin' => false, 'alasan_butuh_admin' => null]);
});

test('the permanent note is written as typed', function () {
    $c = CrmCustomer::factory()->create(['catatan_tetap' => 'Lama']);

    $this->actingAs($this->falah)->patch("/pelanggan/{$c->id}/catatan", ['catatan_tetap' => "Guru SD.\nSuka foto produk."])->assertSessionHasNoErrors();
    expect($c->refresh()->catatan_tetap)->toBe("Guru SD.\nSuka foto produk.");

    $this->actingAs($this->falah)->patch("/pelanggan/{$c->id}/catatan", ['catatan_tetap' => ''])->assertSessionHasNoErrors();
    expect($c->refresh()->catatan_tetap)->toBeNull()
        ->and(ActivityLog::query()->where('aksi', 'Ubah catatan tetap')->count())->toBe(2);
});

test('items can change before ACC and the product total follows', function () {
    $order = LogOrder::factory()->create(['order_id' => 'ORD-1', 'total_produk' => 50000, 'ongkir' => 10000]);

    $this->actingAs($this->falah)->put('/pesanan/ORD-1/item', ['item' => [
        ['nama' => 'Molreng Reguler', 'qty' => '10', 'harga' => 'Rp15.000'],
        ['nama' => 'Basreng', 'qty' => '2', 'harga' => '20000'],
    ]])->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->total_produk)->toBe(190000)
        ->and($order->ongkir)->toBe(10000)
        ->and($order->items()->orderBy('urutan')->pluck('subtotal')->all())->toBe([150000, 40000])
        ->and($order->riwayat()->sole()->only(['jenis', 'teks']))->toBe(['jenis' => 'UBAH_ITEM', 'teks' => 'Item diubah oleh Falah dari panel'])
        ->and(ActivityLog::query()->sole()->detail)->toBe('ORD-1 · total produk Rp190.000');
});

test('items cannot change after ACC, need one line, and need the right', function () {
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['order_id' => 'ORD-1', 'invoice_number' => 'INV/1']);
    LogOrder::factory()->create(['order_id' => 'ORD-2']);
    $baris = ['item' => [['nama' => 'X', 'qty' => '1', 'harga' => '1000']]];

    $this->actingAs($this->falah)->put('/pesanan/ORD-1/item', $baris)->assertSessionHasErrors(['item' => 'Item hanya bisa diubah sebelum ACC; invoice sudah dibuat.']);
    $this->actingAs($this->falah)->put('/pesanan/ORD-2/item', ['item' => []])->assertSessionHasErrors(['item' => 'Order harus punya paling sedikit satu item.']);
    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->put('/pesanan/ORD-2/item', $baris)
        ->assertSessionHasErrors(['item' => 'Peran Anda tidak bisa mengubah item order.']);
});
