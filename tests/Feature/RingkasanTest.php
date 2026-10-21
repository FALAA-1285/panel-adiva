<?php

use App\Enums\JenisNotifikasi;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    foreach ([
        'DAFTAR_NOMOR_BOT' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2',
        'JAM_PESAN_OTOMATIS' => '07:00-21:00', 'HARI_LIBUR_OTOMATIS' => 'Minggu',
        'JEDA_FOLLOWUP_JAM' => '10', 'JEDA_KESAN_HARI' => '2', 'BATAS_BAYAR_JAM' => '5',
    ] as $kunci => $nilai) {
        ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
    }

    foreach ([
        ['chatbot', 'Setiap pesan masuk', '+ pengingat daftar jeda 09.00', 'AKTIF'],
        ['batas_bayar', 'Tiap jam, menit 15', null, 'AKTIF'],
        ['pantau_koneksi', 'Tiap 15 menit', null, 'AKTIF'],
        ['laporan_harian', 'Tiap hari 20.00', null, 'AKTIF'],
    ] as [$kode, $jadwal, $keterangan, $status]) {
        Workflow::create(['kode' => $kode, 'nama' => $kode, 'jadwal' => $jadwal, 'jadwal_keterangan' => $keterangan, 'status' => $status]);
    }

    Workflow::query()->where('kode', 'chatbot')->update(['pengaturan' => ['model_utama' => 'gemini-3.6-flash', 'model_cadangan' => 'gemini-flash-latest']]);
});

/**
 * A chat: one chatbot run.
 *
 * @param  array<string, mixed>  $data
 */
function chat(string $waktu, array $data = [], ?int $customer = null): void
{
    WorkflowEvent::create([
        'workflow_id' => Workflow::query()->where('kode', 'chatbot')->value('id'), 'waktu' => $waktu, 'jenis' => 'EKSEKUSI',
        'status' => 'BERHASIL', 'judul' => 'Pesan dijawab', 'crm_customer_id' => $customer, 'data' => ['model' => 'gemini-3.6-flash', 'model_ms' => 1400, ...$data],
    ]);
}

test('guests are sent to the login page', function () {
    $this->get('/')->assertRedirect('/masuk');
});

test('the figures compare with the same moment yesterday', function () {
    $a = CrmCustomer::factory()->create();
    $b = CrmCustomer::factory()->create();
    // today: 4 chats from 2 customers, 1 to an admin, 1 on the backup model
    chat('2026-09-28 09:00', [], $a->id);
    chat('2026-09-28 10:00', ['diteruskan' => true], $a->id);
    chat('2026-09-28 11:00', ['model' => 'gemini-flash-latest'], $b->id);
    chat('2026-09-28 12:00', [], $b->id);
    // yesterday before 13:42: 2 chats, 1 to an admin; after 13:42 does not count
    chat('2026-09-27 09:00');
    chat('2026-09-27 10:00', ['diteruskan' => true]);
    chat('2026-09-27 15:00');

    LogOrder::factory()->create(['tanggal' => '2026-09-28 13:06']);
    LogOrder::factory()->karyawan('6289612000077')->create(['tanggal' => '2026-09-28 10:31']);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-09-27 10:00', 'lunas_at' => '2026-09-28 13:05', 'total_produk' => 5_400_000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-09-27 10:00', 'lunas_at' => '2026-09-27 09:00', 'total_produk' => 4_000_000, 'ongkir' => 0]);
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['tanggal' => '2026-09-27 10:00', 'bukti_masuk_at' => '2026-09-28 08:14']);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ringkasan')
            ->where('tanggal', 'Senin, 28 September · data sampai 13:42 WIB')
            ->where('angka', [
                ['label' => 'Chat masuk hari ini', 'nilai' => '4', 'delta' => '+100% dari kemarin', 'deltaNada' => 'green', 'meta' => '2 customer · pesan beruntun digabung'],
                ['label' => 'Dijawab Adiva tanpa admin', 'nilai' => '75,0%', 'delta' => '+25,0 poin', 'deltaNada' => 'green', 'meta' => '1 lewat model cadangan · 0 pesan maaf'],
                ['label' => 'Order baru', 'nilai' => '2', 'meta' => '1 menunggu ACC · 1 order karyawan'],
                ['label' => 'Pembayaran lunas', 'nilai' => 'Rp5,40 jt', 'delta' => '+35,0%', 'deltaNada' => 'green', 'meta' => '1 order · 1 bukti transfer belum dicek'],
            ]));
});

test('the action list keeps only what is still open, most urgent kind first', function () {
    $rina = CrmCustomer::factory()->create(['nama' => 'Rina Maharani', 'tipe_customer' => TipeCustomer::Reseller, 'kota' => 'Malang']);
    $order = LogOrder::factory()->create(['order_id' => 'ORD-260928-014', 'crm_customer_id' => $rina->id, 'nama_penerima' => 'Rina Maharani', 'tanggal' => '2026-09-28 13:06', 'total_produk' => 1_245_000, 'ongkir' => 38_000, 'kurir_pilihan' => 'JNE REG']);
    $order->items()->create(['urutan' => 1, 'nama_produk' => 'Molreng', 'qty' => 40, 'harga_satuan' => 16500, 'subtotal' => 660000]);
    $order->items()->create(['urutan' => 2, 'nama_produk' => 'Basreng', 'qty' => 30, 'harga_satuan' => 19500, 'subtotal' => 585000]);
    $hendra = CrmCustomer::factory()->create(['nama' => 'Pak Hendra', 'tipe_customer' => TipeCustomer::Reseller, 'kota' => 'Batu', 'skor_prospek' => SkorProspek::Hot, 'butuh_admin' => true, 'pesan_terakhir_customer' => 'bisa harga distributor?']);
    $wati = CrmCustomer::factory()->create(['nama' => 'Bu Wati', 'tipe_customer' => TipeCustomer::Retail]);
    JedaBot::create(['chat_id' => $wati->chat_id, 'sejak' => '2026-09-28 10:05', 'oleh' => 'Falah', 'sudah_diberi_tahu' => true]);
    $sudah = CrmCustomer::factory()->create(['butuh_admin' => false]);

    $notif = fn (JenisNotifikasi $jenis, string $waktu, string $judul, ?int $customer = null, ?int $order = null) => Notifikasi::create([
        'waktu' => $waktu, 'jenis' => $jenis->value, 'kategori' => $jenis->kategori()->value, 'penerima' => 'ADMIN_WA',
        'judul' => $judul, 'crm_customer_id' => $customer, 'log_order_id' => $order,
    ]);
    $notif(JenisNotifikasi::JedaChat, '2026-09-28 13:30', 'Customer dijeda mengirim chat', $wati->id);
    $notif(JenisNotifikasi::ButuhAdmin, '2026-09-28 12:48', 'Butuh admin: nego harga', $hendra->id);
    $notif(JenisNotifikasi::OrderBaru, '2026-09-28 13:06', 'Order baru menunggu ACC', $rina->id, $order->id);
    // handled already, or from yesterday: left out
    $notif(JenisNotifikasi::ButuhAdmin, '2026-09-28 11:00', 'Butuh admin', $sudah->id);
    $notif(JenisNotifikasi::ButuhAdmin, '2026-09-27 12:00', 'Butuh admin', $hendra->id);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tindakan', 3)
            ->where('tindakan.0.judul', 'Order baru menunggu ACC')
            ->where('tindakan.0.waktu', '36 menit lalu')
            ->where('tindakan.0.info', 'Reseller · Malang')
            ->where('tindakan.0.isi', '2 item · Rp1.283.000 termasuk ongkir JNE REG')
            ->where('tindakan.0.aksi.1', ['label' => 'ACC & kirim invoice', 'aksi' => 'ACC', 'order' => 'ORD-260928-014', 'utama' => true])
            ->where('tindakan.1.info', 'Reseller · Batu · HOT')
            ->where('tindakan.1.isi', '“bisa harga distributor?”')
            ->where('tindakan.2.isi', 'Dijeda Falah sejak 10:05. Bot diam; customer sudah dikabari sekali.'));

    // a staff member who may not ACC does not get the button
    $this->actingAs(User::factory()->create(['peran' => Peran::PjKaryawan]))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->has('tindakan.0.aksi', 1));
});

test('the automated messages and Status Adiva read today\'s runs', function () {
    chat('2026-09-28 10:00', ['model_ms' => 1400, 'ongkir' => true]);
    chat('2026-09-28 10:05', ['model' => 'gemini-flash-latest', 'model_ms' => 1400]);
    $c = CrmCustomer::factory()->create();
    foreach (['SAPAAN', 'SAPAAN', 'KESAN', 'PENGINGAT_BAYAR'] as $jenis) {
        Pesan::create(['crm_customer_id' => $c->id, 'chat_id' => $c->chat_id, 'waktu' => '2026-09-28 10:30', 'pengirim' => PengirimPesan::Adiva, 'meta' => ['otomatis' => $jenis]]);
    }
    WorkflowEvent::create(['workflow_id' => Workflow::query()->where('kode', 'batas_bayar')->value('id'), 'waktu' => '2026-09-28 09:15', 'jenis' => 'EKSEKUSI', 'status' => 'BERHASIL', 'judul' => 'Tutup', 'data' => ['ditutup' => 2]]);
    WorkflowEvent::create(['workflow_id' => Workflow::query()->where('kode', 'pantau_koneksi')->value('id'), 'waktu' => '2026-09-28 13:30', 'jenis' => 'KONEKSI', 'status' => 'TERHUBUNG', 'judul' => 'Ok', 'data' => ['sesi' => 'bangbana-waha']]);
    WorkflowEvent::create(['workflow_id' => Workflow::query()->where('kode', 'pantau_koneksi')->value('id'), 'waktu' => '2026-09-28 13:30', 'jenis' => 'KONEKSI', 'status' => 'TERHUBUNG', 'judul' => 'Ok', 'data' => ['sesi' => 'bangbana-2']]);
    JedaBot::create(['chat_id' => $c->chat_id, 'sejak' => now()]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('otomatis.sub', 'Jam kerja 07.00–21.00 · Minggu tidak dihitung')
            ->where('otomatis.baris', [
                ['waktu' => '09.00', 'judul' => 'Pengingat daftar jeda', 'isi' => 'Belum terkirim hari ini'],
                ['waktu' => '10 jam', 'judul' => 'Sapaan ulang prospek', 'isi' => '2 customer hari ini · sekali saja'],
                ['waktu' => '2 hari', 'judul' => 'Tanya kesan pesanan', 'isi' => '1 customer setelah SELESAI'],
                ['waktu' => '5 jam', 'judul' => 'Batas bayar (jam kerja)', 'isi' => '1 diingatkan · 2 order belum di-ACC ditutup'],
                ['waktu' => '20.00', 'judul' => 'Laporan harian', 'isi' => 'Rekap 24 jam ke supervisor'],
            ])
            ->where('statusAdiva', [
                ['label' => 'Workflow n8n', 'nilai' => '4 aktif', 'nada' => 'green'],
                ['label' => 'WhatsApp (WAHA)', 'nilai' => '2 nomor · dicek 13:30', 'nada' => 'green'],
                ['label' => 'Model utama', 'nilai' => 'Gemini 3.6 Flash · 1,4 dtk'],
                ['label' => 'Model cadangan', 'nilai' => 'Dipakai 1× hari ini', 'nada' => 'amber'],
                ['label' => 'Ongkir Mengantar', 'nilai' => '1 cek hari ini'],
                ['label' => 'Sedang dijeda', 'nilai' => '1 customer', 'href' => '/jeda'],
            ]));
});

test('chat volume, stages, types and running orders', function () {
    chat('2026-08-30 10:00');
    chat('2026-09-15 10:00', ['diteruskan' => true]);
    chat('2026-09-15 11:00');
    chat('2026-08-29 10:00');
    CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::Nego, 'tipe_customer' => TipeCustomer::Reseller, 'tgl_interaksi_terakhir' => now()]);
    CrmCustomer::factory()->count(2)->create(['tahap_funnel' => TahapFunnel::TidakMinat, 'tipe_customer' => TipeCustomer::Retail, 'tgl_interaksi_terakhir' => now()]);
    CrmCustomer::factory()->create(['tahap_funnel' => TahapFunnel::Nego, 'tgl_interaksi_terakhir' => now()->subDays(40)]);
    LogOrder::factory()->create();
    LogOrder::factory()->status(StatusOrder::Diacc)->create();
    LogOrder::factory()->status(StatusOrder::Diacc)->create(['bukti_masuk_at' => now()]);
    LogOrder::factory()->status(StatusOrder::Selesai)->create();

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->has('volume.hari', 30)
            ->where('volume.hari.0', ['label' => '30 Agu', 'chat' => 1, 'diteruskan' => 0])
            ->where('volume.hari.16', ['label' => '15 Sep', 'chat' => 2, 'diteruskan' => 1])
            ->where('volume.sub', '3 chat masuk, 1 diteruskan ke admin (33,3%)')
            ->where('tahap.jumlah', 3)
            ->where('tahap.baris.3', ['label' => 'Nego', 'n' => 1, 'keluar' => false])
            ->where('tahap.baris.7', ['label' => 'Tidak minat', 'n' => 2, 'keluar' => true])
            ->where('tipe.baris.0', ['label' => 'Retail', 'n' => 2, 'warna' => '#1D4ED8'])
            ->where('tipe.baris.1', ['label' => 'Reseller', 'n' => 1, 'warna' => '#60A5FA'])
            ->where('pesanan.jumlah', 3)
            ->where('pesanan.baris.0', ['label' => 'Menunggu ACC', 'n' => 1, 'nada' => 'amber', 'tab' => 'menunggu-acc'])
            ->where('pesanan.baris.1.n', 1)
            ->where('pesanan.baris.2.n', 1));
});

test('the period picker and the export', function () {
    chat('2026-09-28 10:00');
    chat('2026-09-25 10:00');
    chat('2026-09-18 10:00');
    $falah = User::factory()->falah()->create();

    $this->actingAs($falah)
        ->get('/?rentang=7-hari')
        ->assertInertia(fn (Assert $page) => $page
            ->where('angka.0.label', 'Chat masuk 7 hari terakhir')
            ->where('angka.0.nilai', '2')
            ->where('angka.0.delta', '+100% dari 7 hari sebelumnya'));

    $csv = $this->actingAs($falah)
        ->get('/ringkasan/ekspor?rentang=7-hari')
        ->assertDownload('ringkasan-2026-09-28.csv')
        ->streamedContent();

    expect($csv)->toContain('Tanggal,"Chat masuk"')
        ->toContain('2026-09-22,0,0,0,0,0')
        ->toContain('2026-09-25,1,0,0,0,0')
        ->toContain('2026-09-28,1,0,0,0,0')
        ->not->toContain('2026-09-21');
});

test('the revenue chart counts money on the day it came in, not the day of the order', function () {
    // paid today, ordered long before: it belongs to today
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-09-01 10:00', 'lunas_at' => '2026-09-28 09:00', 'total_produk' => 1_200_000, 'ongkir' => 38_000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-09-28 08:00', 'lunas_at' => '2026-09-28 11:00', 'total_produk' => 800_000, 'ongkir' => 0]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-09-20 08:00', 'lunas_at' => '2026-09-20 08:30', 'total_produk' => 2_000_000, 'ongkir' => 0]);
    // never paid, and paid before the 30-day window: neither shows
    LogOrder::factory()->create(['tanggal' => '2026-09-28 12:00', 'total_produk' => 9_000_000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['tanggal' => '2026-08-01 08:00', 'lunas_at' => '2026-08-01 09:00', 'total_produk' => 7_000_000]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->has('omzet.hari', 30)
            ->where('omzet.sub', 'Rp4,04 jt dari 3 order · rata-rata Rp1,35 jt per order')
            ->where('omzet.hari.0', ['label' => '30 Agu', 'nilai' => 0, 'order' => 0])
            ->where('omzet.hari.21', ['label' => '20 Sep', 'nilai' => 2_000_000, 'order' => 1])
            ->where('omzet.hari.29', ['label' => '28 Sep', 'nilai' => 2_038_000, 'order' => 2]));
});

test('the best sellers add up every line of a product and skip cancelled orders', function () {
    $jual = function (string $tanggal, StatusOrder $status, array $item) {
        $order = LogOrder::factory()->status($status)->create(['tanggal' => $tanggal]);

        foreach ($item as $i => [$nama, $qty, $subtotal]) {
            $order->items()->create(['urutan' => $i + 1, 'nama_produk' => $nama, 'qty' => $qty, 'harga_satuan' => intdiv($subtotal, $qty), 'subtotal' => $subtotal]);
        }
    };
    // today: Molreng on two orders, Basreng on one
    $jual('2026-09-28 09:00', StatusOrder::Lunas, [['Molreng', 40, 660_000], ['Basreng', 30, 585_000]]);
    $jual('2026-09-28 10:00', StatusOrder::Diacc, [['Molreng', 10, 165_000]]);
    // cancelled today: not a sale
    $jual('2026-09-28 11:00', StatusOrder::Dibatalkan, [['Kripca', 99, 9_000_000]]);
    // yesterday, for the comparison: Molreng was bigger, Basreng did not sell
    $jual('2026-09-27 09:00', StatusOrder::Lunas, [['Molreng', 100, 1_650_000]]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('produk.sub', '2 produk terjual · Rp1,41 jt total')
            ->has('produk.baris', 2)
            ->where('produk.baris.0', ['nama' => 'Molreng', 'qty' => 50, 'nilai' => 'Rp825.000', 'delta' => '-50%', 'deltaNada' => 'red'])
            // no sale yesterday means no base to compare against
            ->where('produk.baris.1', ['nama' => 'Basreng', 'qty' => 30, 'nilai' => 'Rp585.000', 'delta' => null, 'deltaNada' => null]));
});

test('a customer counts as new only when this is their first order ever', function () {
    $lama = CrmCustomer::factory()->create();
    $baru = CrmCustomer::factory()->create();
    // the returning customer already ordered in August
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['crm_customer_id' => $lama->id, 'tanggal' => '2026-08-10 09:00', 'total_produk' => 500_000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['crm_customer_id' => $lama->id, 'tanggal' => '2026-09-28 09:00', 'total_produk' => 96_000]);
    // the new one starts today, with two orders on the same day
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['crm_customer_id' => $baru->id, 'tanggal' => '2026-09-28 10:00', 'total_produk' => 1_000_000]);
    LogOrder::factory()->status(StatusOrder::Lunas)->create(['crm_customer_id' => $baru->id, 'tanggal' => '2026-09-28 11:00', 'total_produk' => 370_000]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pelanggan.persen', 50)
            ->where('pelanggan.sub', '2 pelanggan belanja · 50% pelanggan lama')
            ->where('pelanggan.baris.0.label', 'Pelanggan baru')
            ->where('pelanggan.baris.0.n', 1)
            ->where('pelanggan.baris.0.nilai', 'Rp1,37 jt')
            ->where('pelanggan.baris.1.label', 'Pelanggan lama')
            ->where('pelanggan.baris.1.n', 1)
            ->where('pelanggan.baris.1.nilai', 'Rp96.000'));
});

test('the lost orders are grouped by what happened to them', function () {
    LogOrder::factory()->status(StatusOrder::Ditolak)->create(['tanggal' => '2026-09-28 09:00', 'total_produk' => 300_000, 'ongkir' => 20_000]);
    LogOrder::factory()->status(StatusOrder::BatalOtomatis)->create(['tanggal' => '2026-09-28 10:00', 'total_produk' => 500_000]);
    LogOrder::factory()->status(StatusOrder::BatalOtomatis)->create(['tanggal' => '2026-09-28 11:00', 'total_produk' => 180_000]);
    // still running today, and a cancellation from yesterday: neither belongs here
    LogOrder::factory()->create(['tanggal' => '2026-09-28 12:00', 'total_produk' => 9_000_000]);
    LogOrder::factory()->status(StatusOrder::Ditolak)->create(['tanggal' => '2026-09-27 09:00', 'total_produk' => 7_000_000]);

    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('hilang.jumlah', 3)
            ->where('hilang.sub', '3 order · Rp1,00 jt tidak jadi masuk')
            ->has('hilang.baris', 2)
            ->where('hilang.baris.0', ['label' => 'Ditolak', 'n' => 1, 'nilai' => 'Rp320.000', 'nada' => 'red', 'tab' => 'batal'])
            ->where('hilang.baris.1', ['label' => 'Batal otomatis', 'n' => 2, 'nilai' => 'Rp680.000', 'nada' => 'red', 'tab' => 'batal']));
});

test('an empty period says so instead of leaving the panels blank', function () {
    $this->actingAs(User::factory()->falah()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('produk.sub', '0 produk terjual')
            ->has('produk.baris', 0)
            ->where('pelanggan.sub', 'Belum ada yang belanja di rentang ini')
            ->where('hilang.sub', '0 order batal')
            ->has('hilang.baris', 0));
});
