<?php

use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Jobs\KirimPesanWa;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Pesan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach (['DAFTAR_NOMOR_BOT' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2', 'JEDA_FOLLOWUP_JAM' => '10'] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }
});

/**
 * @param  array<string, mixed>  $isi
 */
function tulis(CrmCustomer $c, PengirimPesan $pengirim, string $teks, string $waktu, array $isi = []): Pesan
{
    return Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => $waktu, 'pengirim' => $pengirim, 'teks' => $teks, ...$isi]);
}

test('guests are sent to the login page', function () {
    $this->get('/percakapan')->assertRedirect('/masuk');
});

test('the list shows who wrote, latest message first, with what needs attention', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'skor_prospek' => SkorProspek::Hot, 'tahap_funnel' => TahapFunnel::OrderDibuat]);
    LogOrder::factory()->create(['crm_customer_id' => $rina->id]);
    tulis($rina, PengirimPesan::Customer, 'JNE aja kak', '2026-09-28 13:06');
    $hendra = CrmCustomer::factory()->create(['nama' => 'Pak Hendra', 'butuh_admin' => true, 'tahap_funnel' => TahapFunnel::Nego]);
    tulis($hendra, PengirimPesan::Customer, 'bisa harga distributor?', '2026-09-28 12:47');
    tulis($hendra, PengirimPesan::Adiva, 'Sudah diteruskan ke admin', '2026-09-28 12:48');
    $wati = CrmCustomer::factory()->create(['nama' => 'Bu Wati']);
    tulis($wati, PengirimPesan::Customer, 'ditunggu kabarnya', '2026-09-28 11:52');
    JedaBot::create(['chat_id' => $wati->chat_id, 'sejak' => '2026-09-28 10:05', 'sampai' => '2026-09-28 14:30', 'oleh' => 'Falah']);
    $yoga = CrmCustomer::factory()->create(['nama' => 'Yoga', 'tipe_customer' => TipeCustomer::Karyawan]);
    LogOrder::factory()->karyawan('6289612000077')->create(['crm_customer_id' => $yoga->id]);
    tulis($yoga, PengirimPesan::Customer, 'potong gaji aja', '2026-09-28 10:31');
    User::factory()->create(['name' => 'Bu Sarah', 'peran' => Peran::PjKaryawan]);
    $lama = CrmCustomer::factory()->create(['nama' => null, 'nomor_wa' => '6287800415120']);
    tulis($lama, PengirimPesan::Customer, 'kak', '2026-09-26 09:13');
    // only Adiva wrote to this one: not a conversation
    tulis(CrmCustomer::factory()->create(), PengirimPesan::Adiva, 'Halo kak', '2026-09-28 13:30');
    // waiting for an admin without a chat: only under "Butuh admin"
    CrmCustomer::factory()->create(['nama' => 'Customer demo 1', 'butuh_admin' => true, 'tgl_interaksi_terakhir' => '2026-09-20 09:00']);

    $falah = User::factory()->falah()->create();
    $this->actingAs($falah)
        ->get('/percakapan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('percakapan')
            ->where('jumlah', ['semua' => 5, 'butuh-admin' => 2, 'dijeda' => 1])
            ->where('daftar.0', [
                'id' => $rina->id, 'inisial' => 'RM', 'nama' => 'Rina Maharani', 'waktu' => '13:06', 'pratinjau' => 'JNE aja kak',
                'skor' => 'HOT', 'tahap' => 'Order dibuat', 'tanda' => ['teks' => 'Menunggu ACC', 'nada' => 'amber'],
            ])
            ->where('daftar.1.waktu', '12:48')
            ->where('daftar.1.pratinjau', 'bisa harga distributor?')
            ->where('daftar.1.tanda', ['teks' => 'Butuh admin', 'nada' => 'red'])
            ->where('daftar.2.tanda', ['teks' => 'Dijeda s/d 14:30', 'nada' => 'gray'])
            ->where('daftar.3.nama', 'Yoga (Karyawan)')
            ->where('daftar.3.tanda', ['teks' => 'Ke Bu Sarah', 'nada' => 'gray'])
            ->where('daftar.4', [
                'id' => $lama->id, 'inisial' => '?', 'nama' => '+62 878-••••-5120', 'waktu' => '26 Sep', 'pratinjau' => 'kak',
                'skor' => 'COLD', 'tahap' => 'Baru', 'tanda' => ['teks' => 'Bot aktif', 'nada' => 'blue'],
            ])
            ->where('percakapan.id', $rina->id));

    $nama = fn (string $url) => collect($this->actingAs($falah)->get($url)->viewData('page')['props']['daftar'])->pluck('nama')->all();
    expect($nama('/percakapan?tab=butuh-admin'))->toBe(['Pak Hendra', 'Customer demo 1'])
        ->and($nama('/percakapan?tab=dijeda'))->toBe(['Bu Wati'])
        ->and($nama('/percakapan?cari=yoga'))->toBe(['Yoga (Karyawan)']);
});

test('the thread and what Adiva knows about the customer', function () {
    $rina = CrmCustomer::factory()->create([
        'nama' => 'Rina Maharani', 'nomor_wa' => '6281233450081', 'sesi_wa' => 'bangbana-waha', 'tipe_customer' => TipeCustomer::Reseller,
        'tahap_funnel' => TahapFunnel::OrderDibuat, 'skor_prospek' => SkorProspek::Hot, 'kota' => 'Malang', 'minat_produk' => 'Molreng, Basreng',
        'lokasi_jualan' => 'Kantin sekolah', 'estimasi_modal' => 1500000, 'ringkasan_terakhir' => 'Reseller pemula.', 'catatan_tetap' => 'Guru SD.',
    ]);
    $falah = User::factory()->falah()->create();
    tulis($rina, PengirimPesan::Customer, 'kak mau tanya', '2026-09-28 13:02');
    tulis($rina, PengirimPesan::Sistem, '3 pesan beruntun digabung', '2026-09-28 13:02:01');
    tulis($rina, PengirimPesan::Adiva, 'Molreng Reguler', '2026-09-28 13:03', ['lampiran_keterangan' => 'Foto produk · Molreng', 'tombol' => ['Mau order']]);
    tulis($rina, PengirimPesan::Admin, 'Saya Falah', '2026-09-28 13:04', ['user_id' => $falah->id]);
    LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id, 'total_produk' => 1245000, 'ongkir' => 38000]);
    LogOrder::factory()->status(StatusOrder::Selesai)->create(['crm_customer_id' => $rina->id, 'tanggal' => '2026-09-01']);

    $this->actingAs($falah)
        ->get("/percakapan?pilih={$rina->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('percakapan.nomor', '0812-3345-••81')
            ->where('percakapan.status', ['label' => 'Dijawab Adiva', 'nada' => 'blue'])
            ->where('percakapan.pesan.0', ['id' => Pesan::query()->orderBy('id')->value('id'), 'jenis' => 'customer', 'oleh' => null, 'teks' => 'kak mau tanya', 'jam' => '13:02', 'foto' => null, 'lampiran' => null, 'tombol' => [], 'kirim' => null])
            ->where('percakapan.pesan.1.jenis', 'sistem')
            ->where('percakapan.pesan.2.oleh', 'Adiva · AI')
            ->where('percakapan.pesan.2.foto', 'Foto produk · Molreng')
            ->where('percakapan.pesan.2.tombol', ['Mau order'])
            ->where('percakapan.pesan.3.oleh', 'Admin · Falah')
            ->where('percakapan.petunjuk', 'Membalas manual akan menjeda Adiva untuk customer ini selama 2 jam.')
            ->where('percakapan.isian', [
                ['Tipe', 'Reseller'], ['Tahap', 'Order dibuat'], ['Skor', 'HOT'], ['Kota', 'Malang'], ['Minat produk', 'Molreng, Basreng'],
                ['Lokasi jualan', 'Kantin sekolah'], ['Estimasi modal', 'Rp1.500.000'], ['Nomor bot', 'Nomor Utama'],
            ])
            ->where('percakapan.catatan', 'Guru SD.')
            // the running order only
            ->where('percakapan.pesanan', [['id' => 'ORD-260928-014', 'total' => 'Rp1.283.000', 'status' => ['label' => 'Menunggu ACC', 'nada' => 'amber']]]));
});

test('the fields follow the customer: prospect, paused, employee', function () {
    $hendra = CrmCustomer::factory()->create(['tipe_customer' => TipeCustomer::Reseller, 'tahap_funnel' => TahapFunnel::Nego, 'butuh_admin' => true, 'alasan_butuh_admin' => 'Nego harga khusus.']);
    tulis($hendra, PengirimPesan::Adiva, 'Sudah diteruskan', '2026-09-28 12:48');
    $wati = CrmCustomer::factory()->create(['tipe_customer' => TipeCustomer::Retail, 'tahap_funnel' => TahapFunnel::TanyaHarga, 'sesi_wa' => 'bangbana-2']);
    $jeda = JedaBot::create(['chat_id' => $wati->chat_id, 'sejak' => '2026-09-28 10:05', 'sampai' => '2026-09-28 14:30', 'oleh' => 'Falah']);
    $yoga = CrmCustomer::factory()->create(['tipe_customer' => TipeCustomer::Karyawan, 'chat_id' => '183920475610245@lid', 'nomor_wa' => '6289612000077']);
    User::factory()->create(['name' => 'Bu Sarah', 'peran' => Peran::PjKaryawan]);
    $falah = User::factory()->falah()->create();
    $isian = fn (CrmCustomer $c) => collect($this->actingAs($falah)->get("/percakapan?pilih={$c->id}")->viewData('page')['props']['percakapan']);

    expect($isian($hendra)['isian'])->toContain(['Sapaan ulang', '22:48 bila diam'])
        ->and($isian($hendra)['alasanAdmin'])->toBe('Nego harga khusus.')
        ->and($isian($hendra)['status'])->toBe(['label' => 'Butuh admin', 'nada' => 'amber'])
        ->and($isian($wati)['isian'])->toBe([['Tipe', 'Retail'], ['Tahap', 'Tanya harga'], ['Skor', 'COLD'], ['Kota', '—'], ['Minat produk', '—'], ['Nomor bot', 'Nomor Lawang'], ['Dijeda oleh', 'Falah'], ['Sampai', '14:30']])
        ->and($isian($wati)['jeda'])->toBe($jeda->id)
        ->and($isian($wati)['petunjuk'])->toBe('Bot sedang dijeda — balasan Anda dikirim langsung lewat WAHA.')
        ->and($isian($yoga)['isian'])->toContain(['Pembayaran', 'Potong gaji'], ['PJ program', 'Bu Sarah'])
        ->and($isian($yoga)['nomor'])->toBe('ID @lid → nomor ditemukan')
        ->and($isian($yoga)['pesanan'])->toBe([]);
});

test('an admin reply is queued and pauses Adiva for 2 hours', function () {
    Queue::fake();
    $c = CrmCustomer::factory()->create(['nama' => 'Pak Hendra']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post("/percakapan/{$c->id}/balas", ['teks' => 'Saya Falah dari Bang Bana'])->assertRedirect();
    $this->actingAs($falah)->post("/percakapan/{$c->id}/balas", ['teks' => 'Harga khusus Rp17.000']);

    expect(Pesan::query()->where('pengirim', PengirimPesan::Admin)->count())->toBe(2)
        ->and(Pesan::query()->first()->status_kirim)->toBe('MENUNGGU')
        ->and(JedaBot::query()->aktif()->sole()->sampai?->format('H:i'))->toBe('15:42')
        ->and(ActivityLog::query()->where('kategori', KategoriAktivitas::Percakapan->value)->pluck('detail')->all())
        ->toBe(['Pak Hendra · Saya Falah dari Bang Bana', 'Pak Hendra · Harga khusus Rp17.000']);
    Queue::assertPushed(KirimPesanWa::class, 2);

    $this->actingAs($falah)->post("/percakapan/{$c->id}/balas", ['teks' => ''])->assertSessionHasErrors(['teks' => 'Tulis balasannya dulu.']);
    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))->post("/percakapan/{$c->id}/balas", ['teks' => 'Halo'])->assertForbidden();
});

test('resetting the chat memory starts a new session', function () {
    $c = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'mem_gen' => 3]);

    $this->actingAs(User::factory()->falah()->create())->post("/percakapan/{$c->id}/reset-memori")->assertRedirect();

    expect($c->refresh()->mem_gen)->toBe(4)
        ->and(Pesan::query()->sole()->teks)->toBe('Memori chat direset oleh Falah · sesi ingatan ke-4')
        ->and(ActivityLog::query()->sole()->aksi)->toBe('Reset memori chat');
});
