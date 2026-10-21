<?php

namespace App\Support;

/**
 * How each CONFIG_TOKO key is named and written in the panel (Settings, Log aktivitas).
 */
class KunciPengaturan
{
    /**
     * nama: short name in lists, judul: full name, satuan: unit after the value,
     * pilihan: words for fixed values, bawaan: value while the key has no row,
     * dampak: what happens once it changes. (PHP turns the pilihan keys '1'/'0' into ints.)
     *
     * @var array<string, array{nama: string, judul: string, satuan?: string, pilihan?: array<int|string, string>, bawaan?: string, dampak?: string}>
     */
    public const DAFTAR = [
        // Settings.dc.html: Kontak & tim
        'ADMIN_WA' => ['nama' => 'Nomor supervisor', 'judul' => 'Nomor supervisor'],
        'SARAH_WA' => ['nama' => 'PJ program karyawan', 'judul' => 'Nomor PJ program karyawan'],
        // Pembayaran & invoice
        'NAMA_TOKO' => ['nama' => 'Nama di invoice', 'judul' => 'Nama di invoice'],
        'REKENING' => ['nama' => 'Rekening tujuan', 'judul' => 'Rekening tujuan'],
        'BATAS_BAYAR_JAM' => [
            'nama' => 'Batas bayar (kirim)',
            'judul' => 'Batas bayar untuk order kirim',
            'satuan' => 'jam kerja',
            'dampak' => 'Langsung dipakai alur Batas Bayar di pengecekan berikutnya (tiap jam, menit ke-15), termasuk untuk order yang sedang menunggu bayar.',
        ],
        'BATAS_BAYAR_PICKUP_JAM' => ['nama' => 'Batas bayar (ambil)', 'judul' => 'Batas bayar untuk order ambil sendiri', 'satuan' => 'jam kerja'],
        'BATAS_KONFIRMASI_JAM' => ['nama' => 'Tutup bila belum ACC', 'judul' => 'Tutup order yang belum di-ACC', 'satuan' => 'jam kerja'],
        'CATATAN_INVOICE' => ['nama' => 'Catatan di invoice', 'judul' => 'Catatan di invoice'],
        'LINK_ULASAN' => ['nama' => 'Link ulasan', 'judul' => 'Link ulasan'],
        // Pengambilan sendiri
        'AMBIL_TOKO_SAWOJAJAR' => ['nama' => 'Toko Sawojajar — alamat & jam ambil', 'judul' => 'Toko Sawojajar — alamat & jam ambil'],
        'AMBIL_PABRIK_LAWANG' => ['nama' => 'Pabrik Lawang — alamat & jam ambil', 'judul' => 'Pabrik Lawang — alamat & jam ambil'],
        // Jam kerja & pesan otomatis
        'JAM_PESAN_OTOMATIS' => ['nama' => 'Jam kerja otomatis', 'judul' => 'Jam kerja otomatis'],
        'HARI_LIBUR_OTOMATIS' => ['nama' => 'Hari libur', 'judul' => 'Hari libur'],
        'JEDA_FOLLOWUP_JAM' => ['nama' => 'Sapa ulang prospek setelah', 'judul' => 'Sapa ulang prospek setelah', 'satuan' => 'jam diam'],
        'JEDA_KESAN_HARI' => ['nama' => 'Tanya kesan setelah SELESAI', 'judul' => 'Tanya kesan setelah SELESAI', 'satuan' => 'hari'],
        // Ongkir (Mengantar)
        'MENGANTAR_KOTA_ASAL' => ['nama' => 'Kota asal', 'judul' => 'Kota asal pengiriman'],
        'MENGANTAR_PICKUP_ADDRESS_ID' => ['nama' => 'ID alamat pickup', 'judul' => 'ID alamat pickup'],
        'MENGANTAR_JAM_PICKUP' => ['nama' => 'Jam pickup kurir', 'judul' => 'Jam pickup kurir'],
        'MENGANTAR_VOLUME_PICKUP' => ['nama' => 'Kendaraan pickup', 'judul' => 'Kendaraan pickup'],
        'KURIR_DIKECUALIKAN' => ['nama' => 'Kurir disembunyikan', 'judul' => 'Kurir disembunyikan'],
        // Nomor WhatsApp bot
        'SESI_UTAMA' => ['nama' => 'Nomor untuk pesan terjadwal', 'judul' => 'Nomor untuk pesan terjadwal'],
        // Arsip data
        'ARSIP_ORDER_HARI' => ['nama' => 'Arsipkan order setelah', 'judul' => 'Arsipkan order setelah', 'satuan' => 'hari'],
        'ARSIP_CUSTOMER_HARI' => ['nama' => 'Arsipkan customer diam', 'judul' => 'Arsipkan customer diam', 'satuan' => 'hari'],
        // AI & perilaku bot: kept on the chatbot workflow (PengaturanAi), not in CONFIG_TOKO
        'AI_MODEL_UTAMA' => ['nama' => 'Model utama', 'judul' => 'Model utama'],
        'AI_MODEL_CADANGAN' => ['nama' => 'Model cadangan', 'judul' => 'Model cadangan'],
        'AI_TEMPERATURE' => ['nama' => 'Temperature', 'judul' => 'Temperature'],
        'AI_INGATAN_CHAT' => ['nama' => 'Ingatan chat', 'judul' => 'Ingatan chat', 'satuan' => 'pesan'],
        'AI_TUNGGU_GABUNG' => ['nama' => 'Tunggu gabung', 'judul' => 'Tunggu gabung', 'satuan' => 'detik'],
        // Notifikasi WhatsApp: the kinds switched off (App\Support\KabarWa)
        'NOTIF_WA_MATI' => ['nama' => 'Kabar WhatsApp yang dimatikan', 'judul' => 'Kabar WhatsApp yang dimatikan', 'bawaan' => ''],
        // Instagram & Facebook (Chatbot Sosmed); the token and the app secret are never logged
        'META_PAGE_ID' => ['nama' => 'Page ID Facebook', 'judul' => 'Page ID Facebook'],
        'META_IG_ID' => ['nama' => 'ID akun Instagram', 'judul' => 'ID akun Instagram'],
        'META_APP_ID' => ['nama' => 'App ID Meta', 'judul' => 'App ID Meta'],
        'META_VERIFY_TOKEN' => ['nama' => 'Verify token webhook', 'judul' => 'Verify token webhook Meta'],
        'SOSMED_BOT_IG' => ['nama' => 'Bot Instagram', 'judul' => 'Adiva membalas DM Instagram', 'pilihan' => ['AKTIF' => 'Aktif', 'MATI' => 'Mati'], 'bawaan' => 'AKTIF'],
        'SOSMED_BOT_FB' => ['nama' => 'Bot Facebook', 'judul' => 'Adiva membalas pesan Facebook', 'pilihan' => ['AKTIF' => 'Aktif', 'MATI' => 'Mati'], 'bawaan' => 'AKTIF'],
        'SOSMED_JEDA_JAM' => ['nama' => 'Bot diam setelah admin membalas', 'judul' => 'Bot sosmed diam setelah admin membalas', 'satuan' => 'jam', 'bawaan' => '12'],
        // Tim & akses
        PengaturanKeamanan::KODE_PERANGKAT_BARU => [
            'nama' => 'Kode WhatsApp di perangkat baru',
            'judul' => 'Kode WhatsApp di perangkat baru',
            'pilihan' => ['1' => 'aktif', '0' => 'nonaktif'],
            'bawaan' => '1',
        ],
        PengaturanKeamanan::KELUAR_OTOMATIS => [
            'nama' => 'Keluar otomatis',
            'judul' => 'Keluar otomatis setelah tidak aktif',
            'satuan' => 'jam',
            'bawaan' => '12',
        ],
    ];

    public static function nama(string $kunci): string
    {
        return self::DAFTAR[$kunci]['nama'] ?? $kunci;
    }

    public static function judul(string $kunci): string
    {
        return self::DAFTAR[$kunci]['judul'] ?? $kunci;
    }

    public static function bawaan(string $kunci): ?string
    {
        return self::DAFTAR[$kunci]['bawaan'] ?? null;
    }

    public static function dampak(string $kunci): ?string
    {
        return self::DAFTAR[$kunci]['dampak'] ?? null;
    }

    /**
     * "4 jam kerja", "aktif"; "—" for an empty value.
     */
    public static function teks(string $kunci, ?string $nilai): string
    {
        $pilihan = self::DAFTAR[$kunci]['pilihan'] ?? [];

        if (isset($pilihan[(string) $nilai])) {
            return $pilihan[(string) $nilai];
        }

        if ($nilai === null || $nilai === '') {
            return '—';
        }

        return trim($nilai.' '.(self::DAFTAR[$kunci]['satuan'] ?? ''));
    }

    /**
     * "Batas bayar (kirim): 4 → 5 jam kerja" (Log aktivitas, Detail).
     */
    public static function perubahan(string $kunci, ?string $sebelum, string $sesudah): string
    {
        $pilihan = self::DAFTAR[$kunci]['pilihan'] ?? [];
        $dari = $pilihan[(string) $sebelum] ?? (($sebelum ?? '') === '' ? '—' : $sebelum);

        return self::nama($kunci).': '.$dari.' → '.self::teks($kunci, $sesudah);
    }
}
