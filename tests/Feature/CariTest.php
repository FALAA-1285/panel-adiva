<?php

use App\Enums\PengirimPesan;
use App\Enums\StatusOrder;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\Pesan;
use App\Models\User;

/*
 * Suggestions while typing in the top bar: customers, their chats and orders, so a
 * name leads straight to the chat or the order.
 */

test('guests get nothing', function () {
    $this->getJson('/cari?q=rina')->assertUnauthorized();
});

test('a name finds the customer, the chat and the order', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'nomor_wa' => '6281233450081']);
    Pesan::create(['crm_customer_id' => $rina->id, 'chat_id' => $rina->chat_id, 'waktu' => now(), 'pengirim' => PengirimPesan::Customer, 'teks' => 'halo']);
    LogOrder::factory()->status(StatusOrder::MenungguKonfirmasi)->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id, 'nama_penerima' => 'Rina Maharani']);
    CrmCustomer::factory()->create(['nama' => 'Budi']);

    $this->actingAs(User::factory()->create())
        ->getJson('/cari?q=rina')
        ->assertOk()
        ->assertJsonCount(1, 'customer')
        ->assertJsonPath('customer.0.judul', 'Rina Maharani')
        ->assertJsonPath('customer.0.sub', 'Belum jelas · 0812-3345-••81')
        ->assertJsonPath('chat.0.href', "/percakapan?pilih={$rina->id}")
        ->assertJsonPath('pesanan.0.judul', 'ORD-260928-014')
        ->assertJsonPath('pesanan.0.href', '/pesanan/ORD-260928-014');
});

test('an order number and a phone number are found too; one letter is not enough', function () {
    CrmCustomer::factory()->create(['nama' => 'Sari', 'nomor_wa' => '6285712345678']);
    LogOrder::factory()->create(['order_id' => 'ORD-260927-031']);

    $staf = User::factory()->create();

    $this->actingAs($staf)->getJson('/cari?q=ord-260927')->assertJsonPath('pesanan.0.judul', 'ORD-260927-031');
    $this->actingAs($staf)->getJson('/cari?q=0857123')->assertJsonPath('customer.0.judul', 'Sari');
    $this->actingAs($staf)->getJson('/cari?q=s')->assertExactJson(['customer' => [], 'chat' => [], 'pesanan' => []]);
});
