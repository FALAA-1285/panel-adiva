<?php

use App\Enums\KategoriAktivitas;
use App\Enums\StatusBroadcast;
use App\Enums\StatusOrder;
use App\Models\ActivityLog;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A campaign with recipients described as [status, dibaca, dibalas_at, balasan, order, stop].
 *
 * @param  array<string, mixed>  $isi
 * @param  list<array<string, mixed>>  $penerima
 */
function kampanye(array $isi, array $penerima = []): Broadcast
{
    $b = Broadcast::create(['nama' => 'Kampanye', 'pesan' => 'Halo', 'jumlah_target' => count($penerima), ...$isi]);

    foreach ($penerima as $p) {
        $c = $p['customer'] ?? CrmCustomer::factory()->create();
        $status = $p['status'] ?? BroadcastPenerima::TERKIRIM;
        BroadcastPenerima::create([
            'broadcast_id' => $b->id,
            'crm_customer_id' => $c->id,
            'chat_id' => $c->chat_id,
            'status' => $status,
            'terkirim_at' => $status === BroadcastPenerima::TERKIRIM ? ($b->mulai_at ?? now()) : null,
            'dibaca_at' => ($p['dibaca'] ?? false) ? now() : null,
            'dibalas_at' => $p['dibalas'] ?? null,
            'balasan' => $p['balasan'] ?? null,
            'log_order_id' => $p['order'] ?? null,
            'minta_stop' => $p['stop'] ?? false,
            'naik_tahap' => $p['naik'] ?? false,
            'jadi_hot' => $p['hot'] ?? false,
            'keterangan_gagal' => $p['gagal'] ?? null,
        ]);
    }

    return $b;
}

test('guests are sent to the login page and staff without access are refused', function () {
    $this->get('/broadcast')->assertRedirect('/masuk');
    $this->actingAs(User::factory()->create())->get('/broadcast')->assertForbidden();
});

test('the figures count campaigns started this month', function () {
    $order = LogOrder::factory()->create(['total_produk' => 1_200_000, 'ongkir' => 50_000]);
    kampanye(['status' => StatusBroadcast::Selesai, 'mulai_at' => '2026-09-27 10:00', 'selesai_at' => '2026-09-27 10:21'], [
        ['dibalas' => '2026-09-27 10:42', 'order' => $order->id],
        ['dibalas' => '2026-09-27 11:31', 'stop' => true],
        [], [],
        ['status' => BroadcastPenerima::GAGAL],
    ]);
    // last month: not counted
    kampanye(['status' => StatusBroadcast::Selesai, 'mulai_at' => '2026-08-20 10:00', 'selesai_at' => '2026-08-20 11:00'], [['dibalas' => '2026-08-20 10:30']]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/broadcast')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('broadcast')
            ->where('angka', [
                ['label' => 'Terkirim bulan ini', 'nilai' => '4', 'meta' => 'dari 5 target · 1 gagal'],
                ['label' => 'Tingkat balas', 'nilai' => '50,0%', 'meta' => '2 customer membalas'],
                ['label' => 'Order dari broadcast', 'nilai' => '1', 'meta' => 'Rp1,3 jt · dalam 7 hari setelah kirim'],
                ['label' => 'Opt-out', 'nilai' => '25,0%', 'meta' => '1 orang balas STOP · otomatis dikecualikan'],
            ])
            ->where('aturan.0', ['label' => 'Jeda antar pesan', 'nilai' => 'acak 15–45 dtk']));
});

test('the list shows the newest campaigns and hides those finished over a week ago', function () {
    $lama = kampanye(['nama' => 'Lama', 'status' => StatusBroadcast::Selesai, 'mulai_at' => '2026-09-10 10:00', 'selesai_at' => '2026-09-10 11:00']);
    $selesai = kampanye(['nama' => 'Restock', 'status' => StatusBroadcast::Selesai, 'jadwal_at' => '2026-09-27 10:00', 'mulai_at' => '2026-09-27 10:00', 'selesai_at' => '2026-09-27 10:21', 'jumlah_target' => 3], [
        ['dibalas' => '2026-09-27 10:42'], ['order' => LogOrder::factory()->create()->id], [],
    ]);
    $mengirim = kampanye(['nama' => 'Promo', 'status' => StatusBroadcast::Mengirim, 'jadwal_at' => '2026-09-28 13:00', 'mulai_at' => '2026-09-28 13:00'], [
        [], [], [], [], [], ['status' => BroadcastPenerima::MENUNGGU], ['status' => BroadcastPenerima::MENUNGGU], ['status' => BroadcastPenerima::MENUNGGU],
    ]);
    $draf = kampanye(['nama' => 'Draf', 'status' => StatusBroadcast::Draf, 'jumlah_target' => 1205]);
    foreach ([$lama, $selesai, $mengirim, $draf] as $i => $b) {
        $b->forceFill(['created_at' => now()->subDays(4 - $i)])->save();
    }

    $falah = User::factory()->falah()->create();
    $this->actingAs($falah)
        ->get('/broadcast')
        ->assertInertia(fn (Assert $page) => $page
            ->has('daftar', 3)
            ->where('daftar.0', [
                'id' => $draf->id, 'nama' => 'Draf', 'segmen' => null, 'target' => '1.205',
                'status' => ['label' => 'Draf', 'nada' => 'gray'], 'progres' => null, 'hasil' => '—', 'jadwal' => '—',
            ])
            ->where('daftar.1.status', ['label' => 'Mengirim 62%', 'nada' => 'blue'])
            ->where('daftar.1.progres', 62)
            ->where('daftar.1.jadwal', 'Hari ini 13:00')
            ->where('daftar.2.hasil', '1 · 1 order')
            ->where('daftar.2.jadwal', '27 Sep 10:00')
            ->where('laporan.id', $draf->id));

    $nama = fn (string $url) => collect($this->actingAs($falah)->get($url)->viewData('page')['props']['daftar'])->pluck('nama')->all();
    expect($nama('/broadcast?status=SELESAI'))->toBe(['Restock'])
        ->and($nama('/broadcast?status=MENGIRIM'))->toBe(['Promo']);
});

test('the report follows the campaign picked', function () {
    $maya = CrmCustomer::factory()->create(['nama' => 'Maya Kusuma']);
    $tsr = CrmCustomer::factory()->create(['nama' => 'Toko Sumber Rejeki']);
    $andika = CrmCustomer::factory()->create(['nama' => 'Andika Putra']);
    $restock = kampanye(['nama' => 'Restock Kripca untuk reseller', 'segmen_keterangan' => 'Reseller', 'status' => StatusBroadcast::Selesai, 'mulai_at' => '2026-09-27 10:00', 'selesai_at' => '2026-09-27 10:21', 'jumlah_target' => 40], [
        ['dibaca' => true, 'dibalas' => '2026-09-27 10:10', 'balasan' => 'Lama', 'naik' => true],
        ['customer' => $maya, 'dibaca' => true, 'dibalas' => '2026-09-27 10:42', 'balasan' => 'Kak aku ambil Kripca', 'order' => LogOrder::factory()->create()->id, 'naik' => true, 'hot' => true],
        ['customer' => $tsr, 'dibaca' => true, 'dibalas' => '2026-09-27 11:05', 'balasan' => 'Harga 30 pcs masih sama kak?'],
        ['customer' => $andika, 'dibalas' => '2026-09-27 11:31', 'balasan' => 'STOP', 'stop' => true],
        // replied without a stored message: not listed
        ['dibalas' => '2026-09-27 11:40'],
        ['status' => BroadcastPenerima::GAGAL, 'gagal' => 'nomor tidak aktif'],
    ]);
    $promo = kampanye(['status' => StatusBroadcast::Mengirim, 'mulai_at' => '2026-09-28 13:00', 'jumlah_target' => 48], [
        ...array_fill(0, 18, ['status' => BroadcastPenerima::MENUNGGU]),
    ]);
    $dijeda = kampanye(['status' => StatusBroadcast::Dijeda, 'catatan' => 'Dijeda otomatis: 5 kiriman gagal beruntun (WAHA terputus).']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get("/broadcast?pilih={$restock->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('laporan.corong.0', ['label' => 'Target', 'n' => '40', 'judul' => 'Target · 40 (100%)', 'lebar' => 100])
            ->where('laporan.corong.1', ['label' => 'Terkirim', 'n' => '5', 'judul' => 'Terkirim · 5 (13%)', 'lebar' => 13])
            ->where('laporan.corong.4', ['label' => 'Order', 'n' => '1', 'judul' => 'Order · 1 (3%)', 'lebar' => 3])
            ->where('laporan.catatan', '1 gagal: nomor tidak aktif. Pengiriman 10.00–10.21 dengan jeda acak 15–45 detik.')
            ->where('laporan.dampak', ['naik' => 2, 'hot' => 1, 'stop' => 1])
            ->where('laporan.balasan', [
                ['nama' => 'Maya Kusuma', 'jam' => '10:42', 'teks' => 'Kak aku ambil Kripca'],
                ['nama' => 'Toko Sumber Rejeki', 'jam' => '11:05', 'teks' => 'Harga 30 pcs masih sama kak?'],
                ['nama' => 'Andika Putra', 'jam' => '11:31', 'teks' => 'STOP'],
            ])
            ->where('laporan.bisaKirimUlang', true));

    $this->actingAs($falah)
        ->get("/broadcast?pilih={$promo->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('laporan.catatan', 'Sedang berjalan · 18 pesan tersisa · estimasi selesai 13:51 · tetap patuh batas 300/hari.')
            ->where('laporan.corong.1.lebar', 0));

    $this->actingAs($falah)
        ->get("/broadcast?pilih={$dijeda->id}")
        ->assertInertia(fn (Assert $page) => $page->where('laporan.catatan', 'Dijeda otomatis: 5 kiriman gagal beruntun (WAHA terputus).'));
});

test('failed recipients are queued again', function () {
    $b = kampanye(['nama' => 'Paket reseller pemula', 'status' => StatusBroadcast::Dijeda, 'mulai_at' => now(), 'catatan' => 'Dijeda otomatis'], [
        [], ['status' => BroadcastPenerima::GAGAL, 'gagal' => 'WAHA terputus'], ['status' => BroadcastPenerima::GAGAL, 'gagal' => 'WAHA terputus'],
    ]);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post("/broadcast/{$b->id}/kirim-ulang")->assertRedirect();

    expect($b->refresh()->status)->toBe(StatusBroadcast::Mengirim)
        ->and($b->catatan)->toBeNull()
        ->and($b->penerima()->where('status', BroadcastPenerima::MENUNGGU)->count())->toBe(2)
        ->and(ActivityLog::query()->get(['kategori', 'aksi', 'detail'])->toArray())->toBe([
            ['kategori' => KategoriAktivitas::Broadcast->value, 'aksi' => 'Kirim ulang broadcast', 'detail' => 'Paket reseller pemula · 2 penerima gagal'],
        ]);

    $this->actingAs($falah)->post("/broadcast/{$b->id}/kirim-ulang")->assertSessionHasErrors('broadcast');
});

test('the recipients are exported as CSV', function () {
    $maya = CrmCustomer::factory()->create(['nama' => 'Maya Kusuma', 'chat_id' => '6281944550004@c.us']);
    $order = LogOrder::factory()->status(StatusOrder::Dikirim)->create(['order_id' => 'ORD-260925-004']);
    $b = kampanye(['nama' => 'Restock Kripca untuk reseller', 'mulai_at' => '2026-09-27 10:00'], [
        ['customer' => $maya, 'dibalas' => '2026-09-27 10:42', 'balasan' => 'Kak aku ambil', 'order' => $order->id],
    ]);

    $csv = $this->actingAs(User::factory()->falah()->create())
        ->get("/broadcast/{$b->id}/ekspor")
        ->assertDownload('broadcast-restock-kripca-untuk-reseller.csv')
        ->streamedContent();

    expect($csv)->toContain('"Maya Kusuma",6281944550004,TERKIRIM,"2026-09-27 10:00",,"2026-09-27 10:42","Kak aku ambil",ORD-260925-004');
});
