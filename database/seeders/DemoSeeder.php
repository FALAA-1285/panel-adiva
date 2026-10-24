<?php

namespace Database\Seeders;

use App\Enums\JenisNotifikasi;
use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\Peran;
use App\Enums\SkorProspek;
use App\Enums\StatusBroadcast;
use App\Enums\StatusData;
use App\Enums\StatusOrder;
use App\Enums\StatusPrompt;
use App\Enums\StatusWorkflow;
use App\Enums\SumberAktivitas;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\ActivityLog;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\ConfigToko;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\HargaKaryawan;
use App\Models\JedaBot;
use App\Models\Karyawan;
use App\Models\Kebijakan;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\PaketReseller;
use App\Models\PemakaianKebijakan;
use App\Models\Pesan;
use App\Models\Produk;
use App\Models\PromptVersi;
use App\Models\TemplateBroadcast;
use App\Models\TemplateFollowup;
use App\Models\UjiAdiva;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sample data copied exactly from the <script> section of design/source/*.dc.html.
 * Each page adds its own data here when it is built.
 *
 * Rows visible in the design are copied exactly. "Pengisi" rows only exist so that
 * aggregate numbers in the design (Sidebar, Topbar, Pipeline, Ringkasan) come out
 * right; they use neutral names and older dates so they stay off-screen.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Running number for pengisi order ids (ORD-260915-101 …), clear of the design's ids.
     */
    private int $urutPengisi = 100;

    /**
     * Seed the demo data.
     */
    public function run(): void
    {
        $this->nomorBot();
        $this->staf();
        $this->katalog();
        $this->workflow();
        $this->pengaturan();
        $this->prompt();
        $this->customer();
        $this->fotoTerkirim();
        $this->pemakaianKebijakan();
        $this->pesanan();
        $this->orderKaryawan();
        $this->pipeline();
        $this->broadcast();
        $this->templateBroadcast();
        $this->jeda();
        $this->insiden();
        $this->notifikasi();
        $this->aktivitas();
        $this->otomasi();
        $this->monitoring();
        $this->ringkasan();
        $this->percakapan();
    }

    /**
     * Sidebar "2 nomor WAHA" (Settings.dc.html: sesi WAHA).
     */
    protected function nomorBot(): void
    {
        ConfigToko::create([
            'kunci' => 'DAFTAR_NOMOR_BOT',
            // Jeda.dc.html writes the short names: "· utama", "· CS 2"
            'nilai' => 'bangbana-waha=Nomor Utama=utama,bangbana-2=Nomor Lawang=CS 2',
        ]);
    }

    /**
     * Staff list. Dina (Daftar.dc.html step 2: "Dina · Cocok dengan daftar staf ·
     * dina@bangbana.id · Staf CS", number "+62 857-••••-••36") logs in with a
     * WhatsApp code and has no panel password yet, so she can still use Daftar.
     */
    protected function staf(): void
    {
        // LupaSandi.dc.html: "+62 812-••••-••07", "Untuk akun falah@bangbana.id". /_demo/login signs in as this user.
        // Tim.dc.html: "Hari ini 13:40 · Chrome, Windows"
        User::updateOrCreate(
            ['email' => 'falah@bangbana.id'],
            [
                'name' => 'Falah',
                'nomor_wa' => '6281234560007',
                'cabang' => 'Malang',
                'peran' => Peran::Supervisor,
                'terakhir_masuk_at' => $this->jam('2026-09-28 13:40'),
                'terakhir_masuk_perangkat' => 'Chrome · Windows',
            ],
        );

        // Tim.dc.html: "Hari ini 08:02 · Android"
        $dina = User::updateOrCreate(
            ['email' => 'dina@bangbana.id'],
            [
                'name' => 'Dina',
                'nomor_wa' => '6285712340036',
                'cabang' => 'Malang',
                'peran' => Peran::StafCs,
                'password' => null,
                'terakhir_masuk_at' => $this->jam('2026-09-28 08:02'),
                'terakhir_masuk_perangkat' => 'Android',
            ],
        );

        // Tim.dc.html, Karyawan.dc.html: "Bu Sarah · PJ karyawan", "0812-••••-0646", "Undangan terkirim"
        User::updateOrCreate(
            ['email' => 'sarah@bangbana.id'],
            [
                'name' => 'Bu Sarah',
                'nomor_wa' => '6281298760646',
                'cabang' => 'Malang',
                'peran' => Peran::PjKaryawan,
                'diundang_at' => $this->jam('2026-09-27 09:00'),
            ],
        );

        // Tim.dc.html: "Nonaktif", "12 Sep · Chrome, Android"
        User::updateOrCreate(
            ['email' => 'rizky@bangbana.id'],
            [
                'name' => 'Rizky',
                'nomor_wa' => '6282112340052',
                'cabang' => 'Malang',
                'peran' => Peran::StafCs,
                'nonaktif_at' => $this->jam('2026-09-20 10:00'),
                'terakhir_masuk_at' => $this->jam('2026-09-12 16:00'),
                'terakhir_masuk_perangkat' => 'Chrome · Android',
            ],
        );

        // Tim.dc.html, Sesi & keamanan: "Dina · Chrome · Android · Malang", "5 jam lalu"
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $dina->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
            'payload' => base64_encode(serialize([])),
            'last_activity' => $this->jam('2026-09-28 08:42')->getTimestamp(),
        ]);
    }

    /**
     * Katalog & pengetahuan. Products as in Katalog.dc.html, with example prices so the
     * showcase has no empty columns; tab counts Paket 3, Kebijakan 9, Program karyawan 12.
     */
    protected function katalog(): void
    {
        // [min, max, harga] per tier
        $tier = fn (string $satuan, array $batas) => [
            'satuan' => $satuan,
            'tier' => array_map(fn (array $b) => ['min' => $b[0], 'max' => $b[1], 'harga' => $b[2]], $batas),
        ];
        $duaTier = fn (int $eceran, int $grosir) => $tier('pcs', [[1, 9, $eceran], [10, null, $grosir]]);

        $produk = [
            ['Molreng Reguler', 'Molreng', 'Pack', '250 g', 'Bawang original, pedas', $tier('pcs', [[1, 9, 12000], [10, 49, 11000], [50, null, 10000]]), 'Kantin sekolah, warung'],
            ['Basreng Pedas Daun Jeruk', 'Basreng', 'Pack', '250 g', 'Pedas daun jeruk', $duaTier(15000, 14000), 'Reseller online, oleh-oleh'],
            ['Kripca 1,8 kg', 'Keripik kaca', 'Bal', '1,8 kg', 'Original, pedas', $tier('bal', [[1, 29, 92000], [30, null, 87000]]), 'Grosir, repack'],
            ['Seblak Kering', 'Seblak', 'Pack', '200 g', 'Original, pedas', $duaTier(10000, 9000), 'Konsumen akhir'],
            ['Makaroni Bantat', 'Makaroni', 'Pack', '250 g', 'Balado, asin', $duaTier(9000, 8000), 'Warung, kantin'],
            ['Kentang Balado', 'Kentang balado', 'Pack', '200 g', 'Balado', $duaTier(13000, 12000), 'Oleh-oleh'],
            ['Kerupuk Rujak', 'Kerupuk rujak', 'Pack', '150 g', 'Rujak', $duaTier(7000, 6000), 'Warung'],
            ['Usus Crispy', 'Usus', 'Pack', '100 g', 'Original, pedas', $duaTier(11000, 10000), 'Konsumen akhir'],
        ];

        foreach ($produk as [$nama, $kategori, $kemasan, $gramasi, $rasa, $harga, $target]) {
            Produk::create([
                'jenis_produk' => $nama,
                'kategori_produk' => $kategori,
                'kategori_kemasan' => $kemasan,
                'gramasi' => $gramasi,
                'rasa' => $rasa,
                'harga_bertingkat' => $harga,
                'target_pasar' => $target,
            ]);
        }

        // Paket.dc.html (the editor shows PKT-A)
        $paket = [
            ['PKT-A', 'Paket Kantin', 'Molreng 20 + Basreng 20', 'Kantin sekolah, modal kecil', '± 1 kg', 480000],
            ['PKT-B', 'Paket Warung Campur', '6 varian × 10 pcs', 'Warung kelontong', '± 1,5 kg', 590000],
            ['PKT-C', 'Paket Repack', 'Kripca 1,8 kg × 10 bal', 'Repack / grosir', '18 kg · sarankan kargo', 900000],
        ];

        foreach ($paket as [$kode, $nama, $isi, $cocok, $berat, $harga]) {
            PaketReseller::create([
                'kode_paket' => $kode,
                'nama_paket' => $nama,
                'isi' => $isi,
                'cocok_untuk' => $cocok,
                'berat' => $berat,
                'harga' => $harga,
                'status_data' => StatusData::Aktif->value,
            ]);
        }

        $kantin = PaketReseller::query()->where('kode_paket', 'PKT-A')->sole();
        $kantin->timestamps = false;
        $kantin->forceFill([
            'isi_detail' => [
                ['nama' => 'Molreng Reguler Bawang Original', 'jumlah' => '10 pcs'],
                ['nama' => 'Molreng Reguler Pedas', 'jumlah' => '10 pcs'],
                ['nama' => 'Basreng Pedas Daun Jeruk', 'jumlah' => '20 pcs'],
            ],
            'tawarkan_ketika' => 'Customer baru dinilai reseller dengan estimasi modal di bawah Rp2 jt, atau bertanya “paket buat pemula ada?”',
            // "terakhir diubah 12 Sep oleh Falah"
            'diubah_oleh_id' => User::query()->where('email', 'falah@bangbana.id')->value('id'),
            'updated_at' => $this->jam('2026-09-12 10:00'),
        ])->save();

        // Gambar.dc.html: 7 photos + "Usus Crispy", a slot still waiting for its file.
        // (Katalog.dc.html also marks Kentang Balado and Kerupuk Rujak "Ada"; the design
        // contradicts itself there, and Gambar wins: they have no photo row.)
        $gambar = [
            ['Molreng Reguler', 'Molreng Reguler Bawang Original', 'molreng, bawang', 'molreng-bawang.jpg'],
            ['Molreng Reguler', 'Molreng Reguler Pedas', 'molreng, pedas', 'molreng-pedas.jpg'],
            ['Basreng Pedas Daun Jeruk', 'Basreng Pedas Daun Jeruk', 'basreng, daun jeruk', 'basreng-daun-jeruk.jpg'],
            ['Kripca 1,8 kg', 'Kripca 1,8 kg Original', 'kripca, keripik kaca', 'kripca-original.jpg'],
            ['Kripca 1,8 kg', 'Kripca 1,8 kg Pedas', 'kripca pedas', 'kripca-pedas.jpg'],
            ['Seblak Kering', 'Seblak Kering Original', 'seblak', 'seblak-original.jpg'],
            ['Makaroni Bantat', 'Makaroni Bantat Balado', 'makaroni, balado', 'makaroni-balado.jpg'],
            ['Usus Crispy', 'Usus Crispy', 'usus', null],
        ];

        foreach ($gambar as [$produkNama, $judul, $tags, $filename]) {
            GambarProduk::create([
                'produk_id' => Produk::query()->where('jenis_produk', $produkNama)->value('id'),
                'title' => $judul,
                'tags' => $tags,
                'filename' => $filename,
                'aktif' => true,
            ]);
        }

        // Detail foto panel
        GambarProduk::query()->where('title', 'Molreng Reguler Bawang Original')->update([
            'caption' => 'Molreng Reguler Bawang Original — harga turun di pembelian 10+ dan 50+ pcs ya kak.',
            'lebar' => 1080,
            'tinggi' => 1080,
            'ukuran_byte' => 184 * 1024,
        ]);

        // Kebijakan.dc.html: title and "diubah …"
        $kebijakan = [
            'Minimal order' => '2026-09-12', 'Diskon' => '2026-09-12', 'Ongkir & ekspedisi' => '2026-09-20',
            'Metode pembayaran' => '2026-09-20', 'COD' => '2026-09-12', 'Jam operasional & lokasi' => '2026-09-25',
            'Retur & komplain' => '2026-09-12', 'Halal / PIRT' => '2026-09-01', 'Sampel' => '2026-09-12',
        ];
        $no = 0;

        foreach ($kebijakan as $topik => $diubah) {
            $baru = new Kebijakan(['kode' => sprintf('KB-%02d', ++$no), 'topik' => $topik, 'status_data' => StatusData::Aktif->value]);
            $baru->timestamps = false;
            $baru->forceFill(['created_at' => $this->jam($diubah.' 10:00'), 'updated_at' => $this->jam($diubah.' 10:00')])->save();
        }

        // The policy open in the editor
        $ongkir = Kebijakan::query()->where('topik', 'Ongkir & ekspedisi')->sole();
        $ongkir->timestamps = false;
        $ongkir->forceFill([
            'jawaban_resmi' => '[Tulis aturan pengiriman: kurir yang tersedia, asal kirim dari gudang Sawojajar, kapan paket berangkat, siapa yang menanggung ongkir, dan estimasi tiba.]',
            'kata_pemicu' => 'ongkir, kirim, ekspedisi, kurir, JNE, J&T, gosend',
        ])->save();

        // Karyawan.dc.html: "12 orang · menampilkan 5".
        $karyawan = [
            ['Yoga', '6289612000077', 'Produksi', StatusData::Aktif],
            ['Rahma', '6281255300004', 'Packing', StatusData::Aktif],
            ['Doni', '6285733210090', 'Gudang Sawojajar', StatusData::Aktif],
            ['Lilis', '6281322900018', 'Produksi', StatusData::Aktif],
            ['Siti', '6281377810025', 'Admin', StatusData::Nonaktif],
        ];

        foreach ($karyawan as [$nama, $nomor, $bagian, $status]) {
            Karyawan::create(['nama' => $nama, 'nomor_wa' => $nomor, 'bagian' => $bagian, 'status' => $status->value]);
        }

        // Pengisi: the other 7 verified employees.
        foreach (range(1, 7) as $i) {
            Karyawan::create(['nama' => 'Karyawan demo '.$i, 'nomor_wa' => '62800000010'.$i, 'bagian' => 'Produksi', 'status' => StatusData::Aktif->value]);
        }

        // Karyawan.dc.html: Harga karyawan (example prices)
        $hargaKaryawan = [
            ['Basreng Original', 'BSR-O', '4 pack / minggu', 11000],
            ['Basreng Pedas', 'BSR-P', '4 pack / minggu', 11000],
            ['Molreng Reguler', 'MLR', '4 pack / minggu', 8500],
            ['Kripca 1,8 kg', 'KRC', '2 bal / bulan', 80000],
        ];

        foreach ($hargaKaryawan as [$varian, $singkatan, $batas, $harga]) {
            HargaKaryawan::create(['varian_produk' => $varian, 'singkatan' => $singkatan, 'batas' => $batas, 'harga_karyawan' => $harga]);
        }

        // Katalog.dc.html: Uji tanya Adiva (and the highlighted Kripca row).
        UjiAdiva::create([
            'bagian' => 'KATALOG',
            'pertanyaan' => 'kripca 1,8 kg kalau ambil 30 harganya brp?',
            'jawaban' => 'Untuk Kripca 1,8 kg pembelian 30 bal harganya Rp87.000 per bal ya kak. Mau yang Original, Pedas, atau campur?',
            'catatan' => 'Tool dipakai: Cari_Produk_per_Kategori (Keripik kaca) · dinilai Reseller · Tanya harga · WARM',
            'produk_id' => Produk::query()->where('jenis_produk', 'Kripca 1,8 kg')->value('id'),
            'user_id' => User::query()->where('email', 'falah@bangbana.id')->value('id'),
        ]);
    }

    /**
     * Sidebar "7 workflow aktif" (Otomasi.dc.html: Workflow & jadwal).
     */
    protected function workflow(): void
    {
        $workflow = [
            ['chatbot', 'chatbot bangbana (main)', 143, 'Setiap pesan masuk', '+ pengingat daftar jeda 09.00', 'Menjawab chat, perintah admin, jeda, lampiran & bukti transfer', StatusWorkflow::Aktif],
            ['batas_bayar', 'Batas Bayar Order', 25, 'Tiap jam, menit 15', 'bertindak hanya di jam kerja', 'Ingatkan bayar, batalkan order lewat batas, tutup order yang tak sempat di-ACC', StatusWorkflow::Aktif],
            ['prospek_ulang', 'Prospek Ulang Otomatis', 18, 'Tiap jam, menit 30', 'kirim hanya 07.00–21.00', 'Sapa prospek yang berhenti membalas dan tanya kesan pesanan, masing-masing sekali', StatusWorkflow::Aktif],
            ['pantau_koneksi', 'Pantau Koneksi WhatsApp', 6, 'Tiap 15 menit', 'semua nomor di DAFTAR_NOMOR_BOT', 'Cek sambungan tiap nomor bot; kabari supervisor bila putus 2× berturut-turut', StatusWorkflow::Aktif],
            ['laporan_harian', 'Laporan Harian', 10, 'Tiap hari 20.00', 'rekap 24 jam terakhir', 'Kirim rekap order, pembayaran, antrean, dan customer ke supervisor', StatusWorkflow::Aktif],
            ['arsip_data', 'Arsip Data', 16, 'Minggu 01.00', 'mingguan', 'Pindahkan order dan customer lama ke tabel ARSIP_DATA, lalu hapus dari tabel aslinya', StatusWorkflow::Aktif],
            ['notifikasi_error', 'Notifikasi Error', 7, 'Saat workflow lain gagal', 'dipasang sebagai Error Workflow', 'Kirim nama alur, langkah yang gagal, dan pesan error ke supervisor', StatusWorkflow::Siaga],
        ];

        foreach ($workflow as [$kode, $nama, $node, $jadwal, $keterangan, $deskripsi, $status]) {
            Workflow::create([
                'kode' => $kode,
                'nama' => $nama,
                'jumlah_node' => $node,
                'jadwal' => $jadwal,
                'jadwal_keterangan' => $keterangan,
                'deskripsi' => $deskripsi,
                'status' => $status->value,
            ]);
        }
    }

    /**
     * Settings.dc.html: the CONFIG_TOKO values on the page (empty fields have no row),
     * the chatbot's AI settings, the last connection check and the active prompt.
     */
    protected function pengaturan(): void
    {
        $config = [
            'ADMIN_WA' => '6281234560007', // Falah
            'SARAH_WA' => '6281298760646', // Bu Sarah
            'NAMA_TOKO' => 'Bang Bana — CV TKT Bana Food',
            'INVOICE_TERAKHIR' => 'INV/2026/09/141',
            'BATAS_BAYAR_JAM' => '5',
            'BATAS_BAYAR_PICKUP_JAM' => '24',
            'BATAS_KONFIRMASI_JAM' => '24',
            'CATATAN_INVOICE' => 'Ongkir sudah termasuk dalam tagihan ini. Mohon transfer sesuai TOTAL, tidak perlu bayar lagi ke kurir.',
            'JAM_PESAN_OTOMATIS' => '07:00-21:00',
            'HARI_LIBUR_OTOMATIS' => 'Minggu',
            'JEDA_FOLLOWUP_JAM' => '10',
            'JEDA_KESAN_HARI' => '2',
            // a stand-in: the real key is entered on the server, never shown
            'MENGANTAR_API_KEY' => 'demo-mengantar-key',
            'MENGANTAR_KOTA_ASAL' => 'SAWOJAJAR, KEDUNGKANDANG, MALANG, JAWA TIMUR',
            'MENGANTAR_PICKUP_ADDRESS_ID' => '5fc6390df8f44b34aa4c7fd7',
            'MENGANTAR_JAM_PICKUP' => '13:00',
            'MENGANTAR_VOLUME_PICKUP' => 'volumeMotor',
            'KURIR_DIKECUALIKAN' => 'SAPLite',
            'SESI_UTAMA' => 'bangbana-waha',
            'ARSIP_ORDER_HARI' => '90',
            'ARSIP_CUSTOMER_HARI' => '365',
        ];

        foreach ($config as $kunci => $nilai) {
            ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
        }

        // "AI & perilaku bot": set in the chatbot workflow's nodes
        Workflow::query()->where('kode', 'chatbot')->update(['pengaturan' => [
            'model_utama' => 'gemini-3.6-flash',
            'model_cadangan' => 'gemini-flash-latest',
            'temperature' => 0.4,
            'ingatan_pesan' => 60,
            'tunggu_gabung_detik' => 8,
        ]]);

        // "Terhubung" for both numbers, "Terakhir dicek 13:30" (Pantau Koneksi WhatsApp)
        $pantau = Workflow::query()->where('kode', 'pantau_koneksi')->sole();

        foreach (['bangbana-waha' => 'Nomor Utama', 'bangbana-2' => 'Nomor Lawang'] as $sesi => $label) {
            WorkflowEvent::create([
                'workflow_id' => $pantau->id,
                'waktu' => $this->jam('2026-09-28 13:30'),
                'jenis' => 'KONEKSI',
                'status' => 'TERHUBUNG',
                'judul' => "{$label} tersambung",
                'data' => ['sesi' => $sesi],
            ]);
        }
    }

    /**
     * Prompt.dc.html: the 18 sections (0–17) and versions v12–v15. v14 is active,
     * published "29 Sep" as the design writes it (a day after the demo clock); v15 is
     * Falah's draft, so it reads "Anda · belum diterbitkan". v14's text is Adiva's
     * prompt from n8n (teksPrompt), except section 12, which the design shows: its v14
     * text is the lines without "+", its v15 text the lines without "−". v12 and v13
     * carry v14's text (their own is not in the design).
     */
    protected function prompt(): void
    {
        $judul = [
            'Baca dulu', 'Identitas', 'Kepribadian & gaya', 'Sumber data (tool)', 'Pembuka', 'Jalur reseller',
            'Jalur konsumsi sendiri', 'Ringkasan pesanan', 'Alamat & ongkir', 'Order & pembayaran', 'Gambar produk',
            'Tombol balasan', 'Eskalasi admin', 'Jam operasional', 'Kategorisasi', 'Prinsip percakapan',
            'Cek status pesanan', 'Program karyawan',
        ];
        $eskalasi = [
            'Set butuh_admin = true (dan isi alasan_butuh_admin) untuk:',
            '- bukti transfer, komplain/retur, nego harga khusus,',
            '- kerja sama distributor besar, permintaan sampel,',
            '- ongkir yang tidak ditemukan, data yang tidak ada di tool,',
            '- customer yang terdengar kecewa atau marah,',
            '- permintaan pembatalan atau perubahan pesanan (bagian 9),',
            '- status pesanan yang TIDAK ketemu di Cek_Order_Saya.',
            '',
            'Pertanyaan status pesanan, pembayaran, pengiriman, atau resi',
            'yang datanya ADA di Cek_Order_Saya dijawab sendiri (bagian 16)',
            'dan butuh_admin = false.',
        ];

        $teks = $this->teksPrompt();
        $v14 = array_map(fn (string $judul, int $no) => ['no' => $no, 'judul' => $judul, 'teks' => $teks[$no] ?? ''], $judul, array_keys($judul));
        $v14[12]['teks'] = implode("\n", [...$eskalasi, 'Jangan pernah memberi nomor resi karangan.']);
        $v14[12]['keterangan'] = 'kapan Adiva memanggil admin';

        $v15 = $v14;
        array_splice($eskalasi, 7, 0, ['- order di atas 200 pcs untuk satu varian.']);
        $v15[12]['teks'] = implode("\n", [...$eskalasi, 'Jangan pernah memberi nomor resi atau harga karangan.']);

        $falah = User::query()->where('email', 'falah@bangbana.id')->value('id');
        $dina = User::query()->where('email', 'dina@bangbana.id')->value('id');

        // [versi, ringkasan, bagian, status, oleh, diterbitkan]
        $versi = [
            [12, 'Program karyawan & singkatan', $v14, StatusPrompt::Arsip, $dina, '2026-09-12 10:00'],
            [13, 'Satu order aktif saja', $v14, StatusPrompt::Arsip, $falah, '2026-09-21 10:00'],
            [14, 'Tahap Tidak minat & aturan sapaan ulang', $v14, StatusPrompt::Aktif, $falah, '2026-09-29 10:00'],
            [15, 'Eskalasi order besar, larang harga karangan', $v15, StatusPrompt::Draf, $falah, null],
        ];

        foreach ($versi as [$nomor, $ringkasan, $bagian, $status, $oleh, $diterbitkan]) {
            PromptVersi::create([
                'versi' => $nomor,
                'ringkasan' => $ringkasan,
                'bagian' => $bagian,
                'status' => $status,
                'user_id' => $oleh,
                'diterbitkan_at' => $diterbitkan ? $this->jam($diterbitkan) : null,
            ]);
        }

        // "Uji draf": the last test of v15
        UjiAdiva::create([
            'bagian' => 'PROMPT',
            'pertanyaan' => 'kak saya distributor di kediri, bisa minta harga khusus buat 300 pcs basreng?',
            'jawaban' => 'Siap kak, untuk harga distributor 300 pcs Adiva teruskan ke admin dulu supaya dicek langsung ya. Boleh tahu mau varian yang pedas daun jeruk atau original?',
            'hasil' => ['butuh_admin' => true, 'tahap' => TahapFunnel::Nego->value, 'alasan' => 'kerja sama distributor besar'],
            'prompt_versi_id' => PromptVersi::query()->draf()->value('id'),
            'user_id' => $falah,
        ]);
    }

    /**
     * Adiva's system prompt as it is in n8n (data/prompt-adiva.txt), cut at its
     * "═══ N. JUDUL ═══" headings into the design's sections. A sub-section (3b, 7b)
     * stays in its section under its own heading, and the "0. BACA DULU" block at the
     * top of section 4 is section 0 ("Baca dulu").
     *
     * @return array<int, string> section number => text
     */
    protected function teksPrompt(): array
    {
        $isi = str_replace("\r\n", "\n", (string) file_get_contents(database_path('seeders/data/prompt-adiva.txt')));
        // [title, heading, number, text, heading, number, text, …]
        $potongan = preg_split('/^(═+\n(\d+)[a-z]?\. .+\n═+)\n/mu', $isi, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $teks = [];

        for ($i = 1; $i + 2 < count($potongan); $i += 3) {
            $no = (int) $potongan[$i + 1];
            $badan = trim($potongan[$i + 2], "\n");
            $teks[$no] = isset($teks[$no]) ? "{$teks[$no]}\n\n{$potongan[$i]}\n{$badan}" : $badan;
        }

        // "0. BACA DULU SEBELUM MEMBUKA — PALING PENTING", up to the first empty line
        if (preg_match('/^0\. .+?\n(.+?)\n\n(.*)$/su', $teks[4] ?? '', $baca)) {
            [$teks[0], $teks[4]] = [$baca[1], $baca[2]];
        }

        return $teks;
    }

    /**
     * Customers referenced by the rows below, plus Sidebar "Percakapan 6"
     * (Inbox.dc.html: tab "Butuh admin 6"; only Pak Hendra is shown).
     */
    protected function customer(): void
    {
        $customer = [
            ['6281233450081', 'Rina Maharani', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::OrderDibuat, SkorProspek::Hot, 'Malang', '2026-09-28 13:06', false, null],
            ['6285722100009', 'Pak Hendra', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::Nego, SkorProspek::Hot, 'Batu', '2026-09-28 12:48', true, 'Nego harga khusus: minta harga distributor untuk 200 pcs basreng.'],
            ['6281355660042', 'Bu Wati', 'bangbana-2', TipeCustomer::Retail, TahapFunnel::TanyaHarga, SkorProspek::Warm, 'Malang', '2026-09-28 11:52', false, null],
            ['6285744100036', 'Toko Berkah Jaya', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::Nego, SkorProspek::Hot, 'Blitar', '2026-09-26 10:00', false, null],
            ['6282290310018', 'Mbak Lia', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::SiapOrder, SkorProspek::Warm, 'Malang', '2026-09-24 09:50', false, null],
            ['6289612000011', 'Anisa Putri', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::OrderDibuat, SkorProspek::Hot, 'Malang', '2026-09-26 09:40', false, null],
            ['6281277000055', 'Nisa Aulia', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::TanyaProduk, SkorProspek::Warm, 'Malang', '2026-09-24 09:30', false, null],
            // Orders.dc.html; tahap and skor from Pipeline.dc.html / Customer.dc.html where they appear
            ['6282177880015', 'Dimas Pratama', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::OrderDibuat, SkorProspek::Hot, 'Malang', '2026-09-26 09:20', false, null],
            ['6285655440027', 'Laras Ayu', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::OrderDibuat, SkorProspek::Warm, 'Batu', '2026-09-26 09:10', false, null],
            ['6281333440019', 'Toko Sumber Rejeki', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::OrderDibuat, SkorProspek::Warm, 'Lawang', '2026-09-26 09:00', false, null],
            ['6287855660008', 'Bayu Saputra', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::OrderDibuat, SkorProspek::Warm, 'Malang', '2026-09-26 08:50', false, null],
            ['6281944550004', 'Maya Kusuma', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::OrderDibuat, SkorProspek::Warm, 'Malang', '2026-09-28 10:40', false, null],
            ['6285233660010', 'Fikri Ramadhan', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::TidakMinat, SkorProspek::Cold, 'Malang', '2026-09-26 12:00', false, null],
            ['6289677880002', 'Sinta', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::CartDitinggal, SkorProspek::Cold, 'Malang', '2026-09-25 12:50', false, null],
            // Customer.dc.html: the rest of the list
            ['6281388770021', 'Mega Lestari', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::SiapOrder, SkorProspek::Hot, 'Lawang', '2026-09-28 12:30', false, null],
            ['6285611220033', 'Salsa Billa', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::TanyaProduk, SkorProspek::Warm, 'Malang', '2026-09-28 11:20', false, null],
            ['6289612000077', 'Yoga', 'bangbana-waha', TipeCustomer::Karyawan, TahapFunnel::OrderDibuat, SkorProspek::Hot, 'Lawang', '2026-09-28 10:31', false, null],
            ['6287712340045', 'Andika Putra', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::TanyaHarga, SkorProspek::Warm, 'Pasuruan', '2026-09-28 06:30', false, null],
            ['6281299880056', 'Dewi Anggraini', 'bangbana-waha', TipeCustomer::BelumJelas, TahapFunnel::Baru, SkorProspek::Cold, 'Malang', '2026-09-27 10:00', false, null],
            // Pipeline.dc.html: the other cards. All chatted before Fikri (26 Sep 12:00), so
            // the Pelanggan list keeps its ten rows.
            ['6287800415120', null, 'bangbana-waha', TipeCustomer::BelumJelas, TahapFunnel::Baru, SkorProspek::Cold, null, '2026-09-26 09:14', false, null],
            ['6281566120034', 'Arif Setiawan', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::Baru, SkorProspek::Cold, 'Surabaya', '2026-09-26 11:00', false, null],
            ['6285299310047', 'Pak Joko', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::TanyaProduk, SkorProspek::Warm, 'Kepanjen', '2026-09-26 10:30', false, null],
            ['6281377450012', 'Intan Permata', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::TanyaProduk, SkorProspek::Warm, 'Batu', '2026-09-26 08:55', false, null],
            ['6287844210066', 'Nur Aini', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::TanyaHarga, SkorProspek::Cold, 'Malang', '2026-09-25 11:00', false, null],
            ['6282133580029', 'Ratna Sari', 'bangbana-waha', TipeCustomer::Retail, TahapFunnel::SiapOrder, SkorProspek::Hot, 'Malang', '2026-09-26 11:30', false, null],
            ['6281244870053', 'Rudi Hartono', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::CartDitinggal, SkorProspek::Warm, 'Batu', '2026-09-25 10:00', false, null],
            // Broadcast.dc.html: replied to "Promo Molreng kantin sekolah"; the reply sits on
            // the recipient row, so the CRM keeps an older chat
            ['6285855330071', 'Kopma UB', 'bangbana-waha', TipeCustomer::Reseller, TahapFunnel::TanyaProduk, SkorProspek::Warm, 'Malang', '2026-08-20 10:00', false, null],
        ];

        foreach ($customer as [$nomor, $nama, $sesi, $tipe, $tahap, $skor, $kota, $interaksi, $butuhAdmin, $alasan]) {
            CrmCustomer::create([
                'chat_id' => $nomor.'@c.us',
                'nomor_wa' => $nomor,
                'nama' => $nama,
                'sesi_wa' => $sesi,
                'tipe_customer' => $tipe,
                'tahap_funnel' => $tahap,
                'skor_prospek' => $skor,
                'kota' => $kota,
                'tgl_interaksi_terakhir' => $this->jam($interaksi),
                'butuh_admin' => $butuhAdmin,
                'alasan_butuh_admin' => $alasan,
            ]);
        }

        // Pengisi: 5 more customers waiting for an admin.
        foreach (range(1, 5) as $i) {
            CrmCustomer::create([
                'chat_id' => '62800000000'.$i.'@c.us',
                'nomor_wa' => '62800000000'.$i,
                'nama' => 'Customer demo '.$i,
                'sesi_wa' => 'bangbana-waha',
                'tipe_customer' => TipeCustomer::Retail,
                'tahap_funnel' => TahapFunnel::TanyaHarga,
                'skor_prospek' => SkorProspek::Warm,
                'tgl_interaksi_terakhir' => $this->jam('2026-09-20 09:00'),
                'butuh_admin' => true,
            ]);
        }

        // Customer.dc.html: Rina's profile, as drawn
        $rina = CrmCustomer::query()->where('nama', 'Rina Maharani')->sole();
        $rina->update([
            'minat_produk' => 'Molreng, Basreng',
            'lokasi_jualan' => 'Kantin sekolah',
            'estimasi_modal' => 1500000,
            'ringkasan_terakhir' => 'Reseller pemula untuk kantin sekolah, modal ±1,5 jt. Sudah pilih Molreng 40 & Basreng 30, kirim JNE REG ke Dinoyo. Order diteruskan ke admin.',
            'pesan_terakhir_customer' => 'JNE aja kak',
            'catatan_tetap' => 'Guru SD, ambil barang tiap awal bulan. Suka dikirim foto produk baru.',
            'catatan' => '28 Sep 13:06 · order dicatat dari chat · menunggu ACC',
            // Nego skipped
            'tahap_sejak' => array_map(fn (string $jam) => $this->jam($jam)->toIso8601String(), [
                TahapFunnel::Baru->value => '2026-09-28 13:02',
                TahapFunnel::TanyaProduk->value => '2026-09-28 13:03',
                TahapFunnel::TanyaHarga->value => '2026-09-28 13:05',
                TahapFunnel::SiapOrder->value => '2026-09-28 13:05',
                TahapFunnel::OrderDibuat->value => '2026-09-28 13:06',
            ]),
            'created_at' => $this->jam('2026-09-28 13:02'),
        ]);

        // [waktu, teks, nada], oldest first
        foreach ([
            ['2026-09-28 13:02', 'Chat pertama · 3 pesan digabung', 'gray'],
            ['2026-09-28 13:03', 'Dikenali sebagai reseller · kantin sekolah', 'green'],
            ['2026-09-28 13:03', 'Foto Molreng Reguler dikirim otomatis', 'gray'],
            ['2026-09-28 13:05', 'Ongkir dicek via Mengantar · pilih JNE REG', 'gray'],
            ['2026-09-28 13:06', 'Tahap → Order dibuat · skor HOT', 'blue'],
            ['2026-09-28 13:06', 'Order ORD-260928-014 dicatat · supervisor dikabari', 'blue'],
        ] as [$waktu, $teks, $nada]) {
            $rina->riwayat()->create(['waktu' => $this->jam($waktu), 'teks' => $teks, 'nada' => $nada]);
        }

        // Pengisi: Customer.dc.html "312 pelanggan · 205 aktif 30 hari". All of them chatted
        // before the 10 customers in the list, so none shows up there. Pipeline.dc.html:
        // the active ones fill the columns up to 42 · 53 · 37 · 9 · 11 · 14 · 26 · 5 · 8.
        $tahap = [
            ...array_fill(0, 39, TahapFunnel::Baru),
            ...array_fill(0, 49, TahapFunnel::TanyaProduk),
            ...array_fill(0, 29, TahapFunnel::TanyaHarga),
            ...array_fill(0, 7, TahapFunnel::Nego),
            ...array_fill(0, 8, TahapFunnel::SiapOrder),
            ...array_fill(0, 32, TahapFunnel::OrderDibuat),
            ...array_fill(0, 3, TahapFunnel::CartDitinggal),
            ...array_fill(0, 7, TahapFunnel::TidakMinat),
        ];
        $sampaiOrder = 0;
        $baris = [];

        foreach (range(6, 285) as $i) {
            // 174 within the last 30 days (1–22 Sep), 106 before that (Jun–Aug)
            $aktif = $i <= 179;
            $interaksi = $aktif
                ? $this->jam('2026-09-01 08:00')->addMinutes(($i - 6) * 183)
                : $this->jam('2026-06-01 08:00')->addHours(($i - 180) * 20);
            $tahapIni = $aktif ? $tahap[$i - 6] : TahapFunnel::TanyaProduk;

            // "Baru menyapa → order dibuat 18%" and "Rata-rata sampai order 2,4 hari": 29 of
            // them reached an order, 1–4 days after the first chat
            $sejak = null;

            if ($tahapIni === TahapFunnel::OrderDibuat && $sampaiOrder < 29) {
                $sejak = json_encode([
                    TahapFunnel::Baru->value => $interaksi->subDays(1 + $sampaiOrder % 4)->toIso8601String(),
                    TahapFunnel::OrderDibuat->value => $interaksi->toIso8601String(),
                ]);
                $sampaiOrder++;
            }

            $baris[] = [
                'chat_id' => '6280000'.sprintf('%05d', $i).'@c.us',
                'nomor_wa' => '6280000'.sprintf('%05d', $i),
                'nama' => 'Customer demo '.$i,
                'sesi_wa' => 'bangbana-waha',
                'tipe_customer' => TipeCustomer::Retail->value,
                'tahap_funnel' => $tahapIni->value,
                'tahap_sejak' => $sejak,
                'skor_prospek' => SkorProspek::Cold->value,
                // "Opt-out (tidak dihubungi) 5": Fikri and four who left long ago
                'status_optout' => $i >= 180 && $i < 184,
                'tgl_interaksi_terakhir' => $interaksi,
                'created_at' => $interaksi,
                'updated_at' => $interaksi,
            ];
        }

        foreach (array_chunk($baris, 100) as $potongan) {
            CrmCustomer::query()->insert($potongan);
        }
    }

    /**
     * Pengisi: photos Adiva sent this month (Gambar.dc.html "Dikirim 42× bulan ini" …),
     * spread over 1–27 Sep between the demo customers.
     */
    protected function fotoTerkirim(): void
    {
        $dikirim = [
            'Molreng Reguler Bawang Original' => 41,
            'Molreng Reguler Pedas' => 18,
            'Basreng Pedas Daun Jeruk' => 37,
            'Kripca 1,8 kg Original' => 29,
            'Kripca 1,8 kg Pedas' => 11,
            'Seblak Kering Original' => 9,
            'Makaroni Bantat Balado' => 6,
        ];
        $customer = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->get();
        $baris = [];

        foreach ($dikirim as $judul => $jumlah) {
            $gambarId = GambarProduk::query()->where('title', $judul)->value('id');

            foreach (range(0, $jumlah - 1) as $i) {
                $untuk = $customer[$i % $customer->count()];
                $waktu = $this->jam('2026-09-01 10:00')->addDays($i % 27);
                $baris[] = [
                    'crm_customer_id' => $untuk->id,
                    'chat_id' => $untuk->chat_id,
                    'sesi_wa' => $untuk->sesi_wa,
                    'waktu' => $waktu,
                    'pengirim' => PengirimPesan::Adiva->value,
                    'gambar_produk_id' => $gambarId,
                    'lampiran_keterangan' => 'Foto produk · '.$judul,
                    'status_kirim' => 'TERKIRIM',
                    'created_at' => $waktu,
                    'updated_at' => $waktu,
                ];
            }
        }

        Pesan::query()->insert($baris);
    }

    /**
     * Kebijakan.dc.html: "Dipakai N× · 30 hari" (pengisi, 30 Aug–27 Sep), the three
     * latest questions answered with Ongkir & ekspedisi, and the Uji tanya Adiva panel.
     */
    protected function pemakaianKebijakan(): void
    {
        $dipakai = [
            'Minimal order' => 31, 'Diskon' => 22, 'Ongkir & ekspedisi' => 64, 'Metode pembayaran' => 40,
            'COD' => 17, 'Jam operasional & lokasi' => 28, 'Retur & komplain' => 9, 'Halal / PIRT' => 6, 'Sampel' => 5,
        ];
        $customer = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->get();
        $baris = [];

        foreach ($dipakai as $topik => $jumlah) {
            $kebijakanId = Kebijakan::query()->where('topik', $topik)->value('id');

            foreach (range(0, $jumlah - 1) as $i) {
                $waktu = $this->jam('2026-08-30 11:00')->addDays($i % 29);
                $baris[] = [
                    'kebijakan_id' => $kebijakanId,
                    'crm_customer_id' => $customer[$i % $customer->count()]->id,
                    'pertanyaan' => null,
                    'waktu' => $waktu,
                    'created_at' => $waktu,
                    'updated_at' => $waktu,
                ];
            }
        }

        PemakaianKebijakan::query()->insert($baris);

        // newest first: 28 Sep 11:00, 10:00, 09:00
        $ongkir = Kebijakan::query()->where('topik', 'Ongkir & ekspedisi')->value('id');
        $terbaru = ['ongkir ke surabaya brp kak', 'bisa kirim pakai gosend?', 'kirim dari mana ya, malang kota?'];

        foreach ($terbaru as $i => $pertanyaan) {
            PemakaianKebijakan::query()
                ->where('kebijakan_id', $ongkir)
                ->whereNull('pertanyaan')
                ->orderBy('id')
                ->first()
                ?->update(['pertanyaan' => $pertanyaan, 'waktu' => $this->jam('2026-09-28 11:00')->subHours($i)]);
        }

        UjiAdiva::create([
            'bagian' => 'KEBIJAKAN',
            'pertanyaan' => 'ongkir ke blitar brp kak? 5 bal kripca',
            'jawaban' => 'Untuk 5 bal Kripca (± 9 kg) ke Blitar, Adiva cek dulu ongkirnya ya kak. [Jawaban mengikuti isi kebijakan setelah diisi.]',
            'catatan' => 'Tool dipakai: KB_Kebijakan (Ongkir & ekspedisi) lalu Cek_Ongkir_Mengantar',
            'kebijakan_id' => $ongkir,
            'user_id' => User::query()->where('email', 'falah@bangbana.id')->value('id'),
        ]);
    }

    /**
     * Orders.dc.html: the orders on Pesanan, exactly as listed (tabs: Semua 11 · Menunggu
     * ACC 2 · Menunggu bayar 2 · Bukti masuk 1 · Lunas 2 · Dikirim 1 · Karyawan 1 ·
     * Selesai 1 · Batal / tolak 2; the employee order comes from orderKaryawan). Sidebar
     * "Pesanan" counts the 2 waiting for ACC, not the design's 4: filler orders would
     * show up as rows on Pesanan. Times after the order follow Aktivitas.dc.html
     * (ORD-260925-004 KIRIM 09:30, ORD-260926-008 LUNAS 09:12, bukti 08:14 today).
     */
    protected function pesanan(): void
    {
        // [order, customer, tanggal, status, metode bayar, kurir, ongkir, berat kg, alamat (jalan),
        //  items [nama, qty, harga, keterangan harga], kolom lain, riwayat [waktu, teks, kolom lain]]
        $pesanan = [
            ['ORD-260928-014', 'Rina Maharani', '2026-09-28 13:06', StatusOrder::MenungguKonfirmasi, 'TRANSFER', 'JNE REG', 38000, 1.6, 'Jl. Kalpataru 18',
                [['Molreng Reguler Bawang Original', 40, 16500, 'Harga 10–49 pcs'], ['Basreng Pedas Daun Jeruk', 30, 19500, 'Harga 10+ pcs']],
                ['kelurahan' => 'Dinoyo', 'kecamatan' => 'Lowokwaru', 'kota' => 'Kota Malang', 'provinsi' => 'Jawa Timur', 'kode_pos' => '65144', 'estimasi_kirim' => '1–2 hari'],
                // OrderDetail.dc.html: the conversation before the order, then the order (Pesanan shows the short form)
                [
                    ['2026-09-28 13:02', 'Chat pertama masuk (3 pesan digabung). Dikenali sebagai reseller untuk kantin sekolah.', ['jenis' => 'PERCAKAPAN']],
                    ['2026-09-28 13:03', 'Adiva mengirim foto Molreng Reguler Bawang Original dari katalog.', ['jenis' => 'PERCAKAPAN']],
                    ['2026-09-28 13:05', 'Ongkir dicek lewat Mengantar: JNE REG Rp38.000 · SiCepat REG Rp36.000 · J&T EZ Rp40.000. Customer memilih JNE REG.', ['jenis' => 'PERCAKAPAN']],
                    ['2026-09-28 13:06', 'Dicatat Adiva dari chat · supervisor dikabari dengan tombol ACC',
                        ['jenis' => 'DICATAT', 'nada' => 'blue', 'data' => ['lengkap' => 'Adiva mencatat order dan mengabari supervisor lewat WhatsApp dengan tombol ACC.']]],
                ]],
            ['ORD-260928-012', 'Anisa Putri', '2026-09-28 11:40', StatusOrder::MenungguKonfirmasi, 'BAYAR DI TEMPAT (PICKUP)', 'AMBIL SENDIRI - Toko Sawojajar', 0, 1.8, 'Ambil di Toko Sawojajar',
                [['Kripca 1,8 kg Original', 1, 90000]], ['ambil_sendiri' => true, 'dobel_dengan' => 'ORD-260926-015'],
                [['2026-09-28 11:40', 'Dicatat Adiva · sama persis dengan ORD-260926-015 yang sudah lunas']]],
            ['ORD-260927-031', 'Dimas Pratama', '2026-09-27 19:12', StatusOrder::Diacc, 'TRANSFER', 'SiCepat REG', 36000, 2.4, 'Perum Griya Asri B-7',
                [['Seblak Kering Pedas', 24, 18750]], ['kelurahan' => 'Sukun', 'kecamatan' => 'Sukun', 'kota' => 'Kota Malang', 'provinsi' => 'Jawa Timur', 'kode_pos' => '65147', 'invoice_number' => 'INV/2026/09/138', 'diacc_at' => '2026-09-27 19:20', 'bukti_masuk_at' => '2026-09-28 08:14'],
                [['2026-09-27 19:20', 'ACC oleh Falah · invoice PDF terkirim'], ['2026-09-28 08:14', 'Bukti transfer masuk · ditandai BUKTI MASUK']]],
            // "Batas bayar 5 jam kerja · sisa 2 jam kerja" at 13:42
            ['ORD-260927-027', 'Laras Ayu', '2026-09-27 16:02', StatusOrder::Diacc, 'TRANSFER', 'J&T EZ', 22000, 0.6, 'Jl. Sultan Agung 4',
                [['Makaroni Bantat Balado', 6, 18000]], ['kelurahan' => 'Pesanggrahan', 'kecamatan' => 'Batu', 'kota' => 'Kota Batu', 'provinsi' => 'Jawa Timur', 'kode_pos' => '65313', 'invoice_number' => 'INV/2026/09/136', 'diacc_at' => '2026-09-27 16:30', 'batas_bayar_at' => '2026-09-28 15:42'],
                [['2026-09-27 16:30', 'ACC oleh Falah · invoice PDF terkirim']]],
            ['ORD-260927-019', 'Toko Sumber Rejeki', '2026-09-27 10:15', StatusOrder::Lunas, 'TRANSFER (PICKUP)', 'AMBIL SENDIRI - Pabrik Lawang', 0, 108, 'Ambil di Pabrik Lawang',
                [['Kripca 1,8 kg Original', 30, 90000], ['Kripca 1,8 kg Pedas', 30, 90000]], ['ambil_sendiri' => true, 'invoice_number' => 'INV/2026/09/131', 'diacc_at' => '2026-09-27 10:40', 'lunas_at' => '2026-09-28 13:05'],
                [['2026-09-27 10:40', 'ACC · invoice terkirim'], ['2026-09-28 13:05', 'LUNAS oleh Falah']]],
            ['ORD-260926-008', 'Bayu Saputra', '2026-09-26 20:44', StatusOrder::Lunas, 'TRANSFER', 'JNE REG', 18000, 0.5, 'Jl. Gajayana 50',
                [['Usus Crispy Pedas', 5, 19000]], ['kelurahan' => 'Ketawanggede', 'kecamatan' => 'Lowokwaru', 'kota' => 'Kota Malang', 'kode_pos' => '65145', 'invoice_number' => 'INV/2026/09/127', 'diacc_at' => '2026-09-26 21:00', 'lunas_at' => '2026-09-28 09:12'],
                [['2026-09-26 21:00', 'ACC · invoice terkirim'], ['2026-09-28 09:12', 'LUNAS oleh staf Dina']]],
            ['ORD-260925-004', 'Maya Kusuma', '2026-09-25 14:20', StatusOrder::Dikirim, 'TRANSFER', 'JNE REG', 30000, 2.0, 'Jl. Raya Tlogomas 9',
                [['Kentang Balado', 20, 18000]], ['kota' => 'Kota Malang', 'kode_pos' => '65144', 'invoice_number' => 'INV/2026/09/119', 'no_resi' => 'CGK0028819300', 'diacc_at' => '2026-09-25 14:40', 'lunas_at' => '2026-09-25 16:02', 'dikirim_at' => '2026-09-28 09:30'],
                [['2026-09-25 14:40', 'ACC'], ['2026-09-25 16:02', 'LUNAS'], ['2026-09-28 09:30', 'KIRIM · resi dikirim ke customer']]],
            ['ORD-260921-006', 'Bu Wati', '2026-09-21 09:02', StatusOrder::Selesai, 'BAYAR DI TEMPAT (PICKUP)', 'AMBIL SENDIRI - Toko Sawojajar', 0, null, 'Ambil di Toko Sawojajar',
                [['Seblak Kering Original', 10, 18600]], ['ambil_sendiri' => true, 'invoice_number' => 'INV/2026/09/101', 'diacc_at' => '2026-09-21 09:30', 'selesai_at' => '2026-09-22 10:00'],
                [['2026-09-22 10:00', 'SELESAI · bot minta ulasan (LINK_ULASAN) · CRM → REPEAT']]],
            ['ORD-260926-010', 'Fikri Ramadhan', '2026-09-26 11:11', StatusOrder::Dibatalkan, 'TRANSFER', 'SiCepat REG', 20000, null, null,
                [['Kerupuk Rujak', 8, 12000]], ['invoice_number' => 'INV/2026/09/124', 'diacc_at' => '2026-09-26 11:30'],
                [['2026-09-26 12:00', 'BATAL · customer minta batal sebelum bayar']]],
            ['ORD-260924-002', 'Sinta', '2026-09-24 08:50', StatusOrder::BatalOtomatis, 'TRANSFER', 'JNE REG', 15000, null, null,
                [['Basreng Original', 3, 19500]], ['invoice_number' => 'INV/2026/09/110', 'diacc_at' => '2026-09-24 09:00'],
                [['2026-09-25 12:50', 'Lewat batas bayar · tertutup otomatis (masih bisa di-LUNAS-kan ≤ 3 hari bila dana masuk)']]],
        ];

        foreach ($pesanan as [$orderId, $nama, $tanggal, $status, $metode, $kurir, $ongkir, $berat, $alamat, $item, $lain, $riwayat]) {
            $customer = CrmCustomer::query()->where('nama', $nama)->sole();
            $order = LogOrder::create([
                'order_id' => $orderId,
                'crm_customer_id' => $customer->id,
                'chat_id' => $customer->chat_id,
                'nomor_wa' => $customer->nomor_wa,
                'sesi_wa' => $customer->sesi_wa,
                'nama_penerima' => $nama,
                'tanggal' => $this->jam($tanggal),
                'status_order' => $status,
                'metode_bayar' => $metode,
                'tipe_customer' => $customer->tipe_customer,
                'total_produk' => array_sum(array_map(fn (array $baris) => $baris[1] * $baris[2], $item)),
                'ongkir' => $ongkir,
                'berat_kg' => $berat,
                'kurir_pilihan' => $kurir,
                'alamat_lengkap' => $alamat,
                ...$lain,
            ]);

            foreach ($item as $i => $baris) {
                $order->items()->create([
                    'urutan' => $i + 1,
                    'nama_produk' => $baris[0],
                    'keterangan_harga' => $baris[3] ?? null,
                    'qty' => $baris[1],
                    'harga_satuan' => $baris[2],
                    'subtotal' => $baris[1] * $baris[2],
                ]);
            }

            foreach ($riwayat as $baris) {
                $order->riwayat()->create(['waktu' => $this->jam($baris[0]), 'teks' => $baris[1], ...$baris[2] ?? []]);
            }
        }

        // Pengisi: Paket.dc.html "Terjual 14× bulan ini" for PKT-A; finished long ago, so
        // Pesanan does not list it.
        $pengisi = CrmCustomer::query()->where('nama', 'Customer demo 3')->sole();
        $order = LogOrder::create([
            'order_id' => 'ORD-260901-001',
            'crm_customer_id' => $pengisi->id,
            'chat_id' => $pengisi->chat_id,
            'nomor_wa' => $pengisi->nomor_wa,
            'sesi_wa' => 'bangbana-waha',
            'nama_penerima' => $pengisi->nama,
            'tanggal' => $this->jam('2026-09-01 09:00'),
            'status_order' => StatusOrder::Selesai,
            'metode_bayar' => 'TRANSFER',
            'tipe_customer' => 'RESELLER',
        ]);
        $order->items()->create([
            'urutan' => 1,
            'paket_reseller_id' => PaketReseller::query()->where('kode_paket', 'PKT-A')->value('id'),
            'nama_produk' => 'Paket Kantin',
            'qty' => 14,
            'harga_satuan' => 0,
            'subtotal' => 0,
        ]);
    }

    /**
     * Karyawan.dc.html: the four latest employee orders. Pengisi (older, SELESAI) make
     * the rest add up: September 23 orders, Rp1.482.000, 3 masih berjalan, and per
     * employee "Order" (all time) and "Potong gaji" (September).
     */
    protected function orderKaryawan(): void
    {
        $yoga = '6289612000077';
        $rahma = '6281255300004';
        $doni = '6285733210090';
        $lilis = '6281322900018';

        $this->catatOrderKaryawan($yoga, '2026-09-28 10:31', [['Basreng Original', 4, 24000]], StatusOrder::Karyawan, 'ORD-260928-011');
        LogOrder::query()->where('order_id', 'ORD-260928-011')->sole()->riwayat()->create([
            'waktu' => $this->jam('2026-09-28 10:31'),
            'teks' => 'Order karyawan dicatat · diteruskan ke Bu Sarah',
        ]);
        $this->catatOrderKaryawan($rahma, '2026-09-27 09:20', [['Molreng', 2, 20000], ['Basreng Pedas', 2, 26000]], StatusOrder::Karyawan, 'ORD-260927-020');
        $this->catatOrderKaryawan($lilis, '2026-09-26 08:45', [['Kripca 1,8 kg', 1, 70000]], StatusOrder::Karyawan, 'ORD-260926-017', siapDiambil: '2026-09-27 09:00');
        $this->catatOrderKaryawan($doni, '2026-09-25 13:10', [['Basreng Original', 3, 24000]], StatusOrder::Selesai, 'ORD-260925-009');

        // Pengisi: Rahma Rp168.000 and Lilis Rp140.000 in September
        $this->catatOrderKaryawan($rahma, '2026-09-15 10:00', [['Basreng Pedas', 2, 26000], ['Basreng Original', 1, 24000]], StatusOrder::Selesai);
        $this->catatOrderKaryawan($lilis, '2026-09-10 10:00', [['Kripca 1,8 kg', 1, 70000]], StatusOrder::Selesai);

        // Pengisi: August orders, so Yoga has 4, Rahma 6, Doni 3 and Lilis 5 in all
        foreach ([[$yoga, 3], [$rahma, 4], [$doni, 2], [$lilis, 3]] as [$nomor, $jumlah]) {
            foreach (range(1, $jumlah) as $i) {
                $this->catatOrderKaryawan($nomor, sprintf('2026-08-%02d 10:00', $i * 5), [['Basreng Original', 2, 24000]], StatusOrder::Selesai);
            }
        }

        // Pengisi: 17 September orders (Rp1.006.000) for the other seven employees
        $item = [
            ...array_fill(0, 9, [['Basreng Original', 2, 24000]]),
            [['Kripca 1,8 kg', 1, 70000]],
            ...array_fill(0, 7, [['Basreng Original', 3, 24000]]),
        ];
        $pengisi = Karyawan::query()->where('nama', 'like', 'Karyawan demo %')->orderBy('id')->pluck('nomor_wa');

        foreach ($item as $i => $isi) {
            $this->catatOrderKaryawan($pengisi[$i % $pengisi->count()], sprintf('2026-09-%02d 10:00', 1 + $i), $isi, StatusOrder::Selesai);
        }
    }

    /**
     * Pipeline.dc.html: what the cards say about each customer. The line under it is
     * worked out from these (PipelineController).
     */
    protected function pipeline(): void
    {
        // [minat produk, lokasi jualan, kolom lain]
        $kartu = [
            'Dewi Anggraini' => ['Tanya lokasi toko', null, []],
            'Arif Setiawan' => ['Seblak kering', null, []],
            'Salsa Billa' => ['Makaroni, Usus', 'Warung depan kampus', []],
            'Pak Joko' => ['Kerupuk Rujak', null, ['jumlah_followup' => 1]],
            'Intan Permata' => ['Kentang Balado', null, []],
            'Andika Putra' => ['Basreng', null, ['estimasi_modal' => 800000, 'tgl_followup_berikutnya' => $this->jam('2026-09-28 16:30')]],
            'Bu Wati' => ['Seblak kering', null, []],
            'Nur Aini' => ['Kripca', null, []],
            'Pak Hendra' => ['Basreng 200 pcs', 'Toko oleh-oleh', []],
            'Toko Berkah Jaya' => ['Semua varian', 'Distributor', []],
            'Mega Lestari' => ['Kripca 1,8 kg × 20', null, []],
            'Ratna Sari' => ['Molreng Reguler', null, []],
            'Anisa Putri' => ['Kripca 1,8 kg', null, []],
            'Yoga' => ['Basreng', null, []],
            'Maya Kusuma' => ['Kentang Balado', null, []],
            'Toko Sumber Rejeki' => ['Kripca 1,8 kg', null, []],
            'Bayu Saputra' => ['Usus Crispy', null, []],
            'Sinta' => ['Basreng Original', null, ['jumlah_followup' => 1, 'tahap_sejak' => [TahapFunnel::CartDitinggal->value => $this->jam('2026-09-25 12:50')->toIso8601String()]]],
            'Rudi Hartono' => ['Kripca 1,8 kg × 10', null, [
                'tahap_sejak' => [TahapFunnel::CartDitinggal->value => $this->jam('2026-09-28 09:15')->toIso8601String()],
                'tgl_followup_berikutnya' => $this->jam('2026-09-28 19:30'),
            ]],
            'Fikri Ramadhan' => ['Kerupuk Rujak', null, ['status_optout' => true, 'tahap_sejak' => [TahapFunnel::TidakMinat->value => $this->jam('2026-09-26 12:00')->toIso8601String()]]],
        ];

        foreach ($kartu as $nama => [$minat, $lokasi, $lain]) {
            CrmCustomer::query()->where('nama', $nama)->sole()->update(['minat_produk' => $minat, 'lokasi_jualan' => $lokasi, ...$lain]);
        }

        // the customer who has not said a name yet: greeted again tonight
        CrmCustomer::query()->where('nomor_wa', '6287800415120')->sole()->update(['tgl_followup_berikutnya' => $this->jam('2026-09-28 19:14')]);

        // Yoga's employee orders belong to his CRM row ("ORD-260928-011 · ke Bu Sarah")
        $yoga = CrmCustomer::query()->where('nama', 'Yoga')->sole();
        LogOrder::query()->where('nomor_wa', $yoga->nomor_wa)->update(['crm_customer_id' => $yoga->id]);

        // Pengisi: older finished orders, so Repeat reads "Lawang · 5 order", "Malang · 2 order"
        // and Maya's third order (June–July: off Pesanan and this month's figures)
        foreach (['Toko Sumber Rejeki' => 4, 'Bayu Saputra' => 1, 'Maya Kusuma' => 2] as $nama => $jumlah) {
            $customer = CrmCustomer::query()->where('nama', $nama)->sole();

            foreach (range(1, $jumlah) as $i) {
                $waktu = $this->jam(sprintf('2026-%02d-%02d 10:00', 5 + $i % 2 + 1, 4 * $i));
                LogOrder::create([
                    'order_id' => 'ORD-'.$waktu->format('ymd').'-'.++$this->urutPengisi,
                    'crm_customer_id' => $customer->id,
                    'chat_id' => $customer->chat_id,
                    'nomor_wa' => $customer->nomor_wa,
                    'sesi_wa' => $customer->sesi_wa,
                    'nama_penerima' => $customer->nama,
                    'tanggal' => $waktu,
                    'status_order' => StatusOrder::Selesai,
                    'metode_bayar' => 'TRANSFER',
                    'tipe_customer' => $customer->tipe_customer,
                    'selesai_at' => $waktu->addDays(3),
                ]);
            }
        }

        // "Baru menyapa → order dibuat" and "Rata-rata sampai order": when the others with
        // an order first chatted and when the order came (Rina's is in customer())
        foreach ([
            'Anisa Putri' => ['2026-09-27 11:40', '2026-09-28 11:40'],
            'Yoga' => ['2026-09-27 22:31', '2026-09-28 10:31'],
            'Dimas Pratama' => ['2026-09-25 19:12', '2026-09-27 19:12'],
            'Laras Ayu' => ['2026-09-24 16:02', '2026-09-27 16:02'],
            'Maya Kusuma' => ['2026-09-21 14:20', '2026-09-25 14:20'],
            'Toko Sumber Rejeki' => ['2026-09-21 10:15', '2026-09-27 10:15'],
            'Bayu Saputra' => ['2026-09-24 20:44', '2026-09-26 20:44'],
        ] as $nama => [$baru, $order]) {
            CrmCustomer::query()->where('nama', $nama)->sole()->update(['tahap_sejak' => [
                TahapFunnel::Baru->value => $this->jam($baru)->toIso8601String(),
                TahapFunnel::OrderDibuat->value => $this->jam($order)->toIso8601String(),
            ]]);
        }
    }

    /**
     * Broadcast.dc.html: the six campaigns as drawn, and three older finished ones (off
     * the list) so this month adds up: 386 terkirim of 395, 9 gagal, 68 balas, 19 order
     * worth Rp6,4 jt, 3 STOP. Replies, STOP and orders live on the recipient rows only.
     */
    protected function broadcast(): void
    {
        $customer = fn (string $nama) => CrmCustomer::query()->where('nama', $nama)->sole();
        $order = fn (string $id) => LogOrder::query()->where('order_id', $id)->sole();
        $pengisi = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->get();
        $falah = User::query()->where('email', 'falah@bangbana.id')->value('id');
        $dibuat = $this->jam('2026-09-27 09:30');

        $kampanye = function (array $isi) use (&$dibuat, $falah): Broadcast {
            $b = Broadcast::create([...$isi, 'pesan' => $isi['pesan'] ?? '[Isi pesan kampanye]', 'user_id' => $falah]);
            // newest first on the list, in the order of the design
            $b->forceFill(['created_at' => $dibuat, 'updated_at' => $dibuat])->save();
            $dibuat = $dibuat->subHours(6);

            return $b;
        };

        // [customer, order, dibalas_at, balasan, stop] for the rows that replied
        $kirim = function (Broadcast $b, array $penerima, CarbonImmutable $mulai, int $detik, array $hasil) {
            foreach (array_values($penerima) as $i => $p) {
                [$chat, $customerId, $sesi] = $p;
                $isi = $hasil[$i] ?? [];
                $status = $isi['status'] ?? BroadcastPenerima::TERKIRIM;
                $terkirim = $status === BroadcastPenerima::TERKIRIM ? $mulai->addSeconds($i * $detik) : null;

                BroadcastPenerima::create([
                    'broadcast_id' => $b->id,
                    'crm_customer_id' => $customerId,
                    'chat_id' => $chat,
                    'sesi_wa' => $sesi,
                    'status' => $status,
                    'terkirim_at' => $terkirim,
                    'dibaca_at' => ($isi['dibaca'] ?? false) && $terkirim ? $terkirim->addMinutes(2) : null,
                    'dibalas_at' => isset($isi['dibalas']) ? $this->jam($isi['dibalas']) : null,
                    'balasan' => $isi['balasan'] ?? null,
                    'log_order_id' => $isi['order'] ?? null,
                    'naik_tahap' => $isi['naik'] ?? false,
                    'jadi_hot' => $isi['hot'] ?? false,
                    'minta_stop' => $isi['stop'] ?? false,
                    'keterangan_gagal' => $isi['gagal'] ?? null,
                ]);
            }
        };
        $dari = fn (CrmCustomer $c) => [$c->chat_id, $c->id, $c->sesi_wa];

        // Hasil per recipient, by position: who read, replied, ordered, moved up, failed
        $hasil = function (int $jumlah, int $terkirim, int $gagal, int $dibaca, array $balas, int $naik, int $hot, string $alasan = '') {
            $baris = [];

            foreach (range(0, $jumlah - 1) as $i) {
                $baris[$i] = match (true) {
                    $i < $terkirim => ['dibaca' => $i < $dibaca, 'naik' => $i < $naik, 'hot' => $i < $hot],
                    $i < $terkirim + $gagal => ['status' => BroadcastPenerima::GAGAL, 'gagal' => $alasan],
                    default => ['status' => BroadcastPenerima::MENUNGGU],
                };
            }

            foreach ($balas as $i => $isi) {
                $baris[$i] = [...$baris[$i], ...$isi];
            }

            return $baris;
        };

        // Restock Kripca untuk reseller: 38 · 37 terkirim · 30 dibaca · 11 balas · 5 order
        $restock = $kampanye([
            'nama' => 'Restock Kripca untuk reseller', 'segmen_keterangan' => 'Reseller yang pernah order atau tanya harga · minat Kripca · Malang Raya',
            'status' => StatusBroadcast::Selesai, 'jumlah_target' => 38,
            'jadwal_at' => $this->jam('2026-09-27 10:00'), 'mulai_at' => $this->jam('2026-09-27 10:00'), 'selesai_at' => $this->jam('2026-09-27 10:21'),
        ]);
        $orang = [...array_map(fn (string $n) => $dari($customer($n)), ['Maya Kusuma', 'Toko Sumber Rejeki', 'Andika Putra', 'Dimas Pratama', 'Laras Ayu', 'Anisa Putri', 'Bayu Saputra']),
            ...$pengisi->slice(0, 31)->map($dari)->all()];
        $kirim($restock, $orang, $this->jam('2026-09-27 10:00'), 34, $hasil(38, 37, 1, 30, [
            0 => ['dibalas' => '2026-09-27 10:42', 'balasan' => 'Kak aku ambil Kripca 1,8 kg 20 ya, yg pedas 10', 'order' => $order('ORD-260925-004')->id],
            1 => ['dibalas' => '2026-09-27 11:05', 'balasan' => 'Harga 30 pcs masih sama kak?'],
            2 => ['dibalas' => '2026-09-27 11:31', 'balasan' => 'STOP', 'stop' => true],
            3 => ['dibalas' => '2026-09-27 10:20', 'order' => $order('ORD-260927-031')->id],
            4 => ['dibalas' => '2026-09-27 10:25', 'order' => $order('ORD-260927-027')->id],
            5 => ['dibalas' => '2026-09-27 10:30', 'order' => $order('ORD-260928-012')->id],
            6 => ['dibalas' => '2026-09-27 10:35', 'order' => $order('ORD-260926-008')->id],
            7 => ['dibalas' => '2026-09-27 10:10'], 8 => ['dibalas' => '2026-09-27 10:12'],
            9 => ['dibalas' => '2026-09-27 10:14'], 10 => ['dibalas' => '2026-09-27 10:16'],
        ], 8, 5, 'nomor tidak aktif'));

        // Promo Molreng kantin sekolah: sending since 13:00, 30 of 48 · 22 dibaca · 6 balas · 1 order
        $promo = $kampanye([
            'nama' => 'Promo Molreng kantin sekolah', 'segmen_keterangan' => 'Reseller yang jualan di kantin/sekolah · minat Molreng',
            'status' => StatusBroadcast::Mengirim, 'jumlah_target' => 48,
            'jadwal_at' => $this->jam('2026-09-28 13:00'), 'mulai_at' => $this->jam('2026-09-28 13:00'),
        ]);
        $orang = [...array_map(fn (string $n) => $dari($customer($n)), ['Salsa Billa', 'Kopma UB', 'Rina Maharani']),
            ...$pengisi->slice(40, 45)->map($dari)->all()];
        $kirim($promo, $orang, $this->jam('2026-09-28 13:00'), 80, $hasil(48, 30, 0, 22, [
            0 => ['dibalas' => '2026-09-28 13:22', 'balasan' => 'Kak minimal order berapa ya untuk harga reseller?'],
            1 => ['dibalas' => '2026-09-28 13:30', 'balasan' => 'Ada paket campur varian?'],
            2 => ['dibalas' => '2026-09-28 13:04', 'order' => $order('ORD-260928-014')->id],
            3 => ['dibalas' => '2026-09-28 13:10'], 4 => ['dibalas' => '2026-09-28 13:12'], 5 => ['dibalas' => '2026-09-28 13:15'],
        ], 4, 2));

        $kampanye([
            'nama' => 'Win-back: 60 hari tidak order', 'segmen_keterangan' => 'Retail yang pernah order · tidak chat lebih dari 60 hari',
            'status' => StatusBroadcast::Terjadwal, 'jumlah_target' => 57, 'mode' => 'JADWAL', 'jadwal_at' => $this->jam('2026-09-30 09:00'),
        ]);
        $kampanye([
            'nama' => 'Info libur Maulid Nabi', 'segmen_keterangan' => 'Semua customer aktif 30 hari · mengikuti kalender libur',
            'status' => StatusBroadcast::Draf, 'jumlah_target' => 205,
        ]);

        // Paket reseller pemula: paused after 5 failures in a row · 17 terkirim · 13 dibaca · 3 balas
        $paket = $kampanye([
            'nama' => 'Paket reseller pemula', 'segmen_keterangan' => 'Calon reseller baru · modal di bawah Rp2 jt',
            'status' => StatusBroadcast::Dijeda, 'jumlah_target' => 41,
            'jadwal_at' => $this->jam('2026-09-26 15:00'), 'mulai_at' => $this->jam('2026-09-26 15:00'),
            'catatan' => 'Dijeda otomatis: 5 kiriman gagal beruntun (WAHA terputus). Lanjutkan setelah sesi WhatsApp tersambung.',
        ]);
        $orang = [$dari($customer('Dewi Anggraini')), ...$pengisi->slice(175, 40)->map($dari)->all()];
        $kirim($paket, $orang, $this->jam('2026-09-26 15:00'), 35, $hasil(41, 17, 5, 13, [
            0 => ['dibalas' => '2026-09-26 15:40', 'balasan' => 'Paket A isinya apa aja kak?'],
            1 => ['dibalas' => '2026-09-26 15:20', 'stop' => true],
            2 => ['dibalas' => '2026-09-26 15:25'],
        ], 2, 1, 'WAHA terputus'));

        // Harga karyawan Oktober: every employee number · 9 balas · 7 order (their September orders)
        $karyawan = $kampanye([
            'nama' => 'Harga karyawan Oktober', 'segmen_keterangan' => 'Karyawan terverifikasi',
            'status' => StatusBroadcast::Selesai, 'jumlah_target' => 12,
            'jadwal_at' => $this->jam('2026-09-25 08:00'), 'mulai_at' => $this->jam('2026-09-25 08:00'), 'selesai_at' => $this->jam('2026-09-25 08:06'),
            'catatan' => 'Order karyawan diteruskan ke Bu Sarah seperti biasa (potong gaji).',
        ]);
        $yoga = $customer('Yoga');
        $nomorKaryawan = Karyawan::query()->orderBy('id')->pluck('nomor_wa');
        $orang = $nomorKaryawan->map(fn (string $n) => [$n.'@c.us', $n === $yoga->nomor_wa ? $yoga->id : null, 'bangbana-waha'])->all();
        $balas = [];
        $jamBalas = ['08:12', '08:04', '08:05', '08:06', '08:07', '08:08', '08:09', '08:10', '08:11'];

        // employees by id: Yoga, Rahma, Doni, Lilis, Siti, then the demo ones
        foreach ($nomorKaryawan->take(9)->values() as $i => $nomor) {
            $balas[$i] = ['dibalas' => '2026-09-25 '.$jamBalas[$i]];
            // the four named employees' order after it, and the September order of three demo ones
            $orderId = match (true) {
                $i < 4 => LogOrder::query()->where('nomor_wa', $nomor)->where('tanggal', '>=', $this->jam('2026-09-25'))->orderByDesc('tanggal')->value('id'),
                $i >= 5 && $i <= 7 => LogOrder::query()->where('nomor_wa', $nomor)->where('tanggal', '>=', $this->jam('2026-09-01'))->orderBy('tanggal')->value('id'),
                default => null,
            };

            if ($orderId) {
                $balas[$i]['order'] = $orderId;
            }
        }

        $balas[0]['balasan'] = 'Basreng 4 pack mbak';
        $kirim($karyawan, $orang, $this->jam('2026-09-25 08:00'), 30, $hasil(12, 12, 0, 12, $balas, 7, 7));

        // Pengisi: three finished campaigns earlier this month, off the list
        $nilaiTertaut = (int) BroadcastPenerima::query()->join('log_order', 'log_order.id', '=', 'broadcast_penerima.log_order_id')
            ->sum(DB::raw(LogOrder::sqlTagihan('log_order')));
        $sisa = 6_400_000 - $nilaiTertaut;

        foreach ([[100, '2026-09-03 10:00'], [100, '2026-09-09 10:00'], [93, '2026-09-15 10:00']] as $k => [$jumlah, $mulai]) {
            $b = $kampanye([
                'nama' => 'Kampanye demo '.($k + 1), 'segmen_keterangan' => 'Customer demo',
                'status' => StatusBroadcast::Selesai, 'jumlah_target' => $jumlah,
                'jadwal_at' => $this->jam($mulai), 'mulai_at' => $this->jam($mulai), 'selesai_at' => $this->jam($mulai)->addHour(),
            ]);
            $orang = $pengisi->slice($k * 90, $jumlah)->map($dari)->all();
            $balas = [];

            foreach (range(0, 12) as $i) {
                $balas[$i] = ['dibalas' => $this->jam($mulai)->addMinutes(30 + $i)->format('Y-m-d H:i')];

                if ($i < 2) {
                    // two orders a campaign, priced so the 19 orders come to Rp6,4 jt
                    $n = $k * 2 + $i;
                    $untuk = $pengisi[$k * 90 + $i];
                    $tanggal = $this->jam($mulai)->addDay();
                    $balas[$i]['order'] = LogOrder::create([
                        'order_id' => 'ORD-'.$tanggal->format('ymd').'-'.++$this->urutPengisi,
                        'crm_customer_id' => $untuk->id,
                        'chat_id' => $untuk->chat_id,
                        'nomor_wa' => $untuk->nomor_wa,
                        'sesi_wa' => $untuk->sesi_wa,
                        'nama_penerima' => $untuk->nama,
                        'tanggal' => $tanggal,
                        'status_order' => StatusOrder::Selesai,
                        'metode_bayar' => 'TRANSFER',
                        'tipe_customer' => $untuk->tipe_customer,
                        'total_produk' => $n < 5 ? intdiv($sisa, 6) : $sisa - 5 * intdiv($sisa, 6),
                        'selesai_at' => $tanggal->addDays(3),
                    ])->id;
                }
            }

            if ($k === 0) {
                $balas[12]['stop'] = true;
                $balas[12]['balasan'] = 'STOP';
            }

            $kirim($b, $orang, $this->jam($mulai), 30, $hasil($jumlah, $jumlah - 1, 1, (int) ($jumlah * 0.7), $balas, 0, 0, 'nomor tidak aktif'));
        }
    }

    /**
     * BroadcastNew.dc.html "Template": Restock reseller fills the form as drawn. The
     * other three have no text in the design yet. Pengisi: 24 resellers it reaches
     * ("Perkiraan 24 orang"): the Repeat fillers and one asking the price.
     */
    protected function templateBroadcast(): void
    {
        $template = [
            ['Restock reseller', 'Restock Kripca Oktober', [
                'tipe' => [TipeCustomer::Reseller->value],
                'tahap' => [TahapFunnel::TanyaHarga->value, TahapFunnel::OrderDibuat->value],
                'skor' => [SkorProspek::Hot->value, SkorProspek::Warm->value],
                'minat' => ['Keripik kaca'],
                'kota' => 'MALANG_RAYA',
                'interaksi' => '90_HARI',
                'kecualikan_order' => true,
                'kecualikan_baru' => true,
            ], "Halo kak {nama}, stok Kripca 1,8 kg Original & Pedas sudah ready lagi minggu ini.\nUntuk reseller di {kota}, harga 30 pcs ke atas tetap sama seperti bulan lalu.\nMau Adiva siapkan pesanannya?",
                GambarProduk::query()->where('title', 'Kripca 1,8 kg Original')->value('id'), ['Mau order', 'Lihat harga']],
            ['Promo produk baru', null, null, '[Tulis pesan promo produk baru.]', null, null],
            ['Win-back', null, null, '[Tulis pesan untuk customer yang lama tidak order.]', null, null],
            ['Info libur', null, null, '[Tulis pesan info libur.]', null, null],
        ];

        foreach ($template as $i => [$nama, $namaKampanye, $filter, $pesan, $gambar, $tombol]) {
            TemplateBroadcast::create([
                'nama' => $nama,
                'nama_kampanye' => $namaKampanye,
                'filter' => $filter,
                'pesan' => $pesan,
                'gambar_produk_id' => $gambar,
                'tombol' => $tombol,
                'urutan' => $i + 1,
            ]);
        }

        $nama = ['Customer demo 94', ...array_map(fn (int $i) => 'Customer demo '.$i, range(147, 169))];
        CrmCustomer::query()->whereIn('nama', $nama)->update([
            'tipe_customer' => TipeCustomer::Reseller,
            'skor_prospek' => SkorProspek::Warm,
            'minat_produk' => 'Kripca 1,8 kg',
            'kota' => 'Malang',
        ]);

        // "Minat produk" lists the most wanted first: Makaroni has two, like its neighbours
        CrmCustomer::query()->where('nama', 'Customer demo 50')->update(['minat_produk' => 'Makaroni Bantat']);
    }

    /**
     * An employee order, picked up at the factory and paid by salary deduction
     * (Orders.dc.html: ORD-260928-011).
     *
     * @param  list<array{string, int, int}>  $item  [name, qty, unit price]
     */
    protected function catatOrderKaryawan(string $nomor, string $tanggal, array $item, StatusOrder $status, ?string $orderId = null, ?string $siapDiambil = null): void
    {
        $karyawan = Karyawan::query()->where('nomor_wa', $nomor)->sole();
        $waktu = $this->jam($tanggal);

        $order = LogOrder::create([
            'order_id' => $orderId ?? 'ORD-'.$waktu->format('ymd').'-'.++$this->urutPengisi,
            'chat_id' => $nomor.'@c.us',
            'nomor_wa' => $nomor,
            'sesi_wa' => 'bangbana-waha',
            'nama_penerima' => $karyawan->nama,
            'tanggal' => $waktu,
            'status_order' => $status,
            'metode_bayar' => 'POTONG GAJI',
            'tipe_customer' => 'KARYAWAN',
            'total_produk' => array_sum(array_map(fn (array $baris) => $baris[1] * $baris[2], $item)),
            'ongkir' => 0,
            'kurir_pilihan' => 'Diambil di pabrik',
            'ambil_sendiri' => true,
            'alamat_lengkap' => 'Diambil sesuai info grup “Anak Bang Bana”',
            'siap_diambil_at' => $siapDiambil ? $this->jam($siapDiambil) : null,
            'selesai_at' => $status === StatusOrder::Selesai ? $waktu->addDay() : null,
        ]);

        foreach ($item as $i => [$nama, $qty, $harga]) {
            $order->items()->create([
                'urutan' => $i + 1,
                'nama_produk' => $nama,
                'keterangan_harga' => 'Harga karyawan',
                'qty' => $qty,
                'harga_satuan' => $harga,
                'subtotal' => $qty * $harga,
            ]);
        }
    }

    /**
     * Sidebar "Ambil alih chat 3" and "3 customer sedang dijeda" (Jeda.dc.html: active
     * pauses), then the ended ones.
     */
    protected function jeda(): void
    {
        $jeda = [
            ['Bu Wati', '2026-09-28 10:05', '2026-09-28 14:30', 'Falah', 'Komplain kemasan bocor', true],
            ['Toko Berkah Jaya', '2026-09-27 15:00', null, 'Dina', 'Nego kontrak distributor bulanan', true],
            ['Mbak Lia', '2026-09-28 12:40', '2026-09-28 13:40', 'Falah', 'Order via telepon (CATAT manual)', false],
        ];

        foreach ($jeda as [$nama, $sejak, $sampai, $oleh, $alasan, $diberiTahu]) {
            $customer = CrmCustomer::query()->where('nama', $nama)->sole();

            JedaBot::create([
                'crm_customer_id' => $customer->id,
                'nomor' => $customer->nomor_wa,
                'chat_id' => $customer->chat_id,
                'nama' => $nama,
                'sesi_wa' => $customer->sesi_wa,
                'sejak' => $this->jam($sejak),
                'sampai' => $sampai ? $this->jam($sampai) : null,
                'oleh' => $oleh,
                'alasan' => $alasan,
                'sudah_diberi_tahu' => $diberiTahu,
            ]);
        }

        // Riwayat jeda: the four pauses ended in the last 3 days, on chats without a CRM
        // row (jeda_bot keeps the name and chat itself)
        // [nama, sejak, selesai, dijeda oleh, alasan, catatan serah terima, diakhiri oleh]
        $riwayat = [
            ['Rudi Hartono', '2026-09-27 14:02', '2026-09-27 15:10', 'Falah', 'Tanya retur', 'Retur disetujui, barang pengganti dikirim Senin', 'Falah'],
            ['Sari', '2026-09-27 09:15', '2026-09-27 09:45', 'Dina', 'Minta sampel', 'Sampel dikirim via GoSend', 'Dina'],
            ['Toko Makmur', '2026-09-26 13:00', '2026-09-27 09:00', 'Falah', 'Nego harga', 'Jeda kedaluwarsa → lanjut otomatis, supervisor dikabari', 'Sistem'],
            ['Nadia', '2026-09-25 16:20', '2026-09-25 16:52', 'Falah', 'Ubah alamat kirim', 'Alamat diperbarui di ORD-260925-006', 'Falah'],
        ];

        foreach ($riwayat as $i => [$nama, $sejak, $selesai, $oleh, $alasan, $catatan, $diakhiri]) {
            JedaBot::create([
                'chat_id' => '628990000'.($i + 1).'@c.us',
                'nama' => $nama,
                'sesi_wa' => 'bangbana-waha',
                'sejak' => $this->jam($sejak),
                'sampai' => $diakhiri === 'Sistem' ? $this->jam($selesai) : null,
                'oleh' => $oleh,
                'alasan' => $alasan,
                'sudah_diberi_tahu' => true,
                'selesai_at' => $this->jam($selesai),
                'catatan_selesai' => $catatan,
                'diakhiri_oleh' => $diakhiri,
            ]);
        }

        // Pengisi: 34 older pauses (hidden from Riwayat jeda), so the numbers at the top
        // come out as drawn: 38 pauses in 30 days averaging 47 mnt (1.330 + 456 menit),
        // and 5 continued automatically this week (Toko Makmur + 4 here)
        $pengisi = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->get();
        $sesi = [
            ...array_map(fn (int $i) => [$this->jam('2026-08-30 10:00')->addDays(intdiv($i * 7, 10)), 13, 'Falah'], range(0, 29)),
            [$this->jam('2026-09-22 10:00'), 16, 'Sistem'],
            [$this->jam('2026-09-23 10:00'), 16, 'Sistem'],
            [$this->jam('2026-09-23 15:00'), 17, 'Sistem'],
            [$this->jam('2026-09-24 10:00'), 17, 'Sistem'],
        ];

        foreach ($sesi as $i => [$sejak, $menit, $diakhiri]) {
            $customer = $pengisi[$i % $pengisi->count()];
            JedaBot::create([
                'crm_customer_id' => $customer->id,
                'chat_id' => $customer->chat_id,
                'nama' => $customer->nama,
                'sejak' => $sejak,
                'sampai' => $diakhiri === 'Sistem' ? $sejak->addMinutes($menit) : null,
                'oleh' => 'Falah',
                'alasan' => 'Tanya harga',
                'sudah_diberi_tahu' => true,
                'selesai_at' => $sejak->addMinutes($menit),
                'diakhiri_oleh' => $diakhiri,
            ]);
        }
    }

    /**
     * Aktivitas.dc.html: today's 16 lines. Falah's JEDA at 10:05 came from WhatsApp,
     * so Tim.dc.html lists "Falah · WhatsApp 0812-••••-••07 · Perintah admin".
     * Dina's change of BATAS_BAYAR_JAM (Settings.dc.html: 5 jam kerja) is the one
     * that can still be put back.
     */
    protected function aktivitas(): void
    {
        $batasBayar = ConfigToko::query()->where('kunci', 'BATAS_BAYAR_JAM')->sole();

        $staf = fn (string $nama) => User::query()->where('name', $nama)->sole();
        $order = fn (string $id) => LogOrder::query()->where('order_id', $id)->sole();
        $customer = fn (string $nama) => CrmCustomer::query()->where('nama', $nama)->sole();
        $wati = JedaBot::query()->where('nama', 'Bu Wati')->sole();

        $log = [
            ['13:42', 'Falah', SumberAktivitas::Panel, KategoriAktivitas::Pesanan, 'ACC order', 'ORD-260928-014 · invoice INV/2026/09/142 terkirim', $order('ORD-260928-014'), []],
            ['13:40', 'Falah', SumberAktivitas::Panel, KategoriAktivitas::Login, 'Masuk', 'Chrome · Windows · Malang', $staf('Falah'), ['perangkat' => 'Chrome · Windows']],
            ['13:21', 'Sistem', SumberAktivitas::Sistem, KategoriAktivitas::Otomasi, 'Balasan gagal', 'Nisa Aulia · WAHA timeout · supervisor dikabari', $customer('Nisa Aulia'), []],
            ['13:15', 'Sistem', SumberAktivitas::Sistem, KategoriAktivitas::Otomasi, 'Cek batas bayar', 'Tidak ada order yang jatuh tempo', null, []],
            ['12:48', 'Adiva', SumberAktivitas::Adiva, KategoriAktivitas::Percakapan, 'Teruskan ke admin', 'Pak Hendra · nego harga distributor', $customer('Pak Hendra'), []],
            ['12:10', 'Dina', SumberAktivitas::Panel, KategoriAktivitas::Pengaturan, 'Ubah pengaturan', 'Batas bayar (kirim): 4 → 5 jam kerja', $batasBayar, ['kunci' => 'BATAS_BAYAR_JAM', 'sebelum' => '4', 'sesudah' => '5', 'perangkat' => 'Chrome · Android']],
            ['11:52', 'Sistem', SumberAktivitas::Sistem, KategoriAktivitas::Otomasi, 'Nomor bot tersambung lagi', 'Nomor Lawang · putus ± 22 menit · supervisor dikabari', null, []],
            ['11:50', 'Adiva', SumberAktivitas::Adiva, KategoriAktivitas::Jeda, 'Jawab dengan jeda aktif', 'Bu Wati · pesan disimpan, tidak dibalas', $wati, []],
            ['10:31', 'Adiva', SumberAktivitas::Adiva, KategoriAktivitas::Pesanan, 'Catat order karyawan', 'ORD-260928-011 · Yoga · diteruskan ke Bu Sarah', $order('ORD-260928-011'), []],
            ['10:05', 'Falah', SumberAktivitas::WhatsApp, KategoriAktivitas::Jeda, 'JEDA customer', 'Bu Wati sampai 14:30 · komplain kemasan bocor', $wati, []],
            ['09:30', 'Dina', SumberAktivitas::Panel, KategoriAktivitas::Pesanan, 'KIRIM order', 'ORD-260925-004 · resi CGK0028819300', null, []],
            ['09:15', 'Sistem', SumberAktivitas::Sistem, KategoriAktivitas::Pesanan, 'Tutup order belum di-ACC', '2 order lewat 24 jam kerja · rekap ke supervisor', null, []],
            ['09:12', 'Dina', SumberAktivitas::Panel, KategoriAktivitas::Pesanan, 'LUNAS order', 'ORD-260926-008 · Rp113.000', null, []],
            ['09:00', 'Sistem', SumberAktivitas::Sistem, KategoriAktivitas::Jeda, 'Pengingat jeda', '3 customer masih dijeda · dikirim ke supervisor', null, []],
            ['08:14', 'Adiva', SumberAktivitas::Adiva, KategoriAktivitas::Pesanan, 'Bukti transfer masuk', 'ORD-260927-031 · ditandai bukti masuk', null, []],
            ['08:02', 'Dina', SumberAktivitas::Panel, KategoriAktivitas::Login, 'Masuk', 'Chrome · Android · kode WhatsApp', $staf('Dina'), ['perangkat' => 'Chrome · Android']],
        ];

        foreach ($log as [$jam, $pelaku, $sumber, $kategori, $aksi, $detail, $subjek, $data]) {
            $oleh = in_array($pelaku, ['Adiva', 'Sistem'], true) ? null : $staf($pelaku);

            ActivityLog::create([
                'waktu' => $this->jam('2026-09-28 '.$jam),
                'user_id' => $oleh?->id,
                'pelaku' => $pelaku,
                'sumber' => $sumber,
                'kategori' => $kategori->value,
                'aksi' => $aksi,
                'detail' => $detail,
                'subjek_type' => $subjek?->getMorphClass(),
                'subjek_id' => $subjek?->getKey(),
                'data' => $data === [] ? null : $data,
            ]);
        }
    }

    /**
     * Sidebar "Monitoring 2" (Monitoring.dc.html: Jaring pengaman, "Perlu tindakan").
     */
    protected function insiden(): void
    {
        $chatbot = Workflow::query()->where('kode', 'chatbot')->sole();
        $nisa = CrmCustomer::query()->where('nama', 'Nisa Aulia')->sole();

        WorkflowEvent::create([
            'workflow_id' => $chatbot->id,
            'waktu' => $this->jam('2026-09-28 13:21'),
            'jenis' => 'INSIDEN',
            'status' => 'PERLU_TINDAKAN',
            'judul' => 'Balasan gagal terkirim · Nisa Aulia',
            'detail' => 'WAHA timeout · dicoba ulang 1× · supervisor dikabari isi balasan untuk dikirim manual',
            'aksi' => 'Kirim manual',
            'crm_customer_id' => $nisa->id,
        ]);
        WorkflowEvent::create([
            'workflow_id' => $chatbot->id,
            'waktu' => $this->jam('2026-09-28 11:47'),
            'jenis' => 'INSIDEN',
            'status' => 'PERLU_TINDAKAN',
            'judul' => 'AI gagal total · +62 812-••••-3321',
            'detail' => 'Memori chat direset · pesan maaf terkirim · supervisor dikabari',
            'aksi' => 'Balas manual',
            'data' => ['jenis' => 'AI_GAGAL_TOTAL'],
        ]);

        // Jaring pengaman: the failures caught and handled today
        // [workflow, waktu, judul, detail]
        foreach ([
            ['chatbot', '12:02', 'Model utama error (kuota)', 'Pindah otomatis ke model cadangan · customer tetap dijawab'],
            ['pantau_koneksi', '11:52', 'Nomor Lawang terputus ± 22 menit', 'Pantau Koneksi · 2× cek gagal → supervisor dikabari lewat Nomor Utama · tersambung lagi 11:52'],
            ['chatbot', '10:40', 'Invoice PDF gagal · ORD-260927-027', '2× percobaan gagal → invoice dikirim versi teks · admin dikabari'],
            ['chatbot', '09:05', 'Daftar jeda gagal dibaca', 'Supervisor dikabari supaya tidak ada customer dijeda yang disela bot'],
            ['chatbot', '08:12', 'Nomor asli customer tidak ditemukan (ID @lid)', 'Chat tetap dijawab memakai ID chat'],
            ['batas_bayar', '07:15', 'Workflow Batas Bayar Order gagal', 'Notifikasi Error mengirim detail ke supervisor · putaran 08:15 berjalan normal'],
        ] as [$kode, $jam, $judul, $detail]) {
            WorkflowEvent::create([
                'workflow_id' => Workflow::query()->where('kode', $kode)->value('id'),
                'waktu' => $this->jam("2026-09-28 {$jam}"),
                'jenis' => 'INSIDEN',
                'status' => 'TERTANGANI',
                'judul' => $judul,
                'detail' => $detail,
            ]);
        }
    }

    /**
     * Monitoring.dc.html, the last 24 hours: the chatbot's runs per hour as drawn (an
     * ERROR run hit an error branch and was handled), the scheduled workflows' runs, so
     * "Eksekusi workflow 1.284 · 17 error" adds up, and the incoming messages per route.
     */
    protected function monitoring(): void
    {
        $wf = Workflow::query()->pluck('id', 'kode');
        $baris = [];
        $eksekusi = function (string $kode, CarbonImmutable $waktu, string $status, string $judul, ?array $data = null, ?int $durasi = null, ?int $customerId = null) use ($wf, &$baris) {
            $baris[] = [
                'workflow_id' => $wf[$kode], 'waktu' => $waktu, 'jenis' => 'EKSEKUSI', 'status' => $status, 'judul' => $judul,
                'crm_customer_id' => $customerId, 'durasi_ms' => $durasi, 'data' => $data ? json_encode($data) : null,
                'created_at' => $waktu, 'updated_at' => $waktu,
            ];
        };
        // Main.dc.html: today's chats come from 61 customers; 19 of today's and 77 of
        // yesterday afternoon's went to an admin
        $pengisi = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->pluck('id');
        $diteruskan = ['hari_ini' => 0, 'kemarin' => 0];

        // Eksekusi per jam: 14–23 yesterday, 00–13 today. 13:41 and the failed Batas Bayar
        // run at 07:15 come from otomasi().
        $sukses = [10, 6, 2, 1, 1, 4, 12, 30, 52, 70, 82, 88, 74, 60, 64, 76, 70, 58, 52, 70, 84, 76, 50, 22];
        $error = [0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 2, 1, 3, 1, 0, 1, 2, 0, 1, 1, 2, 1, 0, 0];
        $n = 0;
        $cadangan = 0;
        $ongkir = 0;

        foreach (range(0, 23) as $jam) {
            $awal = $this->jam(sprintf('2026-09-%02d %02d:00', $jam >= 14 ? 27 : 28, $jam));
            $jumlahSukses = $sukses[$jam] - ($jam === 13 ? 1 : 0);
            $jumlahError = $error[$jam] - ($jam === 7 ? 1 : 0);
            $total = $jumlahSukses + $jumlahError;
            $detik = ($jam === 13 ? 40 : 60) * 60;

            foreach (range(0, $total - 1) as $i) {
                if ($total === 0) {
                    break;
                }

                $waktu = $awal->addSeconds(intdiv($i * $detik, $total));
                $hariIni = $jam < 14;
                $untuk = $hariIni ? $pengisi[$n % 61] : $pengisi[$n % $pengisi->count()];

                if ($i < $jumlahError) {
                    $eksekusi('chatbot', $waktu, 'ERROR', 'Error tertangani', ['jalur' => 'D', 'pesan' => 1], null, $untuk);

                    continue;
                }

                // median 12 dtk per reply, model p50 1,4 dtk; today 7 on the backup model, 42 shipping checks
                $kunci = $hariIni ? 'hari_ini' : 'kemarin';
                $keAdmin = $diteruskan[$kunci] < ($hariIni ? 19 : 77) && $n % ($hariIni ? 25 : 8) === 4;
                $diteruskan[$kunci] += (int) $keAdmin;
                $pakaiCadangan = $hariIni && $cadangan < 7 && $n % 50 === 0;
                $cekOngkir = $hariIni && $ongkir < 42 && $n % 10 === 3;
                $cadangan += (int) $pakaiCadangan;
                $ongkir += (int) $cekOngkir;
                // Pesan masuk per jalur: 69 runs took an admin command, an attachment, a paused
                // chat or something ignored; 160 merged two messages (D 1.238 in all)
                $jalur = $n < 69 ? [...array_fill(0, 23, 'A'), ...array_fill(0, 14, 'C'), ...array_fill(0, 9, 'B'), ...array_fill(0, 23, 'DIABAIKAN')][$n] : 'D';
                $eksekusi('chatbot', $waktu, 'BERHASIL', 'Pesan dijawab', [
                    'jalur' => $jalur,
                    'pesan' => $n >= 69 && $n < 229 ? 2 : 1,
                    'model' => $pakaiCadangan ? 'gemini-flash-latest' : 'gemini-3.6-flash',
                    'model_ms' => [1400, 1100, 1800][$n % 3],
                    'ongkir' => $cekOngkir,
                    'diteruskan' => $keAdmin,
                ], [12000, 10000, 14000][$n % 3], $untuk);
                $n++;
            }
        }

        // the scheduled workflows over the same 24 hours (times already seeded are skipped)
        $ada = WorkflowEvent::query()->where('jenis', 'EKSEKUSI')->get(['workflow_id', 'waktu'])
            ->map(fn (WorkflowEvent $e) => $e->workflow_id.'@'.$e->waktu->format('Y-m-d H:i'))->all();
        $jadwal = function (string $kode, string $mulai, int $menit, int $kali, string $judul, ?callable $boleh = null) use ($wf, $ada, $eksekusi) {
            foreach (range(0, $kali - 1) as $i) {
                $waktu = $this->jam($mulai)->addMinutes($i * $menit);

                if (! in_array($wf[$kode].'@'.$waktu->format('Y-m-d H:i'), $ada, true) && ($boleh === null || $boleh($waktu))) {
                    $eksekusi($kode, $waktu, 'BERHASIL', $judul);
                }
            }
        };
        $jadwal('pantau_koneksi', '2026-09-27 13:45', 15, 96, 'Cek koneksi');
        $jadwal('batas_bayar', '2026-09-27 14:15', 60, 24, 'Cek batas bayar');
        // "kirim hanya 07.00–21.00"
        $jadwal('prospek_ulang', '2026-09-27 14:30', 60, 24, 'Sapaan prospek', fn (CarbonImmutable $w) => $w->hour >= 7 && $w->hour <= 21);

        foreach (array_chunk($baris, 500) as $potongan) {
            WorkflowEvent::query()->insert($potongan);
        }

        WorkflowEvent::query()->where('workflow_id', $wf['chatbot'])->where('waktu', $this->jam('2026-09-28 13:41'))
            ->update(['crm_customer_id' => $pengisi[60], 'data' => json_encode(['jalur' => 'D', 'pesan' => 1, 'model' => 'gemini-3.6-flash', 'model_ms' => 1400])]);

        // Arsip data · "18 order"; Nomor Lawang back at 11:52 ("sempat putus 11:30–11:52")
        WorkflowEvent::query()->where('workflow_id', $wf['arsip_data'])->where('jenis', 'EKSEKUSI')->update(['data' => json_encode(['order' => 18, 'customer' => 4])]);
        WorkflowEvent::create([
            'workflow_id' => $wf['pantau_koneksi'],
            'waktu' => $this->jam('2026-09-28 11:52'),
            'jenis' => 'KONEKSI',
            'status' => 'TERHUBUNG',
            'judul' => 'Nomor Lawang tersambung',
            'data' => ['sesi' => 'bangbana-2'],
        ]);
    }

    /**
     * Main.dc.html. "Volume chat · 30 hari": the chatbot's runs per day, shaped like the
     * drawn curve × 25 (30 Aug–26 Sep), and yesterday until 13:42 (446 chats, 23 to an
     * admin), so today reads "+12% dari kemarin" and "96,2% · +1,4 poin". "Tipe
     * customer": the active fillers spread so the 205 come to Retail 100, Reseller 78,
     * Belum jelas 15, Karyawan 12. The Perlu tindakan items point at their customer.
     */
    protected function ringkasan(): void
    {
        $chatbot = Workflow::query()->where('kode', 'chatbot')->value('id');
        $pengisi = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->pluck('id');
        $chat = [22, 28, 35, 44, 41, 36, 48, 52, 40, 31, 35, 43, 45, 38, 33, 37, 44, 50, 58, 62, 51, 43, 39, 35, 38, 41, 37, 33];
        $admin = [2, 3, 3, 4, 3, 2, 5, 4, 3, 2, 2, 4, 5, 3, 2, 3, 4, 3, 5, 6, 4, 3, 3, 2, 3, 4, 3, 2];
        $hari = [];

        foreach ($chat as $i => $nilai) {
            // [day, from, until, chats, to an admin]
            $hari[] = [$this->jam('2026-08-30 07:00')->addDays($i), 14 * 3600, $nilai * 25, $admin[$i] * 25];
        }

        $hari[] = [$this->jam('2026-09-27 00:00'), 13 * 3600 + 41 * 60, 446, 23];
        $baris = [];
        $n = 0;

        foreach ($hari as [$mulai, $rentang, $jumlah, $keAdmin]) {
            foreach (range(0, $jumlah - 1) as $i) {
                $waktu = $mulai->addSeconds(intdiv($i * $rentang, $jumlah));
                $baris[] = [
                    'workflow_id' => $chatbot, 'waktu' => $waktu, 'jenis' => 'EKSEKUSI', 'status' => 'BERHASIL', 'judul' => 'Pesan dijawab',
                    'crm_customer_id' => $pengisi[$n % $pengisi->count()], 'durasi_ms' => [12000, 10000, 14000][$n % 3],
                    'data' => json_encode(['model' => 'gemini-3.6-flash', 'model_ms' => [1400, 1100, 1800][$n % 3], 'diteruskan' => $i < $keAdmin]),
                    'created_at' => $waktu, 'updated_at' => $waktu,
                ];
                $n++;
            }
        }

        foreach (array_chunk($baris, 1000) as $potongan) {
            WorkflowEvent::query()->insert($potongan);
        }

        // Tipe customer: 43 more resellers, 13 not yet clear and 11 employees among the
        // active fillers (the stage stays, so Pipeline and Pelanggan keep their numbers)
        $sebaran = [
            TipeCustomer::Reseller->value => range(6, 48),
            TipeCustomer::BelumJelas->value => range(49, 61),
            TipeCustomer::Karyawan->value => range(62, 72),
        ];

        foreach ($sebaran as $tipe => $nomor) {
            CrmCustomer::query()->whereIn('nama', array_map(fn (int $i) => 'Customer demo '.$i, $nomor))->update(['tipe_customer' => $tipe]);
        }

        // Perlu tindakan: Pak Hendra's question, and the proof that came in for ORD-260927-031
        CrmCustomer::query()->where('nama', 'Pak Hendra')->update(['pesan_terakhir_customer' => 'kalau ambil 200 pcs basreng bisa harga distributor?']);
        Notifikasi::query()->where('jenis', JenisNotifikasi::BuktiMasuk->value)->update([
            'crm_customer_id' => CrmCustomer::query()->where('nama', 'Dimas Pratama')->value('id'),
            'log_order_id' => LogOrder::query()->where('order_id', 'ORD-260927-031')->value('id'),
        ]);
    }

    /**
     * Inbox.dc.html: the five threads as drawn, and what the panel on the right reads
     * about each customer. The number without a name chatted on 26 Sep (as on Pipeline
     * and Pelanggan) through an @lid chat ID.
     */
    protected function percakapan(): void
    {
        $customer = fn (string $nama) => CrmCustomer::query()->where('nama', $nama)->sole();
        $falah = User::query()->where('email', 'falah@bangbana.id')->value('id');
        $molreng = GambarProduk::query()->where('title', 'Molreng Reguler Bawang Original')->value('id');

        $tanpaNama = CrmCustomer::query()->where('nomor_wa', '6287800415120')->sole();
        $tanpaNama->update(['chat_id' => '183920475610245@lid', 'ringkasan_terakhir' => 'Baru menyapa, belum menyebut kebutuhan.']);
        $customer('Pak Hendra')->update(['ringkasan_terakhir' => 'Pemilik toko oleh-oleh di Batu. Minta harga distributor untuk 200 pcs basreng, rutin tiap bulan.']);
        $customer('Bu Wati')->update([
            'ringkasan_terakhir' => 'Komplain kemasan bocor pada pesanan minggu lalu. Ditangani manual oleh Falah.',
            'catatan_tetap' => 'Pernah komplain kemasan, beri kompensasi jika repeat.',
        ]);
        $customer('Yoga')->update([
            'kota' => 'Malang',
            'ringkasan_terakhir' => 'Karyawan terverifikasi (tabel KARYAWAN). Pesan 4 pack basreng harga karyawan, bayar potong gaji. Order diteruskan ke Bu Sarah.',
        ]);

        // [pengirim, teks, waktu, foto, tombol]; a system line takes the time of the line before
        $utas = [
            [$customer('Rina Maharani'), '2026-09-28', [
                ['c', "kak mau tanya paket reseller buat kantin sekolah\nmodal sekitar 1,5jt\nyg laris apa ya", '13:02'],
                ['s', '3 pesan beruntun digabung · buffer 8 detik'],
                ['b', 'Halo kak Rina, salam kenal ya. Untuk kantin sekolah dengan modal sekitar 1,5 juta, yang paling cepat habis biasanya Molreng Reguler dan Basreng Pedas Daun Jeruk. Mau Adiva kirimkan foto dan harga bertingkatnya?', '13:03', null, ['Mau, kirim foto', 'Lihat paket reseller']],
                ['c', 'Mau, kirim foto', '13:03'],
                ['b', 'Molreng Reguler Bawang Original — harga turun di pembelian 10+ dan 50+ pcs ya kak.', '13:03', 'Molreng Reguler Bawang Original'],
                ['c', 'ok aku ambil molreng 40, basreng 30. kirim ke Dinoyo Lowokwaru ya', '13:05'],
                ['s', 'Tool dipakai: Cari_Produk_per_Kategori · Cek_Ongkir_Mengantar'],
                ['b', "Siap kak. Ongkir dari Sawojajar ke Dinoyo (±1,6 kg):\n• JNE REG Rp38.000 (2–3 hari)\n• SiCepat REG Rp35.000\n• GoSend Instant Rp27.000\nMau pakai kurir yang mana?", '13:05'],
                ['c', 'JNE aja kak', '13:06'],
                ['b', "Ringkasan pesanan kak Rina:\n• Molreng Reguler Bawang Original × 40\n• Basreng Pedas Daun Jeruk × 30\nTotal produk Rp1.245.000 + ongkir Rp38.000\nPesanan sudah diteruskan ke admin untuk dicek, invoice menyusul ya kak.", '13:06'],
                ['s', 'ORD-260928-014 dicatat · supervisor dikabari untuk ACC'],
            ]],
            [$customer('Pak Hendra'), '2026-09-28', [
                ['c', 'sore kak, saya punya toko oleh2 di Batu', '12:46'],
                ['c', 'kalau ambil 200 pcs basreng bisa harga distributor?', '12:47'],
                ['s', '2 pesan beruntun digabung · buffer 8 detik'],
                ['b', 'Sore Pak Hendra, terima kasih sudah menghubungi Bang Bana. Untuk pembelian 200 pcs rutin, harga khususnya ditentukan langsung oleh admin kami ya Pak. Adiva sudah teruskan, admin akan segera menghubungi Bapak.', '12:48'],
                ['s', 'butuh_admin = true · notifikasi terkirim ke supervisor (ADMIN_WA)'],
            ]],
            [$customer('Bu Wati'), '2026-09-28', [
                ['c', 'mbak seblak yg kemarin bungkusnya bocor 2', '10:01'],
                ['b', 'Mohon maaf sekali atas ketidaknyamanannya, Bu Wati. Adiva sudah teruskan ke admin agar segera ditangani ya.', '10:02'],
                ['s', 'Bot dijeda oleh Falah · 10:05 s/d 14:30 · alasan: komplain kemasan'],
                ['a', 'Bu Wati, saya Falah dari Bang Bana. Mohon maaf ya Bu, kami ganti 2 bungkus di pengiriman berikutnya tanpa biaya.', '10:07'],
                ['c', 'iya mbak ditunggu kabarnya', '11:52'],
                ['s', 'Customer sedang dijeda — bot diam, supervisor diberi tahu ada chat baru'],
            ]],
            [$customer('Yoga'), '2026-09-28', [
                ['c', 'mbak aku karyawan produksi, mau ambil basreng 4 pack harga karyawan', '10:29'],
                ['s', 'Tool dipakai: KB_Harga_Karyawan · nomor cocok dengan tabel KARYAWAN'],
                ['b', 'Siap mas Yoga. 4 pack Basreng Original harga karyawan totalnya Rp96.000, dibayar lewat potong gaji ya. Info pengambilan nanti diumumkan di grup Anak Bang Bana.', '10:30', null, ['Ya, konfirmasi', 'Ubah pesanan']],
                ['c', 'potong gaji aja ya mbak', '10:31'],
                ['s', 'ORD-260928-011 dicatat · diteruskan ke Bu Sarah (SARAH_WA)'],
            ]],
            [$tanpaNama->refresh(), '2026-09-26', [
                ['s', 'Nomor HP asli dicari dari ID @lid (Cari Nomor dari LID)', '09:13'],
                ['c', 'P', '09:13'],
                ['c', 'kak', '09:13'],
                ['b', 'Halo kak, dengan Adiva dari Bang Bana. Camilannya mau untuk dijual lagi atau dinikmati sendiri?', '09:14', null, ['Untuk dijual lagi', 'Untuk sendiri']],
            ]],
        ];

        $pengirim = ['c' => PengirimPesan::Customer, 'b' => PengirimPesan::Adiva, 'a' => PengirimPesan::Admin, 's' => PengirimPesan::Sistem];

        // Main.dc.html: Nisa's reply WAHA did not take ("Isi balasan tersimpan"), so Kirim
        // ulang has something to send. She has no message of her own, so the list keeps five.
        $nisa = $customer('Nisa Aulia');
        Pesan::create([
            'crm_customer_id' => $nisa->id,
            'chat_id' => $nisa->chat_id,
            'sesi_wa' => $nisa->sesi_wa,
            'waktu' => $this->jam('2026-09-28 13:21'),
            'pengirim' => PengirimPesan::Adiva,
            'teks' => 'Baik kak Nisa, Adiva cek dulu stoknya ya.',
            'status_kirim' => 'GAGAL',
            'meta' => ['galat' => 'WAHA timeout setelah 2× coba'],
        ]);

        foreach ($utas as [$c, $tanggal, $baris]) {
            $waktu = null;

            foreach ($baris as $i => $b) {
                // a second apart, so lines in the same minute keep their order
                $waktu = isset($b[2]) ? $this->jam("{$tanggal} {$b[2]}")->addSeconds($i) : $waktu->addSeconds(1);
                Pesan::create([
                    'crm_customer_id' => $c->id,
                    'chat_id' => $c->chat_id,
                    'sesi_wa' => $c->sesi_wa,
                    'waktu' => $waktu,
                    'pengirim' => $pengirim[$b[0]],
                    'user_id' => $b[0] === 'a' ? $falah : null,
                    'teks' => $b[1],
                    'gambar_produk_id' => isset($b[3]) ? $molreng : null,
                    'lampiran_keterangan' => isset($b[3]) ? 'Foto produk · '.$b[3] : null,
                    'tombol' => $b[4] ?? null,
                    'status_kirim' => $b[0] === 's' ? null : 'TERKIRIM',
                ]);
            }
        }
    }

    /**
     * Notifikasi.dc.html, today back to Friday. The four unread rows at the top are the
     * Topbar bell "4".
     */
    protected function notifikasi(): void
    {
        $customer = fn (string $nama) => CrmCustomer::query()->where('nama', $nama)->value('id');
        $order = fn (string $id) => LogOrder::query()->where('order_id', $id)->value('id');

        // [waktu, jenis, judul, detail, customer, order, dibaca]
        $notifikasi = [
            ['2026-09-28 13:30', JenisNotifikasi::JedaChat, 'Customer dijeda mengirim chat', 'Bu Wati · dijeda Falah sampai 14:30', $customer('Bu Wati'), null, false],
            ['2026-09-28 13:21', JenisNotifikasi::BalasanGagal, 'Balasan gagal terkirim', 'Nisa Aulia · WAHA timeout setelah 2× coba', $customer('Nisa Aulia'), null, false],
            ['2026-09-28 13:06', JenisNotifikasi::OrderBaru, 'Order baru menunggu ACC', 'Rina Maharani · ORD-260928-014 · Rp1.283.000', $customer('Rina Maharani'), $order('ORD-260928-014'), false],
            ['2026-09-28 12:48', JenisNotifikasi::ButuhAdmin, 'Butuh admin: nego harga', 'Pak Hendra · minta harga distributor 200 pcs basreng', $customer('Pak Hendra'), null, false],
            ['2026-09-28 11:52', JenisNotifikasi::NomorPutus, 'Nomor bot terputus, sudah tersambung lagi', 'Nomor Lawang · putus 11:30–11:52 (± 22 menit) · kabar dikirim lewat Nomor Utama', null, null, true],
            ['2026-09-28 10:40', JenisNotifikasi::InvoiceGagal, 'Invoice PDF gagal, dikirim versi teks', 'Laras Ayu · ORD-260927-027 · 2× percobaan gagal', null, null, true],
            ['2026-09-28 10:31', JenisNotifikasi::OrderKaryawan, 'Order karyawan', 'Yoga · ORD-260928-011 · diteruskan ke Bu Sarah', null, $order('ORD-260928-011'), true],
            ['2026-09-28 09:15', JenisNotifikasi::OrderDitutup, 'Order lama ditutup otomatis', '2 order belum di-ACC lewat 24 jam kerja · customer sudah dikabari', null, null, true],
            ['2026-09-28 09:00', JenisNotifikasi::PengingatJeda, 'Pengingat daftar jeda', '3 customer masih dijeda · dikirim ke supervisor lewat SESI_UTAMA', null, null, true],
            ['2026-09-28 08:14', JenisNotifikasi::BuktiMasuk, 'Bukti transfer masuk', 'Dimas Pratama · ORD-260927-031 · Rp486.000', null, null, true],
            ['2026-09-28 07:15', JenisNotifikasi::WorkflowError, 'Chatbot bermasalah', 'Alur Batas Bayar Order · langkah Ambil Config · connection timeout', null, null, true],
            ['2026-09-27 20:00', JenisNotifikasi::LaporanHarian, 'Laporan harian', '7 order masuk · Rp1.972.000 lunas · 4 menunggu konfirmasi · 2 customer dijeda', null, null, true],
            ['2026-09-27 13:40', JenisNotifikasi::JedaKedaluwarsa, 'Jeda kedaluwarsa, bot lanjut', 'Mbak Lia · supervisor dikabari', $customer('Mbak Lia'), null, true],
            ['2026-09-27 10:21', JenisNotifikasi::BroadcastSelesai, 'Broadcast selesai', 'Restock Kripca untuk reseller · 37 terkirim · 11 balas · 5 order', null, null, true],
            ['2026-09-27 01:00', JenisNotifikasi::Arsip, 'Arsip mingguan', '18 order dan 4 customer lama dipindah ke ARSIP_DATA', null, null, true],
            ['2026-09-25 08:15', JenisNotifikasi::OrderDibatalkan, 'Order dibatalkan otomatis', 'Sinta · ORD-260924-002 · belum bayar 5 jam kerja setelah invoice', null, null, true],
        ];

        foreach ($notifikasi as [$waktu, $jenis, $judul, $detail, $customerId, $orderId, $dibaca]) {
            Notifikasi::create([
                'waktu' => $this->jam($waktu),
                'jenis' => $jenis->value,
                'kategori' => $jenis->kategori()->value,
                // Settings.dc.html: order karyawan go to Bu Sarah, everything else to the supervisor
                'penerima' => $jenis === JenisNotifikasi::OrderKaryawan ? 'SARAH_WA' : 'ADMIN_WA',
                'judul' => $judul,
                'detail' => $detail,
                'crm_customer_id' => $customerId,
                'log_order_id' => $orderId,
                'dibaca_at' => $dibaca ? $this->jam($waktu) : null,
            ]);
        }
    }

    /**
     * Otomasi.dc.html: the follow-up templates, each workflow's latest run, the daily
     * report sent yesterday, the supervisor messages and today's automated messages.
     */
    protected function otomasi(): void
    {
        // [kode, judul, tahap, kondisi, acuan, pesan]
        $template = [
            ['FU-01', 'Tanya produk / harga / nego lalu diam', 'TANYA_PRODUK,TANYA_HARGA,NEGO', null, 'CHAT_TERAKHIR', "Halo kak {nama}\nIjin follow up ya kak, sebelumnya sempat tanya soal {minat_produk}\nApakah masih dipertimbangkan kak? Nanti kami bantu carikan yang paling pas"],
            ['FU-02', 'Pesanan disepakati tapi belum lanjut', 'SIAP_ORDER', null, 'CHAT_TERAKHIR', "Kak {nama}, bagaimana kak untuk kelanjutan orderannya?\nKalau sudah pas, tinggal balas di sini ya kak, nanti kami bantu proses"],
            ['FU-03', 'Keranjang ditinggal', 'CART_DITINGGAL', null, 'ORDER_DITUTUP', "Halo kak {nama}\nIjin follow up pesanan {minat_produk} sebelumnya ya kak, apakah masih mau dilanjutkan?\nKalau iya, tinggal balas chat ini, nanti kami bantu buatkan lagi"],
            ['FU-04', 'Tanya kesan pesanan', 'SELESAI', 'bukan order karyawan', 'SELESAI', "Halo kak {nama}\nIjin menyapa kak, bagaimana {pesanan} dari Bang Bana kemarin, cocok di lidah kakak?\nKami senang sekali kalau kakak mau berbagi kesan atau masukan, supaya kami bisa terus lebih baik"],
            ['FU-05', 'Baru menyapa lalu diam', 'BARU', null, 'CHAT_TERAKHIR', "Halo kak {nama}\nIjin menyapa kak, kalau ada yang mau ditanyakan soal produk Bang Bana boleh langsung chat sini ya kak"],
        ];

        foreach ($template as [$kode, $judul, $tahap, $kondisi, $acuan, $pesan]) {
            TemplateFollowup::create([
                'kode' => $kode, 'judul' => $judul, 'tahap' => $tahap, 'kondisi_pemicu' => $kondisi,
                'acuan_waktu' => $acuan, 'pesan' => $pesan, 'status_aktif' => true,
            ]);
        }

        $wf = Workflow::query()->pluck('id', 'kode');

        // Workflow & jadwal, "Terakhir jalan". The supervisor messages carry their text
        // (shown under "Pesan sistem ke supervisor"; the design shows them cut off with "…").
        // [workflow, waktu, jenis, status, judul, detail, data]
        $event = [
            ['chatbot', '2026-09-28 13:41', 'EKSEKUSI', 'BERHASIL', 'Pesan dijawab', '184 chat hari ini', null],
            ['batas_bayar', '2026-09-28 07:15', 'EKSEKUSI', 'GAGAL', 'Ambil Config gagal', 'connection timeout', null],
            ['batas_bayar', '2026-09-28 09:15', 'EKSEKUSI', 'BERHASIL', 'Tutup order belum di-ACC', '2 order ditutup', [
                'ditutup' => 2,
                'jenis_pesan' => JenisNotifikasi::OrderDitutup->value,
                'pesan_supervisor' => 'ORDER LAMA DITUTUP OTOMATIS · 2 order tidak sempat dikonfirmasi dan sudah ditutup: ORD-260926-017, ORD-260926-021…',
            ]],
            ['batas_bayar', '2026-09-28 13:15', 'EKSEKUSI', 'BERHASIL', 'Cek batas bayar', 'tidak ada yang jatuh tempo', null],
            ['prospek_ulang', '2026-09-28 13:30', 'EKSEKUSI', 'BERHASIL', 'Sapaan prospek', '2 terkirim', null],
            ['pantau_koneksi', '2026-09-28 11:30', 'KONEKSI', 'PUTUS', 'Nomor Lawang terputus', null, [
                'sesi' => 'bangbana-2',
                'jenis_pesan' => JenisNotifikasi::NomorPutus->value,
                'pesan_supervisor' => 'NOMOR BOT NOMOR LAWANG TERPUTUS · Status: STOPPED · Sejak: 28/09 11:30 WIB. Chat customer yang masuk ke nomor ini tidak dibalas bot…',
            ]],
            ['pantau_koneksi', '2026-09-28 13:30', 'EKSEKUSI', 'BERHASIL', 'Cek koneksi', '2 dari 2 tersambung', null],
            ['laporan_harian', '2026-09-27 20:00', 'EKSEKUSI', 'BERHASIL', 'Laporan harian', 'terkirim', null],
            ['arsip_data', '2026-09-27 01:00', 'EKSEKUSI', 'BERHASIL', 'Arsip mingguan', '18 order, 4 customer', null],
            ['notifikasi_error', '2026-09-28 07:15', 'EKSEKUSI', 'BERHASIL', 'Error dilaporkan', 'Batas Bayar Order', [
                'jenis_pesan' => JenisNotifikasi::WorkflowError->value,
                'pesan_supervisor' => 'CHATBOT BERMASALAH · Alur: Bang Bana - Batas Bayar Order · Langkah: Ambil Config · Keterangan: connection timeout…',
            ]],
        ];

        foreach ($event as [$kode, $waktu, $jenis, $status, $judul, $detail, $data]) {
            WorkflowEvent::create([
                'workflow_id' => $wf[$kode], 'waktu' => $this->jam($waktu), 'jenis' => $jenis, 'status' => $status,
                'judul' => $judul, 'detail' => $detail, 'data' => $data,
            ]);
        }

        // Laporan harian ke supervisor: the WhatsApp text (*bold*, _italic_) sent yesterday
        WorkflowEvent::create([
            'workflow_id' => $wf['laporan_harian'],
            'waktu' => $this->jam('2026-09-27 20:00'),
            'jenis' => 'LAPORAN',
            'status' => 'TERKIRIM',
            'judul' => 'Laporan harian',
            'data' => ['pesan' => implode("\n", [
                '*LAPORAN HARIAN BANG BANA*',
                'Minggu, 27 September 2026',
                '_Periode: Sabtu 20:00 – Minggu 20:00 WIB (24 jam terakhir)_',
                '',
                '*Order masuk: 7*',
                '• menunggu konfirmasi: 3',
                '• diacc: 2',
                '• lunas: 2',
                '',
                '*Pembayaran masuk: Rp1.972.000*',
                '(5 order di-LUNAS-kan di periode ini · harga produk, tanpa ongkir)',
                '',
                '*Perlu ditindaklanjuti*',
                '• Menunggu konfirmasi: 4',
                '• Sudah di-ACC belum bayar: 3',
                '• Customer sedang dijeda: 2',
                '',
                '*Customer*',
                '• Chat masuk: 96',
                '• Customer baru: 14',
                '• Pesan otomatis terjadwal s.d. besok malam: 15 (12 sapaan prospek, 3 tanya kesan)',
                '',
                '*Per nomor bot*',
                '• Nomor Utama: 71 chat, 5 order',
                '• Nomor Lawang: 25 chat, 2 order',
            ])],
        ]);

        // Pengisi: "Pesan otomatis hari ini 16" = 12 sapaan (2 per run 08:30–13:30),
        // 3 tanya kesan and 1 pengingat bayar, to the demo customers
        $customer = CrmCustomer::query()->where('nama', 'like', 'Customer demo %')->orderBy('id')->get();
        $kirim = [];

        foreach (range(8, 13) as $jam) {
            $kirim[] = [sprintf('2026-09-28 %02d:30', $jam), 'SAPAAN', 'FU-01'];
            $kirim[] = [sprintf('2026-09-28 %02d:30', $jam), 'SAPAAN', 'FU-05'];
        }

        foreach (['08:30', '10:30', '12:30'] as $jam) {
            $kirim[] = ["2026-09-28 {$jam}", 'KESAN', 'FU-04'];
        }

        $kirim[] = ['2026-09-28 10:15', 'PENGINGAT_BAYAR', null];

        foreach ($kirim as $i => [$waktu, $jenis, $kode]) {
            $untuk = $customer[$i % $customer->count()];
            Pesan::create([
                'crm_customer_id' => $untuk->id,
                'chat_id' => $untuk->chat_id,
                'sesi_wa' => $untuk->sesi_wa,
                'waktu' => $this->jam($waktu),
                'pengirim' => PengirimPesan::Adiva,
                'teks' => $kode ? str_replace(['{nama}', '{minat_produk}', '{pesanan}'], [$untuk->nama, 'basreng', 'Kripca'], (string) TemplateFollowup::query()->where('kode', $kode)->value('pesan')) : 'Pengingat: tagihan order kakak jatuh tempo 1 jam kerja lagi ya kak.',
                'status_kirim' => 'TERKIRIM',
                'meta' => array_filter(['otomatis' => $jenis, 'template' => $kode]),
            ]);
        }
    }

    /**
     * A moment in WIB, as written in the design.
     */
    protected function jam(string $waktu): CarbonImmutable
    {
        return CarbonImmutable::parse($waktu, 'Asia/Jakarta');
    }
}
