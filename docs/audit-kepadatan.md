# Audit kepadatan & hierarki — 27 halaman panel

Bahan untuk menyiapkan desain baru di `design/source`. Isinya **temuan**, bukan desain: tiap butir menyebut angka yang diukur, lalu usulan yang bisa dikerjakan di tingkat tata letak. Tidak ada satu pun usulan yang menghapus informasi, memindahkan primary action, atau mengubah alur kerja.

Tanggal ukur: 17 Okt 2026. Diukur dari `design/source/*.dc.html` apa adanya (bukan dari halaman React), ditambah pemeriksaan lebar 1280/1024/768/390 pada aplikasi.

## Cara mengukur

Tiga skrip Playwright dijalankan pada tiap halaman desain:

1. **Kepadatan** — jumlah karakter teks yang terlihat dibagi luas `main` (karakter per 100.000 px²), jumlah kotak berbingkai, jumlah kendali (`button`/`a`/`input`/`select`/`textarea`), jumlah ukuran font berbeda, jumlah warna teks berbeda.
2. **Kerangka** — H1, sub-judul, urutan panel (`h2`/`h3`), kolom tabel, dan `grid-template-columns` tiap grid ≥ 4 kolom.
3. **Lebar kecil** — `npm run responsif` pada 1280/1024/768/390, lalu tinggi halaman penuh di tiap lebar.

Angka-angkanya ada di tabel bagian 3.

---

## 1. Temuan yang berlaku di banyak halaman

### 1.1 Skala teks punya 20 ukuran; token hanya menamai 10

Di seluruh desain dipakai: 9 · 9,5 · 10 · 10,5 · 11 · 11,5 · 12 · 12,5 · 13 · 13,5 · 14 · 14,5 · 15 · 16 · 17 · 18 · 19 · 22 · 24 · 26 px.

`design/tokens.css` menamai 10 di antaranya (`--text-key` 10,5 sampai `--text-stat` 24). Sepuluh sisanya tidak punya nama dan muncul tersebar:

| Ukuran | Dipakai di | Keterangan |
|---|---|---|
| 9 · 9,5 · 10 | OrderDetail | pratinjau invoice (halaman A4 diperkecil) — wajar, tapi perlu token sendiri |
| 14 | Login, Daftar, Main, Inbox, OrderNew, Settings | bersaing dengan 13,5 (`--text-base`) dan 14,5 (`--text-panel`) |
| 15 | Login, Daftar, LupaSandi, Inbox, Orders, OrderDetail, Aktivitas | — |
| 16 · 18 | OrderNew, BroadcastNew | judul langkah di formulir |
| 17 · 19 | Customer, Broadcast | nama customer / judul kampanye |
| 26 | 6 halaman auth | H1 halaman auth, sedangkan H1 halaman dalam 22 |

Jarak 0,5 px antara 13,5 / 14 / 14,5 tidak terbaca sebagai beda tingkat oleh mata; yang dihasilkan bukan hierarki melainkan ketidakrataan. **Usul:** tetapkan satu skala berisi 7–8 langkah (mis. 11 · 12 · 13 · 14,5 · 17 · 22 · 24 + satu skala invoice tersendiri), lalu petakan tiap pemakaian ke langkah terdekat. Ini pekerjaan di `tokens.css` + `design/source`, dan efeknya terasa di 27 halaman sekaligus.

### 1.2 Kolom kiri dan kanan berakhir pada tinggi yang jauh berbeda

Pada tata letak dua kolom, salah satu kolom habis jauh lebih dulu, dan sisanya putih:

| Halaman | Kolom kiri berakhir | Kolom kanan berakhir | Putih tersisa |
|---|---|---|---|
| Settings (2112 px) | ±1790 | ±2080 | ±320 px di kiri |
| Otomasi (2576 px) | ±1390 lalu kosong sampai 1500 | ±1470 | dua lubang di kiri |
| Customer (1184 px) | daftar berakhir ±1010 | aktivitas berakhir ±995 | ±170 px di ketiga kolom |
| OrderDetail (1250 px) | riwayat masih berjalan | pratinjau berakhir ±1050 | ±200 px di kanan |

**Usul:** untuk halaman panjang, panel diatur dalam satu aliran masonry/ berurutan, bukan dua kolom tetap; atau urutan panel ditukar supaya tinggi kedua kolom imbang.

### 1.3 Progressive disclosure sudah ada, tapi tidak konsisten

Yang sudah memakainya: `nav[Bagian]` di Katalog/Paket/Gambar/Kebijakan/Karyawan dan Notifikasi, `div[Filter status]` 9 tab di Orders, `div[Tampilan] Kanban/Tabel` di Pipeline, master–detail di Inbox/Orders/Customer, tab status di panel detail Orders.

Yang belum, padahal paling membutuhkan: **Settings** (9 panel, 2112 px, tanpa navigasi apa pun) dan **Otomasi** (6 panel, 2576 px, tanpa navigasi apa pun). Keduanya halaman terpanjang di panel. Pola `nav[Bagian]` sudah terbukti dipakai di Katalog — tinggal diterapkan, bukan diciptakan.

### 1.4 Primary action menggulung keluar layar di halaman formulir panjang

Di Settings tombol **Simpan perubahan** berada di baris judul (y ≈ 113). Isian terakhir (`ARSIP_CUSTOMER_HARI`) ada di y ≈ 1900. Pengguna yang mengubah isian terbawah harus menggulir ±1800 px ke atas untuk menyimpan. Hal yang sama berlaku ringan di BroadcastNew (1500 px) dan OrderNew (1120 px).

**Usul:** baris judul yang menempel (sticky) di halaman formulir, atau baris aksi tetap di bawah. Tombolnya tidak dipindahkan atau disembunyikan — hanya dibuat selalu terjangkau.

### 1.5 Lebar kecil lolos tanpa overflow, tapi belum dirancang

`npm run responsif` hijau di 1280/1024/768/390 untuk semua halaman — tidak ada yang melebar ke samping. Tapi dua hal belum dijawab oleh desain:

- **Halaman jadi sangat panjang di 390 px**: Otomasi 5332 px (6,3 layar ponsel), Settings 5271 px (6,2), Main 3411 px (4,0), BroadcastNew 2980 px (3,5).
- **Halaman master–detail tetap 900 px tinggi tetap di semua lebar** (Inbox, Orders, Customer, Pipeline). Di 390 px itu berarti dua-tiga panel bergulir sendiri-sendiri di dalam layar setinggi 844 px. Belum ada desain yang menyatakan apa yang seharusnya terjadi.

Ini satu-satunya bagian brief yang **belum punya sumber desain sama sekali** (`design/source` hanya 1440 px, Pipeline 2112 px). Kalau responsif mau dikerjakan sungguhan, perlu sumber desain untuk lebar kecil — kalau tidak, aturan "jangan menebak" membuatnya tidak bisa dikerjakan.

---

## 2. Prioritas

Urutan ini dari hasil ukur: kepadatan, panjang halaman, dan berapa sering halaman dipakai per hari.

### P1 — paling berdampak (5 halaman)

| Halaman | Alasan dari angka |
|---|---|
| **Otomasi** | Terpadat (218 kar./100k px²) **dan** tertinggi (2576 px, 2,9 layar). 17 kotak, 6 panel, tanpa navigasi bagian. |
| **Settings** | 2112 px, 32 kotak, 42 kendali, 9 panel tanpa navigasi; primary action di luar jangkauan. |
| **Main** | Halaman pertama yang dibuka tiap hari. 1648 px, 7 panel + strip 4 angka; 14 warna latar berbeda — terbanyak di panel. |
| **Customer** | Blok "Penilaian Adiva" berisi 16 pasang label–nilai dalam satu grid rata tanpa pengelompokan. |
| **OrderDetail** | Pratinjau invoice mengulang seluruh isi panel Item (produk, qty, harga, subtotal, total) kata per kata, memakai separuh lebar halaman. |

### P2 — perlu dirapikan (7 halaman)

| Halaman | Alasan dari angka |
|---|---|
| **Pipeline** | 9 kolom pada lebar 2112 px; di 1440 px hanya ±5,5 kolom terlihat. Tiap kolom hanya memuat 2–4 kartu lalu "Lihat N lainnya" — ±65% tinggi kolom kosong. |
| **Orders** | 9 tab + hitungan pada satu baris selebar ±1130 px; 35 kendali; 7 tombol aksi dalam kisi 4+3 yang sebagian mati. |
| **Inbox** | 3 kolom sekaligus (daftar · percakapan · penilaian), 12 kotak, kepadatan 176. |
| **Prompt** | 1060 px dengan 4 panel dan 28 kendali pada halaman yang isinya satu editor teks. |
| **Monitoring** | 5 panel + 2 grafik + strip angka dalam 1164 px. |
| **BroadcastNew** | 1500 px, 47 kendali — terbanyak kedua setelah Pipeline; semua terbuka sekaligus tanpa langkah. |
| **Notifikasi** | 1424 px untuk daftar yang isinya satu jenis baris berulang. |

### P3 — cukup penyesuaian kecil (9 halaman)

Jeda, Katalog, Paket, Gambar, Kebijakan, Karyawan, Broadcast, OrderNew, Tim, Aktivitas — kepadatan 81–136, satu-dua panel di bawah batas layar, tidak ada temuan struktural.

### P4 — tidak perlu diapa-apakan (6 langkah auth)

Login, Daftar, Daftar_2, LupaSandi, LupaSandi_2, LupaSandi_3. Semua tepat 900 px, kepadatan 45–75, 3–10 kendali. Satu-satunya catatan: H1 di sini 26 px sedangkan H1 di halaman dalam 22 px (lihat 1.1).

---

## 3. Angka tiap halaman

Diurutkan dari terpadat. "Layar" = tinggi halaman ÷ 900. "Padat" = karakter teks terlihat per 100.000 px² area `main`.

| Kode | Ukuran | Layar | Teks | Padat | Kotak | Panel | Kendali | Kolom maks | Ukuran font | Warna teks | Tinggi @390 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Otomasi | 1440×2576 | 2,86 | 6709 | **218** | 17 | 6 | 15 | 5 | 10 | 9 | 5332 |
| Orders | 1440×1124 | 1,25 | 2393 | 179 | 4 | — | 35 | 4 | 8 | 12 | 900 |
| Inbox | 1440×1080 | 1,20 | 2267 | 176 | 12 | — | 21 | 2 | 9 | 10 | 900 |
| Settings | 1440×2112 | 2,35 | 4164 | 165 | **32** | 9 | 42 | 4 | 10 | 6 | 5271 |
| Prompt | 1440×1060 | 1,18 | 2064 | 163 | 9 | 4 | 28 | 3 | 8 | 11 | 2585 |
| Monitoring | 1440×1164 | 1,29 | 2260 | 163 | 7 | 5 | 9 | 4 | 8 | 9 | 2654 |
| Customer | 1440×1184 | 1,32 | 2116 | 150 | 11 | 6 | 13 | 7 | 10 | 8 | 900 |
| OrderDetail | 1440×1250 | 1,39 | 2122 | 142 | 7 | 4 | 10 | 5 | **13** | 9 | 2501 |
| Broadcast | 1440×1080 | 1,20 | 1754 | 136 | 5 | 3 | 17 | 5 | 9 | 9 | 1928 |
| Jeda | 1440×1210 | 1,34 | 1937 | 134 | 12 | 4 | 19 | 5 | 8 | 6 | 2100 |
| Katalog | 1440×1080 | 1,20 | 1712 | 133 | 8 | 3 | 12 | 6 | 8 | 7 | 1667 |
| Notifikasi | 1440×1424 | 1,58 | 2188 | 129 | 3 | 1 | 22 | 4 | 8 | 6 | 2681 |
| Kebijakan | 1440×1080 | 1,20 | 1586 | 123 | 10 | 3 | 24 | 3 | 8 | 7 | 2064 |
| Main | 1440×1648 | 1,83 | 2404 | 122 | 9 | 7 | 27 | 4 | 10 | 10 | 3411 |
| Pipeline | 2112×1080 | 1,20 | 2456 | 122 | **34** | — | **48** | **9** | 7 | 8 | 900 |
| Aktivitas | 1440×1152 | 1,28 | 1673 | 122 | 7 | 2 | 13 | 5 | 9 | 9 | 1868 |
| OrderNew | 1440×1120 | 1,24 | 1574 | 118 | 15 | 5 | 24 | 5 | 10 | 9 | 1815 |
| Karyawan | 1440×1290 | 1,43 | 1716 | 112 | 7 | 4 | 11 | 6 | 9 | 8 | 1967 |
| Tim | 1440×1120 | 1,24 | 1457 | 109 | 5 | 3 | 16 | 6 | 8 | 10 | 1733 |
| BroadcastNew | 1440×1500 | 1,67 | 1920 | 107 | 10 | 3 | 47 | 2 | 10 | 9 | 2980 |
| Gambar | 1440×1080 | 1,20 | 1090 | 85 | 13 | 1 | 14 | 4 | 8 | 8 | 2091 |
| Paket | 1440×1080 | 1,20 | 1049 | 81 | 5 | 2 | 22 | 6 | 8 | 7 | 1307 |
| Daftar | 1440×900 | 1,00 | 377 | 75 | 5 | — | 10 | 2 | 8 | 6 | 900 |
| Daftar_2 | 1440×900 | 1,00 | 371 | 74 | 1 | — | 9 | 6 | 6 | 6 | — |
| Login | 1440×900 | 1,00 | 368 | 73 | 4 | — | 10 | 2 | 7 | 6 | 900 |
| LupaSandi | 1440×900 | 1,00 | 356 | 71 | 2 | — | 3 | 3 | 6 | 5 | 900 |
| LupaSandi_3 | 1440×900 | 1,00 | 242 | 48 | 3 | — | 5 | 3 | 6 | 4 | — |
| LupaSandi_2 | 1440×900 | 1,00 | 229 | 45 | 1 | — | 8 | 6 | 6 | 4 | — |

---

## 4. Temuan per halaman

Tiap butir: **apa yang diukur/terlihat** → **usul tingkat tata letak**. Usulan yang menyentuh isi informasi selalu memindahkan, tidak membuang.

### Otomasi (P1)

- 2576 px, 6 panel, tanpa navigasi bagian. Panel "Batas bayar & tutup order" sendiri berisi 4 langkah bernomor + 5 baris aturan, semuanya prosa penjelas.
- Kolom kiri berhenti di ±1390 sedangkan kanan berlanjut ke ±1470, lalu kiri mulai lagi di 1500 — ada dua lubang putih.
- Panel "Template sapaan otomatis" menampilkan 5 template dengan **seluruh isi pesan terbuka** (FU-01…FU-05), ±1000 px.
- **Usul:** (a) `nav[Bagian]` seperti Katalog: Workflow · Batas bayar · Arsip · Template · Laporan · Pesan sistem; (b) template jadi daftar ringkas yang isi pesannya terbuka saat dipilih — toggle aktif/nonaktif tetap terlihat di baris ringkas; (c) prosa penjelas panjang jadi satu baris + "Lihat aturannya".

### Settings (P1)

- 2112 px, 9 panel, 32 kotak, 42 kendali, tanpa navigasi.
- Panel "Notifikasi WhatsApp" berisi 16 baris jenis notifikasi dalam dua kolom — seluruhnya informasi "selalu aktif", tidak bisa diubah, tapi memakan ±200 px.
- Tombol Simpan perubahan di y ≈ 113; isian terakhir di y ≈ 1900 (lihat 1.4).
- Kolom kiri berakhir ±320 px lebih awal dari kanan.
- **Usul:** (a) `nav[Bagian]`: Kontak · Pembayaran · Jam kerja · Ongkir · Nomor bot · AI · Notifikasi · Arsip; (b) baris judul sticky supaya Simpan selalu terjangkau; (c) daftar 16 jenis notifikasi tetap ada, tapi sebagai satu blok yang bisa dibuka.

### Main (P1)

- 1648 px. Strip 4 angka + "Perlu tindakan" (6 item) + "Status Adiva" (6 baris) + "Pesan otomatis" (6 baris) + grafik 30 hari + 3 panel bawah.
- 14 warna latar berbeda dan 10 warna teks berbeda — terbanyak di seluruh panel. Titik status memakai 6 nada (biru, kuning, hijau, merah, abu, ungu) di satu layar.
- Yang betul-betul butuh tindakan hari ini hanya 6 baris, tapi mendapat porsi vertikal yang sama dengan 3 panel statistik di bawah layar pertama.
- **Usul:** (a) kurangi nada warna di satu layar — status pesanan dan tahap customer tidak perlu palet berbeda; (b) 3 panel bawah (Customer per tahap · Tipe customer · Status pesanan) bisa jadi satu panel bertab, karena ketiganya potongan dari angka yang sama; (c) grafik 30 hari turun ke bawah ketiga panel itu.

### Customer (P1)

- Panel "Penilaian Adiva": 16 pasang label–nilai dalam grid 2 kolom rata, tanpa pengelompokan — Tipe, Tahap, Skor, Minat produk, Lokasi jualan, Estimasi modal, Kota, Butuh admin, Interaksi terakhir, Pesan terakhir, ID chat, Sesi ingatan, Nomor bot, Jumlah sapaan ulang, + ringkasan.
- Grid 7 kolom (104 px masing-masing) untuk jejak tahap di kepala profil.
- ±170 px putih di dasar ketiga kolom.
- **Usul:** pisahkan 16 pasang itu jadi dua kelompok dengan jarak — "Penilaian" (tipe, tahap, skor, minat, lokasi, modal, kota, butuh admin) dan "Teknis" (ID chat, sesi ingatan, nomor bot, jumlah sapaan). Tidak ada yang dibuang; hanya diberi batas antara yang dipakai admin dan yang dipakai saat menelusuri masalah.

### OrderDetail (P1)

- Pratinjau invoice memakai ±48% lebar halaman dan ±780 px tinggi, dan mengulang persis isi panel Item: produk, qty, harga, subtotal, total produk, ongkir, total.
- Pratinjau itu sendiri punya ±170 px kosong di tengah (antara tabel dan blok instruksi transfer).
- 13 ukuran font di satu halaman — terbanyak di panel (9 sampai 22 px), karena invoice A4 diperkecil.
- **Usul:** pratinjau invoice jadi tab di sebelah Item, atau panel yang dibuka lewat tombol (tombol Cetak/Unduh PDF tetap terlihat). Ruang yang bebas dipakai Riwayat, yang sekarang terpotong di dasar halaman.

### Pipeline (P2)

- 9 kolom × 191 px pada kanvas 2112 px — satu-satunya halaman yang desainnya lebih lebar dari 1440. Di 1440 px hanya ±5,5 kolom terlihat.
- Tiap kolom memuat 2–4 kartu lalu "Lihat N lainnya"; sisanya kosong. Kolom berakhir ±800 px pada kanvas 1080 px.
- 48 kendali, 34 kotak — terbanyak di panel.
- **Usul:** tentukan berapa kolom yang harus terlihat pada 1440 px dan bagaimana sisanya dicapai (gulir samping yang jelas, atau pengelompokan tahap). Sekarang tidak ada desain untuk 1440 px, sehingga halaman ini satu-satunya yang tidak bisa dicocokkan pada lebar standar panel.

### Orders (P2)

- 9 tab + angka pada satu baris ±1130 px (Semua · Menunggu ACC · Menunggu bayar · Bukti masuk · Lunas · Dikirim · Karyawan · Selesai · Batal/tolak).
- Panel detail punya **tab kedua** (Menunggu ACC · Invoice · Lunas · Dikirim · Selesai) tepat di bawah tab pertama — dua baris tab bertumpuk dengan arti berbeda.
- 7 tombol aksi (ACC · LUNAS · SIAP · KIRIM · SELESAI · BATAL · TOLAK) dalam kisi 4+3; pada contoh ini 4 di antaranya mati.
- **Usul:** tab atas dan jejak status di panel detail dibedakan bentuknya supaya tidak terbaca sebagai dua navigasi sejenis; pertimbangkan tab status jadi pengelompokan (Perlu tindakan · Berjalan · Selesai) dengan rincian di dalamnya. Ketujuh tombol tetap ada dan tetap terlihat.

### Inbox (P2)

- 3 kolom (daftar 300 px · percakapan 535 px · penilaian 300 px) + 1 baris tulis balasan. 12 kotak, 21 kendali.
- Kolom kanan "Penilaian Adiva terakhir" berisi 8 pasang label–nilai + ringkasan + catatan + pesanan — informasi yang sama persis dengan panel di halaman Customer.
- **Usul:** kolom kanan bisa dijadikan panel yang bisa diciutkan, supaya percakapan mendapat lebar penuh saat admin sedang membalas. Isinya tetap, tombolnya tetap.

### Prompt (P2)

- 1060 px dengan 4 panel dan 28 kendali untuk halaman yang inti pekerjaannya satu editor teks ("18 bagian" + "12 · Eskalasi admin" + "Uji draf" + "Riwayat versi").
- **Usul:** Uji draf dan Riwayat versi tidak perlu berdampingan dengan editor sepanjang waktu.

### Monitoring (P2)

- Strip 4 angka + 2 grafik + 3 panel dalam 1164 px. Grid 4 kolom 281,5 px untuk strip.
- Sudah punya `select[Rentang waktu]` sebagai satu-satunya kendali — bersih.
- **Usul:** paling ringan dari P2; cukup pengaturan ulang tinggi panel supaya tidak ada yang terpotong di 900 px.

### BroadcastNew (P2)

- 1500 px, 47 kendali, 4 panel (Penerima · Pesan · Jadwal & kecepatan · Pratinjau) semuanya terbuka.
- **Usul:** halaman ini urutannya sudah berupa langkah (pilih penerima → tulis → jadwalkan). Bentuk langkah/stepper akan mengurangi yang terlihat sekaligus tanpa menghapus apa pun — dan Pratinjau tetap di samping.

### Notifikasi (P2)

- 1424 px untuk satu daftar; grid baris 4 kolom (8 px titik · 524 px isi · 60 px · 96 px) diulang. Sudah punya `nav[Bagian]`.
- **Usul:** paling ringan; panjangnya wajar untuk daftar, tapi 1424 px berarti bagian bawah tidak pernah terlihat tanpa gulir — pertimbangkan jumlah baris awal.

### P3 — catatan singkat

- **Jeda** (1210 px) — 12 kotak untuk 4 panel; strip angka + Sedang dijeda + Riwayat jeda + 2 panel aksi. Dua panel aksi di kanan bisa diringkas.
- **Katalog / Paket / Gambar / Kebijakan / Karyawan** — sudah memakai `nav[Bagian]`, pola paling rapi di panel. Katalog punya tabel 6 kolom, Paket 6 kolom, Karyawan 5 kolom; semuanya muat. Kebijakan 24 kendali untuk 9 kebijakan — tiap kebijakan punya 2–3 tombol yang selalu terlihat.
- **Broadcast** (1080 px) — 3 panel + strip; "Aturan kirim aman" adalah prosa penjelas yang bisa diciutkan.
- **OrderNew** (1120 px) — 15 kotak, 5 panel untuk satu formulir; tiap baris item adalah kotak tersendiri dalam grid 5 kolom. Panel "Setelah dicatat" menjelaskan akibat — bagus, pertahankan.
- **Tim** (1120 px) — tabel 6 kolom + 2 panel. "Hak akses per peran" adalah matriks 4 kolom yang jarang berubah; kandidat untuk diciutkan.
- **Aktivitas** (1152 px) — tabel 5 kolom (56 · 70 · 180 · 260 · 100 px), satu jenis baris. Rapi; hanya panjang.

### P4 — 6 langkah auth

Tidak ada temuan selain H1 26 px (bagian 1.1). Jangan diubah kecuali demi keseragaman skala teks.

---

## 5. Yang tidak boleh berubah

Diambil dari batasan yang Anda tetapkan, diterjemahkan ke hal konkret di panel ini:

1. **Tujuh tombol aksi di Orders / OrderDetail** (ACC · LUNAS · SIAP · KIRIM · SELESAI · BATAL · TOLAK) urutannya sama dengan urutan workflow bot. Boleh ditata ulang, tidak boleh disembunyikan di balik menu, tidak boleh diubah urutannya.
2. **Kunci CONFIG_TOKO** yang tertulis di bawah tiap label Settings/Otomasi (`BATAS_BAYAR_JAM`, `MENGANTAR_API_KEY`, …) adalah satu-satunya jembatan ke database dan ke dokumen kontrak. Tetap terlihat.
3. **Prosa penjelas di Otomasi dan Settings** menerangkan perilaku bot yang tidak terlihat di tempat lain. Boleh diciutkan; tidak boleh dihapus.
4. **Pratinjau invoice di OrderDetail** adalah satu-satunya tempat admin memeriksa PDF sebelum terkirim ke customer. Boleh pindah ke tab; tidak boleh hilang.
5. **Tab 9 status di Orders** setara dengan filter yang dipakai bot. Jumlah dan namanya mengikuti status order di database.
6. **Lebar 2112 px di Pipeline** sudah tertulis di `design/halaman.json`; mengubahnya berarti memotret ulang acuan dan menyesuaikan uji visual.

---

## 6. Urutan kerja yang disarankan

Setelah Anda menyiapkan `design/source` yang baru, tiap halaman dikerjakan sebagai satu putaran:

1. `npm run foto-desain -- <Kode>` → acuan baru.
2. Sesuaikan halaman React-nya.
3. `npm run visual -- <Kode>` sampai selisih ≤ 0,5%.
4. `npm run responsif -- <Kode>`.
5. `npm run types` dan `php artisan test`.
6. Satu commit: `halaman: <Kode> sesuai desain`.

Yang paling hemat: **kerjakan skala teks (1.1) lebih dulu di `tokens.css` + semua `design/source`**, karena ia menyentuh 27 halaman sekaligus dan membuat selisih visual tiap halaman berikutnya lebih mudah dibaca. Setelah itu P1 (5 halaman), lalu P2 (7), lalu P3 (9). P4 kemungkinan tidak perlu disentuh.

Garis dasar saat ini bersih: 30/30 halaman lolos uji visual dan 100/100 pemeriksaan lebar kecil lolos, jadi tiap selisih yang muncul nanti berasal dari desain baru, bukan dari sisa pekerjaan lama.
