<?php

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\User;
use App\Support\NomorBot;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ConfigToko::create(['kunci' => 'DAFTAR_NOMOR_BOT', 'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2']);
});

test('bot numbers have a full label and a short name', function () {
    ConfigToko::query()->where('kunci', 'DAFTAR_NOMOR_BOT')->update(['nilai' => 'bangbana-waha=Nomor Utama=utama, bangbana-3=Nomor Batu']);

    expect(NomorBot::daftar())->toBe(['bangbana-waha' => 'Nomor Utama', 'bangbana-3' => 'Nomor Batu'])
        ->and(NomorBot::singkat())->toBe(['bangbana-waha' => 'utama', 'bangbana-3' => 'Nomor Batu']);
});

test('only staff who answer chats open Ambil alih chat', function () {
    $this->get('/jeda')->assertRedirect('/masuk');

    $pj = User::factory()->create(['peran' => Peran::PjKaryawan]);
    $this->actingAs($pj)->get('/jeda')->assertForbidden();
    $this->actingAs($pj)->post('/jeda', [])->assertForbidden();
});

test('paused customers show how long the pause still runs', function () {
    foreach ([
        ['Bu Wati', '6281355660042', 'bangbana-2', '2026-09-28 10:05', '2026-09-28 14:30', true],
        ['Toko Berkah Jaya', '6285744100036', 'bangbana-waha', '2026-09-27 15:00', null, true],
        ['Mbak Lia', '6282290310018', 'bangbana-waha', '2026-09-28 12:40', '2026-09-28 13:40', false],
    ] as [$nama, $nomor, $sesi, $sejak, $sampai, $dikabari]) {
        JedaBot::create(['nama' => $nama, 'nomor' => $nomor, 'chat_id' => $nomor.'@c.us', 'sesi_wa' => $sesi, 'sejak' => $sejak, 'sampai' => $sampai, 'oleh' => 'Falah', 'sudah_diberi_tahu' => $dikabari]);
    }

    $this->actingAs(User::factory()->falah()->create())
        ->get('/jeda')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jeda')
            ->where('aktif.0.nomor', '0813-5566-••42')
            ->where('aktif.0.sesi', 'CS 2')
            ->where('aktif.0.sejak', '10:05')
            ->where('aktif.0.sampai', '14:30')
            ->where('aktif.0.sisa', '± 50 mnt lagi')
            ->where('aktif.0.kabar', 'Customer sudah dikabari')
            ->where('aktif.1.sejak', 'Kemarin')
            ->where('aktif.1.sampai', '∞')
            ->where('aktif.1.sisa', 'tanpa batas · diingatkan 09.00')
            // Mbak Lia ran out at 13:40: Adiva already answers her, so she is not paused
            ->has('aktif', 2)
            ->where('ringkasan.0', ['label' => 'Sedang dijeda', 'nilai' => '2', 'meta' => '1 tanpa batas waktu'])
            // …and lands in the history, ended by Sistem at her end time
            ->where('riwayat.0.nama', 'Mbak Lia')
            ->where('riwayat.0.oleh', 'Sistem')
            ->where('riwayat.0.durasi', '60 mnt'));
});

test('a pause that ran out is not counted anywhere as still holding the bot', function () {
    // past its end time, never closed: the customer has not written since
    $lewat = JedaBot::create(['nama' => 'Bu Wati', 'nomor' => '6281355660042', 'chat_id' => '6281355660042@c.us', 'sejak' => '2026-09-28 10:05', 'sampai' => '2026-09-28 11:00', 'oleh' => 'Falah']);

    expect(JedaBot::query()->menahan()->count())->toBe(0)
        ->and(JedaBot::query()->berakhir()->count())->toBe(1)
        ->and($lewat->akhir()?->format('H:i'))->toBe('11:00');

    // pausing her again closes the old one first instead of leaving two open
    CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'nomor_wa' => '6281355660042', 'chat_id' => '6281355660042@c.us']);
    $this->actingAs(User::factory()->falah()->create())
        ->post('/jeda', ['customer' => 'bu wati', 'durasi' => '1 jam'])
        ->assertSessionHasNoErrors();

    expect(JedaBot::query()->aktif()->count())->toBe(1)
        ->and($lewat->refresh()->selesai_at?->format('H:i'))->toBe('11:00')
        ->and($lewat->diakhiri_oleh)->toBe('Sistem');
});

test('the history covers 3 days and the numbers 30 and 7 days', function () {
    $selesai = fn (string $nama, string $sejak, string $akhir, string $oleh) => JedaBot::create([
        'nama' => $nama, 'chat_id' => $nama.'@c.us', 'sejak' => $sejak, 'selesai_at' => $akhir, 'diakhiri_oleh' => $oleh, 'alasan' => 'Nego harga', 'catatan_selesai' => 'catatan',
    ]);
    $selesai('Rudi Hartono', '2026-09-27 14:02', '2026-09-27 15:10', 'Falah');
    $selesai('Toko Makmur', '2026-09-26 13:00', '2026-09-27 09:00', 'Sistem');
    // older than 3 days: counted, not listed
    $selesai('Lama', '2026-09-22 10:00', '2026-09-22 10:32', 'Sistem');
    // older than 30 days: not counted
    $selesai('Sangat lama', '2026-08-01 10:00', '2026-08-01 12:00', 'Sistem');

    $this->actingAs(User::factory()->falah()->create())
        ->get('/jeda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('riwayat', fn ($r) => collect($r)->pluck('nama')->all() === ['Rudi Hartono', 'Toko Makmur'])
            ->where('riwayat.0.waktu', '27 Sep 14:02 → 15:10')
            ->where('riwayat.0.durasi', '68 mnt')
            ->where('riwayat.1.waktu', '26 Sep 13:00 → 27 Sep 09:00')
            ->where('riwayat.1.durasi', '1.200 mnt')
            // (68 + 1200 + 32) / 3
            ->where('ringkasan.1', ['label' => 'Rata-rata durasi jeda', 'nilai' => '433 mnt', 'meta' => '30 hari terakhir · 3 sesi'])
            ->where('ringkasan.2.nilai', '2'));
});

test('"Pakai chat terakhir" skips a customer who is paused', function () {
    $nisa = CrmCustomer::factory()->create(['nama' => 'Nisa Aulia', 'tgl_interaksi_terakhir' => '2026-09-28 13:21']);
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'tgl_interaksi_terakhir' => '2026-09-28 13:30']);
    JedaBot::create(['nama' => 'Rina Maharani', 'chat_id' => $rina->chat_id, 'sejak' => '2026-09-28 13:31']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/jeda')
        ->assertInertia(fn (Assert $page) => $page->where('terakhir', ['nama' => 'Nisa Aulia', 'jam' => '13:21']));
});

test('JEDA pauses a customer found by name or number', function () {
    $wati = CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'nomor_wa' => '6281355660042', 'sesi_wa' => 'bangbana-2']);
    $nisa = CrmCustomer::factory()->create(['nama' => 'Nisa Aulia', 'nomor_wa' => '6281277000055']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post('/jeda', ['customer' => 'bu wati', 'durasi' => '1 jam', 'alasan' => 'komplain kemasan bocor'])
        ->assertSessionHasNoErrors();
    $this->actingAs($falah)->post('/jeda', ['customer' => '0812 7700 0055', 'durasi' => 'Tanpa batas'])
        ->assertSessionHasNoErrors();

    $jeda = JedaBot::query()->where('chat_id', $wati->chat_id)->sole();

    expect($jeda->only(['nama', 'nomor', 'sesi_wa', 'oleh', 'alasan']))->toBe([
        'nama' => 'Bu Wati', 'nomor' => '6281355660042', 'sesi_wa' => 'bangbana-2', 'oleh' => 'Falah', 'alasan' => 'komplain kemasan bocor',
    ])
        ->and($jeda->sampai?->format('H:i'))->toBe('14:42')
        ->and(JedaBot::query()->where('chat_id', $nisa->chat_id)->sole()->sampai)->toBeNull()
        ->and(ActivityLog::query()->orderBy('id')->get(['kategori', 'aksi', 'detail'])->toArray())->toBe([
            ['kategori' => KategoriAktivitas::Jeda->value, 'aksi' => 'JEDA customer', 'detail' => 'Bu Wati sampai 14:42 · komplain kemasan bocor'],
            ['kategori' => KategoriAktivitas::Jeda->value, 'aksi' => 'JEDA customer', 'detail' => 'Nisa Aulia tanpa batas'],
        ]);

    // already paused, and someone the CRM does not know
    $this->actingAs($falah)->post('/jeda', ['customer' => 'Bu Wati', 'durasi' => '1 jam'])
        ->assertSessionHasErrors(['customer' => 'Customer ini sedang dijeda.']);
    $this->actingAs($falah)->post('/jeda', ['customer' => 'Siapa', 'durasi' => '1 jam'])
        ->assertSessionHasErrors(['customer' => 'Customer tidak ditemukan di CRM.']);
});

test('LANJUT ends the pause with the hand-over note', function () {
    $wati = CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'catatan_tetap' => 'Guru SD']);
    $jeda = JedaBot::create(['crm_customer_id' => $wati->id, 'nama' => 'Bu Wati', 'chat_id' => $wati->chat_id, 'sejak' => '2026-09-28 10:05']);
    $lain = JedaBot::create(['nama' => 'Sari', 'chat_id' => 'sari@c.us', 'sejak' => '2026-09-28 11:00']);
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)->post("/jeda/{$jeda->id}/lanjut", ['catatan' => 'kompensasi 2 bungkus disepakati'])->assertRedirect();
    $this->actingAs($falah)->post("/jeda/{$lain->id}/lanjut")->assertRedirect();

    expect($jeda->refresh()->only(['diakhiri_oleh', 'catatan_selesai']))->toBe(['diakhiri_oleh' => 'Falah', 'catatan_selesai' => 'kompensasi 2 bungkus disepakati'])
        ->and($jeda->selesai_at?->format('H:i'))->toBe('13:42')
        // added under the standing note the admin already wrote
        ->and($wati->refresh()->catatan_tetap)->toBe("Guru SD\nkompensasi 2 bungkus disepakati")
        ->and($lain->refresh()->catatan_selesai)->toBe('Bot diaktifkan lagi dari panel')
        ->and(ActivityLog::query()->orderBy('id')->pluck('detail')->all())->toBe(['Bu Wati · kompensasi 2 bungkus disepakati', 'Sari']);

    // ended already
    $this->actingAs($falah)->post("/jeda/{$jeda->id}/lanjut")->assertNotFound();
});

test('JEDA accepts custom jumlah and satuan (menit, jam, detik) or tanpa batas boolean', function () {
    $this->travelTo('2026-09-28 13:42:00');
    $budi = CrmCustomer::factory()->create(['nama' => 'Budi Santoso', 'nomor_wa' => '6281122334455']);
    $rudi = CrmCustomer::factory()->create(['nama' => 'Rudi Hartono', 'nomor_wa' => '6281122334466']);
    $sari = CrmCustomer::factory()->create(['nama' => 'Sari Indah', 'nomor_wa' => '6281122334477']);
    $falah = User::factory()->falah()->create();

    // 45 detik
    $this->actingAs($falah)->post('/jeda', [
        'customer' => 'Budi Santoso',
        'jumlah' => 45,
        'satuan' => 'detik',
        'alasan' => 'tunggu sebentar',
    ])->assertSessionHasNoErrors();

    // 2 jam
    $this->actingAs($falah)->post('/jeda', [
        'customer' => 'Rudi Hartono',
        'jumlah' => 2,
        'satuan' => 'jam',
        'alasan' => 'cek stok gudang',
    ])->assertSessionHasNoErrors();

    // tanpa_batas boolean
    $this->actingAs($falah)->post('/jeda', [
        'customer' => 'Sari Indah',
        'tanpa_batas' => true,
    ])->assertSessionHasNoErrors();

    $jedaBudi = JedaBot::query()->where('chat_id', $budi->chat_id)->sole();
    $jedaRudi = JedaBot::query()->where('chat_id', $rudi->chat_id)->sole();
    $jedaSari = JedaBot::query()->where('chat_id', $sari->chat_id)->sole();

    expect($jedaBudi->sampai?->format('H:i:s'))->toBe('13:42:45')
        ->and($jedaRudi->sampai?->format('H:i'))->toBe('15:42')
        ->and($jedaSari->sampai)->toBeNull();

    // validation fails if jumlah 0 and not tanpa batas
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina']);
    $this->actingAs($falah)->post('/jeda', [
        'customer' => 'Rina',
        'jumlah' => 0,
        'satuan' => 'menit',
        'tanpa_batas' => false,
    ])->assertSessionHasErrors(['durasi']);
});
