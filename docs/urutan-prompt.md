# Urutan prompt untuk Claude Code

Salin satu per satu. Tiap tahap diakhiri commit, lalu mulai sesi baru (`/clear`) supaya konteks Claude tetap bersih.

## Tahap 0 — Siapkan proyek

> Baca `CLAUDE.md` dan isi folder `design/`. Buat proyek Laravel 13 baru dengan starter kit React resmi (Inertia, TypeScript, Tailwind 4) di folder ini. Setelah itu:
> 1. Hapus semua tampilan bawaan starter kit (halaman dashboard, sidebar, komponen shadcn yang tidak dipakai), sisakan autentikasi di backend.
> 2. Tempel `design/tokens.css` ke `resources/css/app.css` dan pasang font Geist sesuai `design/komponen.md`.
> 3. Pasang `playwright`, `pixelmatch`, `pngjs` sebagai devDependency, tambahkan script `"visual": "node scripts/visual-diff.mjs"` dan `"foto-desain": "node scripts/foto-desain.mjs"` di `package.json`, jalankan `npx playwright install chromium`, lalu `npm run foto-desain` dan pastikan ada 30 gambar di `design/screens/`.
> 4. Buat mode demo: `APP_DEMO=true`, jam dibekukan ke Senin 28 Sep 2026 13:42 WIB, `DemoSeeder` kosong, rute `/_demo/login` (masuk sebagai Falah, Supervisor) dan `/_demo/komponen/{sidebar|topbar}`.
>
> Buat rencana dulu, tunggu persetujuan saya.

## Tahap 1 — Database

> Buat migration PostgreSQL untuk tabel yang dibutuhkan panel (lihat data contoh di `design/source/*.dc.html` untuk kolom yang tampil). Nama tabel dan kolom mengikuti Data tables n8n yang sudah ada: `config_toko`, `produk`, `paket_reseller`, `gambar_produk`, `kebijakan`, `harga_karyawan`, `karyawan`, `crm_customer`, `log_order` + `order_items`, `jeda_bot`, `template_followup`, `arsip_data`; ditambah `users`, `pesan`, `notifikasi`, `activity_log`, `broadcast`, `broadcast_penerima`, `prompt_versi`, `workflow_event`. Waktu memakai `timestamptz`. Buat rencana dulu.

## Tahap 2 — Komponen bersama

> Bangun komponen di `resources/js/components/ui/` persis sesuai `design/komponen.md`: AppLayout, Sidebar, Topbar, PageHeader (breadcrumb + tab), StatStrip, Panel, DataTable, Status, Tag, Count, Avatar, KeyValue, Button, IconButton, Field, Input, Select, Textarea, Checkbox, Toggle, Segmented, Note. Buat halaman pratinjau `/_demo/komponen/sidebar` dan `/_demo/komponen/topbar`, lalu jalankan `npm run visual -- Sidebar Topbar` sampai lolos.

Tahap ini paling penting: kalau Sidebar, Topbar, dan Panel sudah persis, semua halaman ikut persis.

## Tahap 3 — Halaman satu per satu

Pakai perintah `/halaman <Kode>`. Urutan yang disarankan (dari yang paling sederhana):

1. `Login`, `Daftar`, `LupaSandi`
2. `Katalog`, `Paket`, `Gambar`, `Kebijakan`, `Karyawan`
3. `Tim`, `Aktivitas`, `Notifikasi`
4. `Settings`, `Otomasi`, `Prompt`
5. `Orders`, `OrderDetail`, `OrderNew`, `Jeda`
6. `Customer`, `Pipeline`, `Broadcast`, `BroadcastNew`
7. `Monitoring`, `Main`, `Inbox`

Kalau hasilnya belum sama: `/cocokkan <Kode>`.

## Tahap 4 — Sambungkan ke bot

Setelah semua halaman lolos uji visual:

> Sambungkan aksi panel ke n8n dan WAHA sesuai rencana: perintah order (ACC, TOLAK, LUNAS, SIAP, KIRIM, SELESAI) lewat webhook n8n bertanda tangan, JEDA/LANJUT menulis `jeda_bot`, balasan admin di Percakapan dikirim lewat WAHA memakai `sesi_wa` customer dan disimpan ke `pesan`. Buat rencana dulu.

## Aturan saat meminta perubahan

- Sebut kode halaman dan tunjuk bagian di gambar desain ("panel Jadwal otomatis di `Monitoring.png`"), jangan menjelaskan dengan kata-kata saja.
- Minta Claude **mengutip nilai dari source** sebelum mengubah ukuran atau warna.
- Jangan pernah meminta "buat lebih bagus" — targetnya sama persis, bukan lebih bagus.
- Kalau desainnya memang mau diubah, ubah dulu di canvas desain, ekspor ulang gambar dan source-nya ke `design/`, baru minta Claude menyesuaikan kode.
