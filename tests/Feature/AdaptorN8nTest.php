<?php

use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Models\Riwayat;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The SQL layer the n8n workflows read and write through (docs/kontrak-n8n.md §8).
 */

test('helpers read the Data Table formats', function () {
    $r = DB::selectOne(<<<'SQL'
        select n8n_waktu('2026-10-08 13:42') = '2026-10-08 06:42+00'::timestamptz as wib,
            n8n_waktu('2026-10-08T06:42:00.000Z') = '2026-10-08 06:42+00'::timestamptz as iso,
            n8n_waktu('') is null as kosong, n8n_waktu('besok') is null as ngawur,
            n8n_wib('2026-10-08 06:42+00') as teks,
            n8n_angka('Rp35.000') as rp, n8n_angka('5 jt') as juta, n8n_angka('25rb') as ribu, n8n_angka('1,5 juta') as koma,
            n8n_desimal('1,6 kg') as berat, n8n_nomor('0812-3456-0007') as nol, n8n_nomor('6281234560007@c.us') as cus,
            n8n_ya('YA') as ya, n8n_ya('TIDAK') as tidak
        SQL);

    expect((array) $r)->toBe([
        'wib' => true, 'iso' => true, 'kosong' => true, 'ngawur' => true, 'teks' => '2026-10-08 13:42',
        'rp' => 35000, 'juta' => 5000000, 'ribu' => 25000, 'koma' => 1500000,
        'berat' => '1.6', 'nol' => '6281234560007', 'cus' => '6281234560007', 'ya' => true, 'tidak' => false,
    ]);
});

test('working hours skip the night and days off, like JamKerja::tambah', function () {
    ConfigToko::create(['kunci' => 'JAM_PESAN_OTOMATIS', 'nilai' => '07:00-21:00']);
    ConfigToko::create(['kunci' => 'HARI_LIBUR_OTOMATIS', 'nilai' => 'Minggu']);

    // Saturday 20:00 + 5 hours: 1 hour on Saturday, Sunday off, 4 hours on Monday
    $batas = DB::scalar("select n8n_wib(n8n_tambah_jam_kerja('2026-10-10 20:00+07', 5))");

    expect($batas)->toBe('2026-10-12 11:00');
});

test('saving a CRM row converts the values and keeps the panel columns right', function () {
    $c = DB::selectOne('select * from n8n_simpan_crm(?::jsonb)', [json_encode([
        'chat_id' => '6289999@c.us', 'nama' => 'Rina', 'tahap_funnel' => 'TANYA_HARGA', 'skor_prospek' => 'HOT',
        'tipe_customer' => 'RESELLER', 'estimasi_modal' => '2 juta', 'tgl_interaksi_terakhir' => '2026-10-08 13:42',
        'tgl_followup_berikutnya' => '2026-10-08 23:42', 'status_optout' => 'TIDAK', 'butuh_admin' => 'YA',
        'alasan_butuh_admin' => 'minta nego', 'draft_pesanan' => '{"status":"DISKUSI","items":[]}', 'eskalasi_terbuka' => '',
    ])]);

    expect($c->tahap_funnel)->toBe('TANYA_HARGA')
        ->and($c->tgl_interaksi_terakhir)->toBe('2026-10-08 13:42')
        ->and($c->butuh_admin)->toBe('YA')
        ->and($c->status_optout)->toBe('TIDAK')
        ->and($c->draft_pesanan)->toBe('{"items": [], "status": "DISKUSI"}');

    $crm = CrmCustomer::query()->where('chat_id', '6289999@c.us')->sole();
    expect($crm->estimasi_modal)->toBe(2000000)
        ->and($crm->butuh_admin)->toBeTrue()
        ->and($crm->alasan_butuh_admin)->toBe('minta nego')
        ->and(array_keys($crm->tahap_sejak))->toBe(['TANYA_HARGA']);

    // only the keys sent change; a stage the panel does not know is ignored
    $crm->update(['tahap_manual_oleh_id' => User::factory()->create()->id, 'tahap_manual_at' => now()]);
    DB::select('select * from n8n_simpan_crm(?::jsonb, false)', [json_encode([
        'chat_id' => '6289999@c.us', 'tahap_funnel' => 'DITUTUP', 'tgl_followup_berikutnya' => '', 'butuh_admin' => 'TIDAK',
    ])]);
    $crm->refresh();
    expect($crm->tahap_funnel->value)->toBe('TANYA_HARGA')
        ->and($crm->tahap_manual_oleh_id)->not->toBeNull()
        ->and($crm->tgl_followup_berikutnya)->toBeNull()
        ->and($crm->butuh_admin)->toBeFalse()
        ->and($crm->alasan_butuh_admin)->toBeNull()
        ->and($crm->nama)->toBe('Rina');

    // Adiva moves the stage: the manual mark goes, tahap_sejak grows
    DB::select('select * from n8n_simpan_crm(?::jsonb)', [json_encode(['chat_id' => '6289999@c.us', 'tahap_funnel' => 'SIAP_ORDER'])]);
    $crm->refresh();
    expect($crm->tahap_manual_oleh_id)->toBeNull()
        ->and(array_keys($crm->tahap_sejak))->toEqualCanonicalizing(['TANYA_HARGA', 'SIAP_ORDER']);

    // update-only writes never create a customer
    expect(DB::select('select * from n8n_simpan_crm(?::jsonb, false)', [json_encode(['chat_id' => 'baru@c.us', 'catatan' => 'x'])]))->toBe([]);
});

test('an order from the chatbot gets its items, and status changes fill the panel timestamps', function () {
    ConfigToko::create(['kunci' => 'BATAS_BAYAR_JAM', 'nilai' => '5']);
    User::factory()->falah()->create();
    $crm = CrmCustomer::factory()->create(['chat_id' => '6289999@c.us']);

    $o = DB::selectOne('select * from n8n_catat_order(?::jsonb)', [json_encode([
        'order_id' => 'ORD-UJI-1', 'chat_id' => '6289999@c.us', 'tanggal' => '2026-10-08 13:42', 'items' => 'Basreng (2)',
        'items_detail' => json_encode([['nama' => 'Basreng Pedas', 'qty' => 2, 'harga_satuan' => '15.000'], ['nama' => 'Kripca', 'qty' => '3', 'harga_satuan' => 9000]]),
        'total_produk' => '57000', 'ongkir' => '', 'kurir_pilihan' => 'JNE REG', 'metode_bayar' => 'Transfer Bank',
        'tipe_customer' => 'RETAIL', 'status_order' => 'MENUNGGU_KONFIRMASI', 'berat_kg' => '1,5',
    ])]);

    expect($o->items)->toBe('Basreng Pedas (2), Kripca (3)')
        ->and(json_decode($o->items_detail, true)[1])->toBe(['nama' => 'Kripca', 'qty' => 3, 'harga_satuan' => 9000, 'subtotal' => 27000])
        ->and($o->tanggal)->toBe('2026-10-08 13:42');

    $order = LogOrder::query()->where('order_id', 'ORD-UJI-1')->sole();
    expect($order->crm_customer_id)->toBe($crm->id)
        ->and((int) $order->items()->sum('subtotal'))->toBe(57000)
        ->and($order->berat_kg)->toBe('1.50')
        ->and($order->ambil_sendiri)->toBeFalse();

    // "Tutup Order Lama" only closes it while still waiting
    expect(DB::select('select * from n8n_ubah_order(?::jsonb)', [json_encode(['order_id' => 'ORD-UJI-1', 'status_order' => 'DIBATALKAN', 'status_lama' => 'DIACC'])]))->toBe([]);

    DB::select('select * from n8n_ubah_order(?::jsonb)', [json_encode([
        'order_id' => 'ORD-UJI-1', 'status_order' => 'DIACC', 'ongkir' => '12000', 'invoice_number' => 'INV/2026/10/001',
        'perintah' => 'ACC', 'riwayat' => 'ACC dari WhatsApp', 'riwayat_oleh' => '6281234560007@c.us',
    ])]);
    $order->refresh();
    expect($order->status_order->value)->toBe('DIACC')
        ->and($order->diacc_at)->not->toBeNull()
        ->and($order->batas_bayar_at)->not->toBeNull()
        ->and($order->ongkir)->toBe(12000)
        ->and($order->invoice_number)->toBe('INV/2026/10/001')
        ->and(Riwayat::query()->where('subjek_id', $order->id)->value('teks'))->toBe('ACC dari WhatsApp oleh Falah');

    DB::select('select * from n8n_ubah_order(?::jsonb)', [json_encode(['order_id' => 'ORD-UJI-1', 'status_order' => 'BATAL OTOMATIS'])]);
    DB::select('select * from n8n_ubah_order(?::jsonb)', [json_encode(['order_id' => 'ORD-UJI-1', 'status_order' => 'LUNAS', 'bukti_masuk' => 'YA'])]);
    $order->refresh();
    expect($order->status_order->value)->toBe('LUNAS')
        ->and($order->lunas_at)->not->toBeNull()
        ->and($order->bukti_masuk_at)->not->toBeNull();

    // the same order id again does not make a second order
    DB::select('select * from n8n_catat_order(?::jsonb)', [json_encode(['order_id' => 'ORD-UJI-1', 'chat_id' => '6289999@c.us'])]);
    expect(LogOrder::query()->count())->toBe(1);
});

test('a pause is kept as history: LANJUT and expiry end it instead of deleting it', function () {
    User::factory()->create(['name' => 'Dina', 'nomor_wa' => '6281234560007']);

    $j = DB::selectOne('select * from n8n_jeda(?::jsonb)', [json_encode([
        'chat_id' => '6289999@c.us', 'nomor' => '089999', 'nama' => 'Rina', 'oleh' => '6281234560007@c.us',
        'alasan' => 'nego', 'sampai' => '2026-10-08 15:42',
    ])]);
    expect($j->sampai)->toBe('2026-10-08 15:42')->and($j->oleh)->toBe('Dina')->and($j->sudah_diberi_tahu)->toBe('');

    // JEDA again on the running pause changes it
    DB::select('select * from n8n_jeda(?::jsonb)', [json_encode(['nomor' => '6289999', 'sampai' => '', 'alasan' => 'tanpa batas'])]);
    expect(JedaBot::query()->count())->toBe(1)->and(JedaBot::query()->value('sampai'))->toBeNull();

    $akhir = DB::select('select * from n8n_akhiri_jeda(?::jsonb)', [json_encode(['chat_id' => '6289999@c.us', 'oleh' => '6281234560007@c.us', 'catatan' => 'sudah deal'])]);
    expect($akhir)->toHaveCount(1)->and($akhir[0]->selesai_at)->not->toBeNull();

    $jeda = JedaBot::query()->sole();
    expect($jeda->diakhiri_oleh)->toBe('Dina')->and($jeda->catatan_selesai)->toBe('sudah deal');

    DB::select('select * from n8n_jeda(?::jsonb)', [json_encode(['chat_id' => '6289999@c.us', 'sampai' => '2026-10-01 10:00'])]);
    DB::select('select * from n8n_akhiri_jeda(?::jsonb)', [json_encode(['chat_id' => '6289999@c.us', 'kedaluwarsa' => 'YA'])]);
    expect(JedaBot::query()->latest('id')->first()?->diakhiri_oleh)->toBe('Sistem')
        ->and(DB::select('select * from n8n_jeda_bot where selesai_at is null'))->toBe([]);
});

test('messages are written once, linked to the customer later, and campaign replies are tracked', function () {
    $pesan = fn (array $j) => DB::select('select * from n8n_catat_pesan(?::jsonb)', [json_encode($j)]);

    expect($pesan(['chat_id' => '6289999@c.us', 'pengirim' => 'CUSTOMER', 'teks' => 'halo', 'jalur' => 'D', 'wa_message_id' => 'wa-1']))->toHaveCount(1)
        ->and($pesan(['chat_id' => '6289999@c.us', 'pengirim' => 'CUSTOMER', 'teks' => 'halo', 'wa_message_id' => 'wa-1']))->toBe([]);
    $pesan(['chat_id' => '6289999@c.us', 'pengirim' => 'ADIVA', 'teks' => 'hai kak', 'tombol' => ['Mau order'], 'meta' => ['otomatis' => 'SAPAAN']]);

    expect(Pesan::query()->whereNull('crm_customer_id')->count())->toBe(2);
    DB::select('select * from n8n_simpan_crm(?::jsonb)', [json_encode(['chat_id' => '6289999@c.us', 'nama' => 'Rina'])]);
    $adiva = Pesan::query()->where('pengirim', 'ADIVA')->sole();
    expect(Pesan::query()->whereNull('crm_customer_id')->count())->toBe(0)
        ->and($adiva->status_kirim)->toBe('TERKIRIM')
        ->and($adiva->tombol)->toBe(['Mau order'])
        ->and($adiva->meta)->toBe(['otomatis' => 'SAPAAN']);

    $crm = CrmCustomer::query()->where('chat_id', '6289999@c.us')->sole();
    // the database clock, not the frozen demo clock: the 3-day window is checked in SQL
    $b = Broadcast::create(['nama' => 'Promo', 'pesan' => 'Halo', 'status' => 'SELESAI', 'kecepatan' => 'AMAN', 'jumlah_target' => 1]);
    $penerima = BroadcastPenerima::create([
        'broadcast_id' => $b->id, 'chat_id' => '6289999@c.us', 'crm_customer_id' => $crm->id, 'status' => BroadcastPenerima::TERKIRIM,
        'terkirim_at' => CarbonImmutable::parse(DB::scalar('select now()')), 'wa_message_id' => 'wa-bc',
    ]);

    DB::select('select * from n8n_pesan_dibaca(?)', ['wa-bc']);
    $pesan(['chat_id' => '6289999@c.us', 'pengirim' => 'CUSTOMER', 'teks' => 'STOP']);
    $penerima->refresh();
    expect($penerima->dibaca_at)->not->toBeNull()
        ->and($penerima->dibalas_at)->not->toBeNull()
        ->and($penerima->balasan)->toBe('STOP')
        ->and($penerima->minta_stop)->toBeTrue()
        ->and($crm->refresh()->status_optout)->toBeTrue();
});

test('notices, workflow events and the invoice PDF land where the panel reads them', function () {
    $wf = Workflow::factory()->create(['kode' => 'chatbot']);
    LogOrder::factory()->create(['order_id' => 'ORD-UJI-1']);

    DB::select('select * from n8n_notifikasi(?::jsonb)', [json_encode(['jenis' => 'BUKTI_MASUK', 'judul' => 'Bukti transfer masuk', 'order_id' => 'ORD-UJI-1'])]);
    expect(Notifikasi::query()->sole()->only(['jenis', 'kategori', 'penerima']))->toBe(['jenis' => 'BUKTI_MASUK', 'kategori' => 'ORDER', 'penerima' => 'ADMIN_WA']);

    $event = fn (array $j) => DB::select('select * from n8n_event(?::jsonb)', [json_encode($j)]);
    $event(['workflow' => 'chatbot', 'jenis' => 'EKSEKUSI', 'judul' => 'Pesan dijawab', 'n8n_execution_id' => '991', 'data' => ['jalur' => 'D', 'pesan' => 2]]);
    $event(['workflow' => 'chatbot', 'jenis' => 'EKSEKUSI', 'status' => 'ERROR', 'n8n_execution_id' => '991', 'data' => ['model' => 'gemini-3.6-flash']]);
    $e = WorkflowEvent::query()->sole();
    expect($e->workflow_id)->toBe($wf->id)
        ->and($e->status)->toBe('ERROR')
        ->and($e->data)->toBe(['jalur' => 'D', 'model' => 'gemini-3.6-flash', 'pesan' => 2]);

    DB::select('select * from n8n_simpan_invoice_pdf(?::jsonb)', [json_encode(['order_id' => 'ORD-UJI-1', 'nama_file' => 'INV-2026-10-001.pdf', 'base64' => base64_encode('%PDF-1.4 uji')])]);
    expect(LogOrder::query()->sole()->invoicePdf?->bytes())->toBe('%PDF-1.4 uji');
});

test('read views keep the Data Table shape', function () {
    User::factory()->create(['name' => 'Dina', 'nomor_wa' => '6281234560007']);
    User::factory()->create(['name' => 'Rizky', 'nomor_wa' => '6281234560008', 'wa_dicabut_at' => now()]);
    User::factory()->create(['name' => 'Lama', 'nomor_wa' => '6281234560009', 'nonaktif_at' => now()]);

    expect(collect(DB::select('select nama, dicabut from n8n_admin_staff order by nama'))->map(fn ($r) => (array) $r)->all())
        ->toBe([['nama' => 'Dina', 'dicabut' => ''], ['nama' => 'Rizky', 'dicabut' => 'YA']]);
});

test('Arsip Data copies a row once and only deletes what was copied', function () {
    LogOrder::factory()->create(['order_id' => 'ORD-LAMA']);
    $hapus = fn (array $j) => DB::select('select * from n8n_hapus_arsip(?::jsonb)', [json_encode($j)]);

    expect($hapus(['jenis' => 'ORDER', 'kunci' => 'ORD-LAMA']))->toBe([]);

    $arsip = ['jenis' => 'ORDER', 'kunci' => 'ORD-LAMA', 'tanggal' => '2026-06-01 10:00', 'data' => '{"order_id":"ORD-LAMA"}', 'diarsipkan' => '2026-10-08 01:00'];
    DB::select('select * from n8n_simpan_arsip(?::jsonb)', [json_encode($arsip)]);
    DB::select('select * from n8n_simpan_arsip(?::jsonb)', [json_encode($arsip)]);

    expect(DB::table('arsip_data')->count())->toBe(1)
        ->and($hapus(['jenis' => 'ORDER', 'kunci' => 'ORD-LAMA']))->toHaveCount(1)
        ->and(LogOrder::query()->count())->toBe(0);
});
