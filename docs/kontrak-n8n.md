# Kontrak panel ↔ n8n ↔ WAHA (Tahap 4)

Panel dan n8n memakai **database yang sama** (`bangbana`). Arah komunikasi:

| Arah | Cara | Isi |
|---|---|---|
| Panel → n8n | webhook bertanda tangan | Jawab lewat Adiva, Uji Adiva, kabar konfigurasi. Perintah order, uji cek ongkir, dan kirim ulang invoice hanya bila `BOT_DRIVER=langsung`; dengan `lokal` panel mengerjakannya sendiri (§8b) |
| Panel → WAHA | HTTP API WAHA | balasan admin, broadcast, kode OTP, kirim uji, kabar WA ke supervisor (§9), QR & restart sesi |
| Panel → Meta | Graph API | balasan admin di Instagram & Facebook, uji sambungan (§10) |
| n8n → panel | tulis langsung ke tabel | pesan, pesan sosmed, eksekusi, insiden, notifikasi, riwayat |

**Bot dikendalikan dari panel saja (sejak 9 Okt 2026).** Perintah admin yang diketik di WhatsApp tidak dijalankan lagi (§2d), dan semua kabar ke supervisor ditulis n8n ke tabel `notifikasi`; panel yang mengirim WhatsApp singkatnya (§9).
| Panel → n8n (tidak langsung) | tabel `jeda_bot`, `prompt_versi`, `workflow.pengaturan` | jeda / lanjutkan bot, prompt aktif, pengaturan AI |

## 1. Pengaturan `.env` panel

```dotenv
BOT_DRIVER=langsung          # tiruan = tidak ada yang dikirim (bawaan; selalu tiruan bila APP_DEMO=true)
                             # lokal = panel mengerjakan sendiri yang sudah diambilnya, sisanya lewat n8n (§8b)
N8N_WEBHOOK_URL=https://n8n.contoh.id/webhook/panel-perintah
N8N_WEBHOOK_SECRET=…         # rahasia panjang acak, sama persis dengan di n8n
N8N_TIMEOUT=15               # detik menunggu jawaban n8n
N8N_TIMEOUT_UJI=60           # detik menunggu Uji Adiva / uji cek ongkir (agen AI lebih lama)
N8N_EDITOR_URL=https://n8n.contoh.id   # tujuan tombol "Buka n8n" di Otomasi
WAHA_URL=https://waha.contoh.id
WAHA_API_KEY=…               # WHATSAPP_API_KEY di WAHA
WAHA_TIMEOUT=15
WAHA_WEBHOOK_SECRET=…        # kunci HMAC webhook WAHA ke panel (BOT_DRIVER=lokal, §8b)
N8N_TIMEOUT_JAWAB=180        # detik menunggu Adiva menjawab satu pesan customer (§8b)
```

Setelah diisi, jalankan `php artisan bot:cek`. Perintah ini mengirim `PING` bertanda tangan ke n8n dan membaca `/api/sessions` WAHA.

Balasan admin dan broadcast dikirim lewat antrean (`QUEUE_CONNECTION=database`), jadi worker harus berjalan:
- saat pengembangan: `composer dev` (sudah termasuk worker);
- di server: `php artisan queue:work`.

Penjadwal Laravel menjalankan `broadcast:lanjutkan` tiap 5 menit (memulai ulang kampanye yang pengirimannya macet):
- saat pengembangan: `php artisan schedule:work` di terminal terpisah (`composer dev` belum menjalankannya);
- di server: cron `* * * * * php artisan schedule:run`.

Antrean `sync` tidak bisa menunggu jeda antarpesan, jadi broadcast hanya berjalan dengan antrean sungguhan.

## 2. Webhook perintah order (panel → n8n)

> Dengan `BOT_DRIVER=lokal` perintah order, `CEK_ONGKIR`, dan `KIRIM_ULANG_INVOICE` tidak dikirim ke sini lagi (§8b). Bagian ini berlaku untuk `langsung` dan tetap menjadi rujukan isi perintahnya.

Buat workflow n8n dengan node **Webhook** (`POST`, path `panel-perintah`, *Respond: Using 'Respond to Webhook' node*).

### Permintaan

Header:

| Header | Isi |
|---|---|
| `Content-Type` | `application/json` |
| `X-Adiva-Timestamp` | detik Unix saat dikirim, mis. `1790664120` |
| `X-Adiva-Signature` | `sha256=` + HMAC-SHA256(`N8N_WEBHOOK_SECRET`, `timestamp + "." + body mentah`) dalam hex |

Body:

```json
{
  "perintah": "ACC",
  "order_id": "ORD-260928-014",
  "catatan": null,
  "oleh": { "id": 1, "nama": "Falah", "nomor_wa": "6281234560007" },
  "sumber": "panel",
  "request_id": "9b2f6c1e-4f0a-4d0e-9d55-2a7f3c1b8e10",
  "waktu": "2026-09-28T13:42:00+07:00"
}
```

- `perintah`: `ACC`, `TOLAK`, `BATAL`, `LUNAS`, `SIAP`, `KIRIM`, `SELESAI`, `TUTUP`, `BATAL KIRIM`, atau `PING` (dari `bot:cek`, cukup dijawab `ok`). Perintah lain di webhook yang sama ada di §2b.
- `catatan`:
  - `KIRIM`: nomor resi; kosong = n8n membuat order pengiriman di Mengantar (resi menyusul, hasilnya jadi notifikasi `PENGIRIMAN`).
  - `ACC`: ongkir pasti (`25000`, `25rb`, `gratis`); kosong = ongkir dari bot. ACC + ongkir pada order `DIACC` yang belum lunas = **revisi ongkir** (nomor invoice sama, invoice dikirim ulang, batas bayar dihitung lagi).
  - `TOLAK` / `BATAL` / `TUTUP`: alasan atau catatan.
- n8n merangkai `perintah order_id catatan` jadi teks perintah lalu menjalankannya di **Parse Perintah** (jalur A), mis. `BATAL KIRIM ORD-260927-019`, `ACC ORD-260928-014 25000`.
- `request_id`: unik per klik. Simpan; bila datang lagi, jangan jalankan dua kali, cukup jawab hasil yang sama.

### Memeriksa tanda tangan

Node Code n8n tidak boleh memakai `require('crypto')` (modul bawaan dimatikan), jadi pemeriksaan dibagi beberapa node di workflow chatbot:

1. **Webhook Panel** (`POST panel-perintah`, *Raw Body* aktif, *Respond: Using 'Respond to Webhook' node*).
2. **Baca Perintah Panel** (Code): body mentah dari `this.helpers.getBinaryDataBuffer(0, 'data')`, plus header `x-adiva-timestamp` dan `x-adiva-signature`.
3. **Hitung Tanda Tangan** (Crypto, HMAC SHA256, hex): nilai `timestamp + "." + body mentah`, rahasia = `N8N_WEBHOOK_SECRET`.
4. **Periksa Perintah Panel** (Code): sah bila `x-adiva-signature === "sha256=" + hasil` dan timestamp tidak lebih dari 5 menit. Tidak sah → **Tolak Panel** menjawab 401.

Setelah itu **Catat Request Panel** menyimpan `request_id` di tabel `n8n_perintah`. Bila `request_id` itu sudah pernah datang, jawaban yang tersimpan dikirim lagi tanpa menjalankan apa pun.

### Yang dijalankan n8n

Jalankan alur yang **sama** dengan perintah WhatsApp admin (jalur A), dengan `oleh.nama` sebagai pelaku:

| Perintah | Status awal yang boleh | Ubah `log_order` | Lalu |
|---|---|---|---|
| ACC | MENUNGGU_KONFIRMASI | `status_order=DIACC`, `diacc_at`, `invoice_number` = berikutnya setelah `INVOICE_TERAKHIR` (format `INV/2026/09/142`), `batas_bayar_at` (jam kerja) | perbarui `config_toko.INVOICE_TERAKHIR`, buat & kirim invoice PDF, isi `log_order.invoice_pdf_url` |
| TOLAK | MENUNGGU_KONFIRMASI, DIACC, KARYAWAN | `status_order=DITOLAK`, `catatan_admin` | kabari customer |
| BATAL | MENUNGGU_KONFIRMASI, DIACC, KARYAWAN | `status_order=DIBATALKAN`, `catatan_admin` | kabari customer |
| LUNAS | DIACC, KARYAWAN, BATAL OTOMATIS | `status_order=LUNAS`, `lunas_at` | konfirmasi ke customer |
| SIAP | LUNAS, DIACC (bayar di tempat), KARYAWAN; hanya ambil sendiri | `siap_diambil_at` (status tetap) | kirim alamat & jam ambil |
| KIRIM | LUNAS; bukan ambil sendiri | `status_order=DIKIRIM`, `dikirim_at`, `no_resi = catatan` | kirim resi |
| SELESAI | DIKIRIM, LUNAS | `status_order=SELESAI`, `selesai_at` | minta ulasan, CRM → REPEAT |
| TUTUP | MENUNGGU_KONFIRMASI, DIACC, KARYAWAN, LUNAS, DIKIRIM | `status_order=DITUTUP`, `catatan_admin` | tidak ada chat ke customer |
| BATAL KIRIM | LUNAS, DIKIRIM; ada `MENGANTAR ORDER …` di `catatan_admin` | (status tetap) | order pengiriman Mengantar dibatalkan |

Setiap perintah menambah baris `riwayat`:
- `subjek_type = 'log_order'`, `subjek_id = log_order.id`;
- `jenis = perintah`, `nada = 'blue'`;
- `teks` mis. `"ACC dari panel · invoice PDF dibuat & dikirim"`.

**Log aktivitas ditulis oleh panel** setelah n8n menjawab sukses. n8n tidak perlu menulisnya.

### Jawaban (node Respond to Webhook)

Jawab **setelah** database diubah, karena panel membaca ulang order begitu jawaban datang.

```json
{ "ok": true,  "pesan": "Invoice INV/2026/09/142 terkirim" }
{ "ok": false, "alasan": "Order sudah di-ACC dari WhatsApp" }
```

Cara panel membaca jawaban:

| Jawaban | Yang terjadi di panel |
|---|---|
| `ok: false` | `alasan` tampil di panel; tidak ada yang berubah |
| Tidak dijawab dalam `N8N_TIMEOUT`, status 5xx, atau bukan JSON | panel menampilkan "n8n tidak menjawab, perintah belum dijalankan. Coba lagi." |

Karena itu `request_id` penting: admin bisa menekan lagi setelah timeout.

## 2b. Perintah lain di webhook yang sama

Header, tanda tangan, `sumber`, `request_id`, `waktu`, dan cara membaca jawaban sama seperti di atas. Arahkan dengan node Switch pada `perintah`.

### `UJI_ADIVA` (Uji tanya Adiva di Katalog / Kebijakan, Uji draf di Prompt)

Jalankan agen Adiva yang sama dengan tool-nya, tetapi dalam **mode uji**: jangan kirim WhatsApp, jangan tulis `pesan`, `crm_customer`, `workflow_event`, atau order. Panel menunggu sampai `N8N_TIMEOUT_UJI`.

```json
{
  "perintah": "UJI_ADIVA",
  "bagian": "KATALOG",
  "pertanyaan": "kripca 1,8 kg kalau ambil 30 harganya brp?",
  "kebijakan_id": null,
  "prompt": null,
  "pengaturan_ai": { "AI_MODEL_UTAMA": "gemini-3.6-flash", "AI_TEMPERATURE": "0.4", "...": "..." }
}
```

- `bagian`: `KATALOG`, `KEBIJAKAN`, atau `PROMPT`.
- `kebijakan_id`: kebijakan yang sedang dibuka di halaman Kebijakan (boleh dipakai untuk mengutamakan KB itu).
- `prompt`: untuk `PROMPT` berisi draf `{ "versi": 15, "teks": "…" }`; pakai teks ini sebagai system prompt. Selain itu `null`: pakai prompt aktif (§2c).

Jawaban:

```json
{
  "ok": true,
  "jawaban": "Untuk Kripca 1,8 kg pembelian 30 bal …",
  "tool": ["Cari_Produk_per_Kategori (Keripik kaca)"],
  "penilaian": { "tipe": "RESELLER", "tahap": "TANYA_HARGA", "skor": "WARM" },
  "hasil": { "butuh_admin": false, "tahap": "TANYA_HARGA", "alasan": "tanya harga grosir" },
  "produk_id": 12
}
```

- `tool`: urutan tool yang dipanggil, boleh dengan argumen dalam kurung. Panel menulisnya "Tool dipakai: A lalu B · dinilai Reseller · Tanya harga · WARM".
- `penilaian`, `hasil`, dan `produk_id` boleh tidak ada. `hasil` ditampilkan di Uji draf (Butuh admin / Tahap / Alasan). `produk_id` menyorot baris produk di Katalog.

### `CEK_ONGKIR` (Pengaturan → "Uji cek ongkir")

```json
{ "perintah": "CEK_ONGKIR", "asal": "SAWOJAJAR, KEDUNGKANDANG, MALANG, JAWA TIMUR", "tujuan": "DINOYO, LOWOKWARU, MALANG, JAWA TIMUR", "berat_kg": 1 }
```

Jalankan `Cek_Ongkir_Mengantar` dengan `MENGANTAR_API_KEY`, pickup, dan `KURIR_DIKECUALIKAN` dari `config_toko`, lalu jawab:

```json
{ "ok": true, "tarif": [ { "kurir": "JNE", "layanan": "REG", "harga": 38000, "estimasi": "2–3 hari" } ] }
```

Bila Mengantar menolak, jawab `{ "ok": false, "alasan": "API key Mengantar ditolak" }`; alasannya tampil di Pengaturan.

### `KIRIM_ULANG_INVOICE` (Pesanan → "Kirim ulang ke customer")

```json
{ "perintah": "KIRIM_ULANG_INVOICE", "order_id": "ORD-260927-031", "invoice_number": "INV/2026/09/138", "oleh": { "id": 1, "nama": "Falah", "nomor_wa": "6281234560007" } }
```

Kirim lagi PDF invoice yang sama ke customer dari nomor bot yang ia pakai (`sesi_wa`). Bila PDF belum ada, buat dulu dan isi `log_order.invoice_pdf_url`. Jawab `{ "ok": true }` atau `{ "ok": false, "alasan": "…" }` (tampil di panel). Log aktivitas ditulis panel.

### `JAWAB` (Percakapan → "Jawab lewat Adiva")

Saat Adiva meneruskan pertanyaan ke supervisor (`crm_customer.eskalasi_terbuka`, ≤ 72 jam, belum dijawab), panel menampilkan pertanyaannya dengan kotak jawaban.

```json
{ "perintah": "JAWAB", "chat_id": "6281234567890@c.us", "jawaban": "Bisa kak, untuk 200 pcs harga Rp9.500 per pack.", "oleh": { "id": 5, "nama": "Ahmad Falahi", "nomor_wa": "628965609705" } }
```

n8n menjalankan cabang JAWAB (jalur B): **Susun Jawaban AI** menyampaikan jawaban dengan bahasa Adiva (semua angka sama persis), mengirimnya dari `sesi_wa` customer, mencatat pesannya, lalu mengisi `jawaban_supervisor` dan mengosongkan `eskalasi_terbuka`. Jawab `{ "ok": true, "pesan": "…" }` setelah terkirim, atau `{ "ok": false, "alasan": "…" }`. Panel menunggu sampai `N8N_TIMEOUT_UJI`. Log aktivitas ditulis panel.

### `KONFIG_BERUBAH` (kabar, boleh diabaikan)

Dikirim lewat antrean. n8n membaca semuanya sendiri dari database, jadi kabar ini hanya berguna bila n8n menyimpan cache. Cukup jawab `{ "ok": true }`.

| `apa` | Kapan | Isi lain |
|---|---|---|
| `PROMPT` | prompt diterbitkan (§2c) | `versi` |
| `PENGATURAN_AI` | pengaturan AI diubah (§2c) | `pengaturan` |
| `AKSES_ADMIN_WA` | admin dikeluarkan dari WhatsApp, atau aksesnya pulih (§2d) | `user_id`, `nomor_wa`, `dicabut` (bool) |

## 2c. Prompt dan pengaturan AI (n8n membaca database)

Di awal setiap chat, sebelum node Agen AI Penjawab:

```sql
-- system prompt Adiva: versi yang diterbitkan di panel
select versi, teks_lengkap from prompt_versi where status = 'AKTIF' order by versi desc limit 1;

-- model dan perilaku
select pengaturan from workflow where kode = 'chatbot';
```

- `teks_lengkap` adalah semua bagian prompt berurutan, masing-masing `## {no}. {judul}` lalu isinya. Panel memperbaruinya setiap versi disimpan.
- `pengaturan`: `model_utama`, `model_cadangan`, `temperature` (angka), `ingatan_pesan` (jumlah pesan yang diingat), `tunggu_gabung_detik` (jeda menggabungkan pesan beruntun).
- "Terbitkan" di panel langsung berlaku untuk chat berikutnya. Prompt di node n8n tidak dipakai lagi.

### Tool `Cari_Barang_Toko` (barang Toko Sawojajar, sejak 31 Okt 2026)

Panel menyalin barang toko dari Accurate (database "Bana Food") ke tabel `barang_toko` tiap menit (`php artisan accurate:sinkron` di scheduler, hanya membaca Accurate; berjalan selama `ACCURATE_API_TOKEN` terisi, juga dalam mode demo). Yang disimpan hanya barang **ber-barcode dan tidak nonaktif**; stok tidak dibaca. Kolom: `nama`, `kode`, `barcode`, `kategori`, `satuan`, `harga` (harga jual utama, boleh kosong), `disinkron_at`. Riwayat setiap sinkron ada di `sinkron_accurate`. Panel masuk ke Accurate lewat OAuth ("Sambungkan Accurate" di Katalog → Barang toko; token terenkripsi di `akses_accurate`, diperbarui sendiri) atau lewat API Token di `.env`.

Node **Cari Barang Toko** (postgresTool, kredensial sama dengan Paket Reseller) tersambung sebagai `ai_tool` ke Agen AI Penjawab, Agen AI Penjawab (Ulang), dan Agen Uji. `queryReplacement`: `{ "k0": $fromAI('kata_kunci', …) }`.

```sql
SELECT nama, kategori, satuan, harga, to_char(disinkron_at AT TIME ZONE 'Asia/Jakarta', 'DD-MM-YYYY HH24:MI') AS data_per
FROM barang_toko b
WHERE b.barcode = trim($1::jsonb ->> 'k0')
   OR NOT EXISTS (
       SELECT 1 FROM regexp_split_to_table(trim($1::jsonb ->> 'k0'), '\s+') AS kata
       WHERE kata <> '' AND b.nama NOT ILIKE '%' || kata || '%'
   )
ORDER BY nama LIMIT 30
```

Setiap kata kunci harus ada di nama (urutan bebas: "bubuk bawang" menemukan "Bawang Putih Bubuk"), atau kata kuncinya sama persis dengan barcode. Memasang: `node storage/app/n8n-cadangan/skrip/n8n-barang-toko.mjs <akar-panel> <id-workflow-panel>` menulis hasil ke `storage/app/n8n-cadangan/panel-barang-toko.json`; tambahkan `--kirim` untuk mengirimnya ke n8n. Aturan pemakaiannya (hanya dijual di toko, tidak bisa dipesan lewat WhatsApp, stok tidak dijanjikan) ada di prompt bagian 3 "BARANG TOKO". Barang toko yang terlanjur masuk order ditandai "⛔ BARANG TOKO" di notifikasi order (`CekHargaOrder`).

## 2d. Admin lewat WhatsApp (tidak dipakai lagi)

Node "Cek Apakah Admin" tetap mengenali admin (nomor di `users.nomor_wa` yang tidak nonaktif, atau `config_toko.ADMIN_WA`) supaya pesannya tidak dijawab AI. Perintah yang diketik admin (ACC, TOLAK, …, JEDA, LANJUT, DAFTAR JEDA, CATAT, JAWAB, BANTU) **tidak dijalankan**: node **Arahkan ke Panel** membalas "Perintah lewat WhatsApp sudah dipindah ke panel Adiva…". Semua perintah datang dari tombol panel (§2, §2b); jeda dan catat order ditulis panel langsung ke database.

## 2e. Invoice PDF

Setiap kali membuat PDF invoice (ACC, revisi ongkir), n8n menyimpannya di tabel `invoice_pdf` lewat `n8n_simpan_invoice_pdf` (satu baris per order, isi `bytea`). Tombol "Unduh PDF" di Detail pesanan membuka `/pesanan/{order}/invoice.pdf`, dan `KIRIM_ULANG_INVOICE` mengirim berkas yang sama. Bila PDF ada di tempat lain, `log_order.invoice_pdf_url` tetap dipakai sebagai cadangan. Selama keduanya kosong, tombol itu tidak aktif.

## 3. Balasan admin (panel → WAHA)

Panel memanggil:

```
POST {WAHA_URL}/api/sendText
X-Api-Key: {WAHA_API_KEY}
{ "session": "<sesi_wa customer, atau SESI_UTAMA>", "chatId": "<crm_customer.chat_id>", "text": "…" }
```

Alurnya di panel:
- Baris `pesan` (`pengirim = ADMIN`, `user_id`) dibuat dulu dengan `status_kirim = MENUNGGU`.
- Berhasil → `TERKIRIM` + `wa_message_id`.
- Gagal 3× (jeda 10 dtk lalu 60 dtk) → `GAGAL` + `meta.galat`, dengan tautan "Kirim ulang" di Percakapan.
- Bila customer belum dijeda, panel membuat jeda 2 jam (`jeda_bot`, `alasan = 'Balas manual dari panel'`).

**Penting untuk n8n:**
- WAHA mengirim ulang pesan keluar ini ke webhook n8n sebagai event `fromMe`. Lewati pesan yang `wa_message_id`-nya sudah ada di `pesan`, supaya tidak tercatat dua kali.
- Jangan dijawab bot: customer itu sedang dijeda.

## 4. Broadcast (panel → WAHA, n8n mencatat tanggapan)

**Panel yang mengirim.** Workflow "Pengirim broadcast" di n8n tidak perlu mengirim apa pun lagi. Aturannya:
- satu pesan per langkah, jeda acak 15–45 dtk (Aman) atau 8–20 dtk (Normal);
- hanya 08.00–20.00 WIB, maksimal 300 pesan per hari untuk semua kampanye;
- 5 gagal beruntun → kampanye `DIJEDA`; dilanjutkan lewat "Kirim ulang ke yang gagal";
- penerima yang opt-out atau sedang dijeda dilewati (`GAGAL`, `keterangan_gagal = 'dilewati: …'`);
- kampanye terjadwal mengunci daftar penerima saat mulai.

Isi pesan:
- `{nama}` (nama panggilan), `{kota}`, `{minat_produk}` diisi dari `crm_customer`;
- tombol dikirim sebagai baris bernomor (`1. Mau order`), karena WAHA tidak mendukung tombol WhatsApp;
- baris `Balas STOP bila tidak ingin menerima info lagi` bila opsinya aktif;
- dengan foto: `POST /api/sendImage`, teks menjadi caption. Foto yang diunggah ke panel diambil WAHA dari `APP_URL/storage/...`, jadi `APP_URL` harus bisa dijangkau dari server WAHA.

Setiap pesan terkirim:
- `broadcast_penerima`: `status = TERKIRIM`, `terkirim_at`, `wa_message_id`, `sesi_wa`;
- `pesan`: baris baru `pengirim = ADIVA`, `broadcast_id`, `meta.otomatis = BROADCAST`, `wa_message_id` (tampil di utas Percakapan).

Kampanye selesai → `status = SELESAI`, dan notifikasi `BROADCAST_SELESAI` dibuat panel.

**Yang dicatat n8n** (dari webhook WAHA):

| Kejadian | Yang ditulis |
|---|---|
| Event `fromMe` | lewati bila `wa_message_id` sudah ada di `pesan` |
| ack READ (`message.ack`, ack = 3) | `broadcast_penerima.dibaca_at` pada baris dengan `wa_message_id` yang sama |
| Pesan masuk dari chat yang punya baris `TERKIRIM` dalam 3 hari dan `dibalas_at` kosong | `dibalas_at`, `balasan` (teks pertama) |
| Balasan "STOP" | `minta_stop = true`, lalu `crm_customer.status_optout = true` |
| Dalam jendela yang sama tahap naik / skor jadi HOT / order dibuat | `naik_tahap`, `jadi_hot`, `log_order_id` |

Bila `broadcast.opsi.konteks = true`, sertakan `broadcast.pesan` dalam konteks Adiva saat menjawab balasan customer itu.

## 5. Langsung dari panel ke WAHA

Tiga fitur ini tidak lewat n8n. Semuanya dikirim dari `SESI_UTAMA` (bila kosong: nomor bot pertama):
- **Kode OTP** Daftar dan Lupa sandi ke nomor staf. Bila WAHA menolak, kode tidak disimpan dan staf melihat "Kode gagal dikirim ke WhatsApp. Coba lagi sebentar.". Saat reset kata sandi terkunci (3× salah), supervisor (`ADMIN_WA`) dikabari.
- **Kirim uji kampanye** ke `ADMIN_WA`, dirakit untuk customer yang tampil di pratinjau, lewat nomor bot yang dipilih.
- **Lihat QR / Restart sesi** (Monitoring, hanya supervisor): bekerja pada nomor yang status terakhirnya `PUTUS` (bila semua terhubung: `SESI_UTAMA`).
  - `GET /api/sessions/{sesi}`, lalu `GET /api/{sesi}/auth/qr` (PNG, dibuka di tab baru);
  - `POST /api/sessions/{sesi}/restart`.

## 6. Jeda dan lanjutkan bot (tabel `jeda_bot`)

Panel menulis langsung ke `jeda_bot` (halaman Jeda, tombol "Lanjutkan bot", dan balas manual). n8n memeriksa tabel ini **setiap ada pesan masuk**:

- **Jeda aktif:** `chat_id` sama, `selesai_at IS NULL`, dan (`sampai IS NULL` atau `sampai > now()`). Bot diam.
  - Kabari customer sekali, lalu isi `sudah_diberi_tahu = true`.
  - Kabari supervisor ada chat baru: notifikasi `JEDA_CHAT`.
- **Jeda kedaluwarsa** (`sampai` lewat): isi `selesai_at = sampai`, `diakhiri_oleh = 'Sistem'`, lalu bot lanjut menjawab.
- **"Lanjutkan" di panel:** panel mengisi `selesai_at`, `diakhiri_oleh`, `catatan_selesai`; catatan serah terima juga ditambahkan ke `crm_customer.catatan_tetap`. Adiva membaca `catatan_tetap` di setiap balasan.

## 7. Yang ditulis n8n agar halaman panel hidup

### `pesan`
Setiap pesan masuk dan keluar:
- `crm_customer_id`, `chat_id`, `sesi_wa`, `waktu`;
- `pengirim` (`CUSTOMER` / `ADIVA` / `SISTEM`), `teks`;
- `gambar_produk_id` + `lampiran_keterangan` (`"Foto produk · <judul>"`), `tombol` (array teks);
- `jalur` (A/B/C/D/DIABAIKAN, untuk pesan masuk), `status_kirim`, `wa_message_id`;
- pesan otomatis memakai `meta.otomatis` = `SAPAAN` / `KESAN` / `PENGINGAT_BAYAR` dan `meta.template` = kode FU.

### `workflow_event`
- **`EKSEKUSI`** satu baris per eksekusi workflow:
  - `status` `BERHASIL`; `ERROR` untuk chatbot yang masuk cabang error tapi tertangani; `GAGAL` untuk workflow yang gagal (memicu Notifikasi Error);
  - `durasi_ms`, `crm_customer_id`;
  - untuk chatbot, `data` = `{ jalur, pesan (jumlah pesan yang digabung), model, model_ms, ongkir (bool), diteruskan (bool) }`.
- **`INSIDEN`:** `status` `TERTANGANI` / `PERLU_TINDAKAN`, `judul`, `detail`, `aksi`; `data.jenis = 'AI_GAGAL_TOTAL'` bila AI gagal total.
- **`KONEKSI`** (Pantau Koneksi): `status` `TERHUBUNG` / `PUTUS`, `data.sesi`; `data.pesan_supervisor` bila supervisor dikabari.
- **`LAPORAN`** (Laporan Harian): `data.pesan`.

### Tabel lain
- **`notifikasi`:** setiap kabar ke supervisor (`jenis` sesuai `App\Enums\JenisNotifikasi`), dengan `crm_customer_id` / `log_order_id` bila ada.
- **`crm_customer`:**
  - `tgl_interaksi_terakhir`, `pesan_terakhir_customer`, `ringkasan_terakhir`, `tahap_funnel`, `skor_prospek`, dsb.;
  - `tahap_sejak` (JSON tahap → waktu) setiap tahap berubah;
  - saat tahap diubah Adiva, kosongkan `tahap_manual_oleh_id` dan `tahap_manual_at`;
  - `tgl_followup_berikutnya` saat menjadwalkan sapaan;
  - `butuh_admin` + `alasan_butuh_admin`;
  - `mem_gen`: dibaca sebagai sesi ingatan; panel menaikkannya lewat "Reset memori".

### Chat chatbot utama dari Data Table (`N8N_SINKRON_CHAT=true`, sejak 3 Nov 2026)

Chatbot yang berjalan ("chatbot bangbana (main)") tidak menulis `pesan` dan `crm_customer`. Ia mencatat chat di Data Table n8n **RIWAYAT_CHAT** (`chat_id`, `peran` customer/adiva, `teks`, `waktu_ms`) dan profil di **CRM_CUSTOMER** (kolom sama dengan `crm_customer`).

- **Cara salin.** Selama `N8N_SINKRON_CHAT=true` (dan bukan mode demo), panel menyalin keduanya lewat API n8n: `GET /api/v1/data-tables/{id}/rows`, **hanya baca**. Workflow bot tidak disentuh.
- **Customer** lewat `n8n_simpan_crm` (§8), hanya baris yang `updatedAt`-nya berubah sejak sinkron terakhir.
- **Pesan** diambil dari WhatsApp sendiri (WAHA) lewat workflow **"Bang Bana — Gerbang WAHA (panel adiva)"** (`POST /webhook/panel-waha`, perintah `BACA_PESAN`, ditandatangani seperti §2 dengan `WAHA_GERBANG_SECRET`), sekali per `wa_message_id`.
  - Yang dibaca tiap putaran: chat yang customernya berubah, chat yang aktif (interaksi 1 hari / pesan 3 hari terakhir), dan chat yang belum pernah dibaca. Maksimal 40 chat, 20 per panggilan.
  - `fromMe` dengan `source = app` (diketik di HP) menjadi baris ADMIN; `fromMe` lainnya ADIVA. Foto/suara/berkas ditandai di `lampiran_keterangan` tanpa berkasnya.
  - Saat satu chat pertama kali terbaca dari WAHA, barisnya yang berasal dari RIWAYAT_CHAT dihapus.
  - Selama gerbang belum diatur, pesan disalin dari RIWAYAT_CHAT (memori bot: satu putaran digabung jadi satu baris, hanya yang dibalas bot), dikenali dari `pesan.meta.riwayat_id`.
- **Pemicu:**
  - Perintah `php artisan n8n:sinkron-chat`, dijadwalkan tiap menit di server yang menjalankan scheduler.
  - Di Vercel, workflow **"Bang Bana — Sinkron Chat (panel adiva)"** memanggil `POST /cron/chat-sinkron` tiap menit, dengan header `X-Cron-Key` = `ACCURATE_CRON_KEY` bila diisi.
  - Endpoint itu berjalan paling sering sekali per 30 detik.
- **Percakapan hanya lihat.** Balas, Template, Jeda/Lanjutkan, Mulai ulang bot, Jawab lewat Adiva, dan Kirim ulang disembunyikan dan ditolak server, karena chatbot utama membaca jeda dan memorinya sendiri (Data Table JEDA_BOT), bukan milik panel.
- **Kode login.** `WAHA_KODE_STAF=true` mengirim kode masuk/daftar/reset staf lewat WAHA (`SESI_UTAMA`) walau `BOT_DRIVER=tiruan`; bila `WAHA_GERBANG_URL` dan `WAHA_GERBANG_SECRET` diisi, lewat gerbang n8n (perintah `KIRIM_TEKS`), karena Vercel tidak bisa menjangkau WAHA di VPS. Bot mengabaikan pesan `fromMe`, jadi tidak membalasnya.

## 8. Lapisan database untuk n8n (sejak 8 Okt 2026)

Workflow n8n semula ditulis untuk **n8n Data Table** (waktu sebagai teks `2026-09-28 13:42` WIB, `YA`/`TIDAK`, isi order sebagai teks + JSON). Semua node Data Table sekarang menjadi node **Postgres** ke database panel lewat lapisan di migrasi `2026_10_08_100001_create_n8n_adaptor`. Logika node Code tidak berubah.

### Baca: view berbentuk tabel lama

| Data Table lama | Dibaca dari | Catatan |
|---|---|---|
| CRM_CUSTOMER | `n8n_crm_customer` | waktu WIB teks, `status_optout`/`butuh_admin` `YA`/`TIDAK`, `broadcast_konteks` (kampanye ≤ 3 hari dengan `opsi.konteks`) |
| LOG_ORDER | `n8n_log_order` | `items` dan `items_detail` dirakit dari `order_items` |
| JEDA_BOT | `n8n_jeda_bot` | semua jeda; yang berjalan `selesai_at IS NULL` |
| ADMIN_STAFF | `n8n_admin_staff` | `users` yang punya `nomor_wa` dan tidak nonaktif; `dicabut = 'YA'` bila `wa_dicabut_at` terisi |
| TEMPLATE_FOLLOWUP | `n8n_template_followup` | `status_aktif` `YA`/`TIDAK` |
| GAMBAR_PRODUK | `n8n_gambar_produk` | hanya yang `aktif` |
| KEBIJAKAN | `kebijakan` | "SIAP" = `status_data = 'AKTIF'`; **Panduan Jualan** = kebijakan aktif yang kodenya diawali `PJ-` |
| PAKET_RESELLER | `paket_reseller` | "SIAP" = `status_data = 'AKTIF'` |
| CONFIG_TOKO, PRODUK, KARYAWAN, HARGA_KARYAWAN, ARSIP_DATA | tabel panel bernama sama | — |
| BUFFER_PESAN, SOSMED_KONTAK | tabel baru `buffer_pesan`, `sosmed_kontak` | hanya untuk n8n, tanpa halaman |
| RIWAYAT_JEDA | `jeda_bot` | jeda tidak dihapus: `selesai_at`, `diakhiri_oleh`, `catatan_selesai` |

### Baca: dua bacaan yang dulu berupa rantai node (sejak 13 Okt 2026)

Migrasi `2026_10_13_100000_ringkas_adaptor_n8n` menggabungkan bacaan yang sama yang berdiri sebagai beberapa node. Perilakunya sama; yang berubah hanya jumlah node dan jumlah jalan ke database.

| Fungsi | Menggantikan |
|---|---|
| `n8n_konteks_awal()` | **Ambil Konteks Awal** di pintu masuk dan **Ambil Konteks Awal (Jadwal)** di pengingat 09:00. Satu baris: `admin_wa` (ADMIN_WA), `sarah_wa` (SARAH_WA), `sesi_utama` (SESI_UTAMA), `daftar_nomor_bot` (DAFTAR_NOMOR_BOT), `staf` (array JSON dari `n8n_admin_staff`). Dulu empat node CONFIG_TOKO/ADMIN_STAFF berjalan berurutan sebelum pesan disaring, dan tiga di antaranya diulang di jalur jadwal |
| `n8n_boleh_balas(j)` | **Boleh Balas Customer?** (C), **Masih Boleh Menjawab?** (D5), **Fallback Boleh Dikirim?** (D5b) — dulu kueri 180 karakter yang sama di tiga node. Satu baris selama bot masih boleh menjawab, nol baris begitu customer dijeda, jadi cabang di belakangnya bekerja seperti sebelumnya |

Node Code membaca `staf` sebagai kolom satu baris itu, bukan satu item per staf: `(($('Ambil Konteks Awal').first().json || {}).staf || []).map(s => ({ json: s }))`.

### Tulis: satu fungsi per jenis tulisan

Setiap fungsi menerima satu objek JSON (`$1::jsonb`) dan hanya mengubah kunci yang dikirim. Semuanya `SECURITY DEFINER`.

| Fungsi | Dipakai untuk |
|---|---|
| `n8n_simpan_crm(j, buat)` | Simpan CRM, Update CRM Closing, Reset Memori Chat (`buat = true`); update CRM lain (`false`). Tahap/skor/tipe yang tidak dikenal panel diabaikan; `tahap_sejak` diisi; tanda tahap manual dihapus saat Adiva mengubah tahap; tanggapan broadcast (`naik_tahap`, `jadi_hot`, `minta_stop`); `pesan` tanpa customer ditautkan |
| `n8n_catat_order(j)` | Catat Order, Catat Order Manual: `log_order` + `order_items` dari `items_detail`, `crm_customer_id`, `broadcast_penerima.log_order_id` |
| `n8n_ubah_order(j)` | semua update order. Status baru mengisi `diacc_at` (+ `batas_bayar_at` dalam jam kerja), `lunas_at`, `dikirim_at`, `selesai_at`; `perintah = SIAP` → `siap_diambil_at`; `bukti_masuk`, `diingatkan`; `status_lama` = hanya bila statusnya masih itu; `riwayat` (+ `riwayat_oleh`) menambah baris riwayat order |
| `n8n_jeda(j)` / `n8n_akhiri_jeda(j)` | JEDA / LANJUT / jeda kedaluwarsa (`kedaluwarsa = YA` → `diakhiri_oleh = 'Sistem'`) |
| `n8n_catat_pesan(j)` | setiap pesan masuk/keluar; `wa_message_id` yang sudah ada dilewati; balasan pertama ke broadcast dan `STOP` |
| `n8n_pesan_dibaca(wa_message_id)` | WAHA `message.ack` READ → `broadcast_penerima.dibaca_at` |
| `n8n_notifikasi(j)`, `n8n_event(j)` | `notifikasi` (kategori dari `jenis`) dan `workflow_event` (EKSEKUSI digabung per `n8n_execution_id`) |
| `n8n_simpan_invoice_pdf(j)` | PDF invoice (§2e) |
| `n8n_simpan_arsip(j)` / `n8n_hapus_arsip(j)` | Arsip Data: salin sekali ke `arsip_data`, lalu hapus hanya yang sudah tersalin |

Pembantu: `n8n_wib`, `n8n_waktu`, `n8n_angka` ("Rp35.000", "5 jt"), `n8n_desimal`, `n8n_ya`, `n8n_json`, `n8n_nomor`, `n8n_nama_admin`, `n8n_tambah_jam_kerja` (= `JamKerja::tambah`).

### Status order dari workflow

- **TUTUP ORD-…** (WhatsApp) → status `DITUTUP`, label "Ditutup", masuk tab "Batal / tolak". Customer tidak dikabari dan tidak di-follow up.
- Batas Bayar menutup order dengan status `BATAL OTOMATIS`. `LUNAS` tetap boleh untuk order itu bila dananya ternyata masuk.
- Perintah `SINKRON` (spreadsheet) dihapus: katalog diubah di panel.

### Yang dikerjakan panel

- `php artisan n8n:sinkron` (tiap menit, kecuali mode demo) membaca API n8n (`N8N_API_URL`, `N8N_API_KEY`):
  - mengisi `workflow.n8n_workflow_id`, `jumlah_node`, dan status (aktif → AKTIF; Notifikasi Error → SIAGA; mati → NONAKTIF);
  - mencatat eksekusi yang selesai sebagai `workflow_event` EKSEKUSI. Eksekusi chatbot yang sukses tanpa baris sendiri adalah pesan yang digabung ke eksekusi lain, jadi dilewati.
- `php artisan prompt:impor-n8n <berkas cadangan workflow>` (sekali): system prompt yang tertulis di node "Agen AI Penjawab" menjadi versi prompt AKTIF, satu bagian per blok "1. IDENTITAS". Setelah itu agen membaca `prompt_versi.teks_lengkap`; model, suhu, ingatan, dan lama tunggu gabung pesan dibaca dari `workflow.pengaturan`.

### Memasang di server

1. `php artisan migrate`.
2. Buat peran database khusus n8n (bukan superuser), lalu kredensial **Postgres** di n8n dengan peran ini:
   ```sql
   CREATE ROLE n8n_adiva LOGIN PASSWORD '…';
   GRANT CONNECT ON DATABASE bangbana TO n8n_adiva;
   GRANT USAGE ON SCHEMA public TO n8n_adiva;
   GRANT SELECT ON n8n_crm_customer, n8n_log_order, n8n_jeda_bot, n8n_admin_staff, n8n_template_followup, n8n_gambar_produk,
     config_toko, produk, kebijakan, paket_reseller, karyawan, harga_karyawan, prompt_versi, workflow, arsip_data, invoice_pdf TO n8n_adiva;
   GRANT SELECT, INSERT, UPDATE, DELETE ON buffer_pesan, sosmed_kontak, n8n_perintah TO n8n_adiva;
   GRANT SELECT ON sosmed_pesan TO n8n_adiva;
   GRANT USAGE, SELECT ON SEQUENCE buffer_pesan_id_seq, sosmed_kontak_id_seq, n8n_perintah_id_seq TO n8n_adiva;
   GRANT UPDATE (nilai, updated_at) ON config_toko TO n8n_adiva;
   GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO n8n_adiva;
   ```
3. Buat kredensial **WAHA** (URL + `WAHA_API_KEY`) dan **Google Gemini** di n8n.
4. Di node **Hitung Tanda Tangan**, isi rahasia yang sama dengan `N8N_WEBHOOK_SECRET`.
5. Arahkan webhook sesi WAHA ke URL produksi node "Trigger Pesan WhatsApp Masuk", dengan event `message` dan `message.ack`.

## 8b. Panel mengerjakan sendiri (`BOT_DRIVER=lokal`, sejak 13 Okt 2026)

Bot dipindah dari n8n ke panel bertahap. Dengan `BOT_DRIVER=lokal`, apa yang sudah diambil panel dikerjakan panel; sisanya tetap lewat n8n seperti pada `langsung`. `APP_DEMO=true` tetap memaksa tiruan.

**Langkah 1 — perintah order, cek ongkir, kirim ulang invoice**

| Dulu lewat n8n | Sekarang | Kelas |
|---|---|---|
| ACC, TOLAK, BATAL, LUNAS, SIAP, KIRIM, SELESAI, TUTUP, BATAL KIRIM | dikerjakan panel | `App\Bot\LokalPerintahOrder` |
| `CEK_ONGKIR` (Uji cek ongkir) | panel memanggil Mengantar langsung | `LokalCekOngkir` → `PengirimanMengantar::tarif()` |
| `KIRIM_ULANG_INVOICE` | panel mengirim ulang PDF yang disimpan | `LokalKirimUlangInvoice` |
| `UJI_ADIVA`, `JAWAB`, `KONFIG_BERUBAH`, `PING` | **tetap n8n** (butuh Gemini, atau cuma kabar) | `N8n*` |

Satu perintah order berjalan begini:

1. Kontroler memeriksa aturan status (`PerintahPesanan::alasanTidakBerlaku`); `LokalPerintahOrder` menambah yang hanya diketahui bot: rekening harus terisi untuk ACC, ongkir armada/motor harus ada, dan KIRIM tanpa resi saat `MENGANTAR_BUAT_ORDER=MATI` meminta resi.
2. Dalam satu transaksi (`PerintahPesanan::jalankan`): status baru, nomor invoice, batas bayar, riwayat, log aktivitas, dan **catatan order berantai** (di bawah).
3. Di luar transaksi, dan tidak pernah membatalkannya: invoice PDF (ACC), pesan ke customer, CRM (SELESAI → REPEAT, TUTUP), dan order Mengantar (LUNAS, KIRIM). Yang gagal menjadi notifikasi ke supervisor, bukan perintah yang gagal.

`BATAL KIRIM` terbalik: Mengantar dibatalkan dulu, order berubah hanya kalau Mengantar berhasil. Alasannya tampil sebagai pesan galat di panel.

**Catatan order berantai.** `log_order.catatan_admin` menyimpan kata-kata yang dibaca workflow lain: Batas Bayar menghitung dari `ACC `, Laporan Harian dan alat Cek Order dari `LUNAS `, `DIKIRIM `, `SIAP DIAMBIL`, `REVISI ONGKIR`, dan panel mencari `MENGANTAR ORDER` untuk BATAL KIRIM. Kata dan pemisah ` | ` harus persis seperti ini (`PerintahPesanan::catatanBerantai`):

```
ACC 2026-09-28 13:42 ongkir Rp25.000 | LUNAS 2026-09-28 15:05 | DIKIRIM 2026-09-28 15:07 resi JNE999 (MENGANTAR ORDER M123 #<id>, JNE, jemput 2026-09-29 13:00)
```

Kata lain: `REVISI ONGKIR <waktu> ongkir Rp40.000` (sengaja tanpa awalan `ACC `), `SIAP DIAMBIL`, `DITOLAK`, `DIBATALKAN (permintaan customer)`, `DITUTUP`, `SELESAI`, `DIKIRIM <waktu> armada kantor|kurir motor`, `MENGANTAR ORDER <id> BELUM DIBAYAR`, `BATAL KIRIM <id>`.

**Invoice PDF** dibuat dompdf dari `resources/views/invoice/adiva.blade.php` (`InvoiceAdiva`). Isinya sama dengan PDF n8n lama; tampilannya berbeda sedikit. Gagal terkirim → dicoba ke chat id lain → dikirim sebagai teks (`InvoiceAdiva::teks`) dan supervisor diberi notifikasi `INVOICE_GAGAL`. PDF disimpan di `invoice_pdf` untuk "Unduh PDF" dan "Kirim ulang". Catatan: `NAMA_TOKO` dan `CATATAN_INVOICE` di Pengaturan belum dicetak di invoice (invoice n8n lama juga tidak).

**Mengantar** (`MengantarApi`, `PengirimanMengantar`) memakai pengaturan toko: `MENGANTAR_API_KEY`, `MENGANTAR_KOTA_ASAL`, `MENGANTAR_PICKUP_ADDRESS_ID` (cek ongkir), `MENGANTAR_ALAMAT_JEMPUT_ID`, `MENGANTAR_JAM_PICKUP`, `MENGANTAR_VOLUME_PICKUP`, `KURIR_DIKECUALIKAN`, dan `MENGANTAR_BUAT_ORDER` (`OTOMATIS` bawaan, `UJI` hanya pratinjau, `MATI`). Order pengiriman dibuat lewat antrean (`BuatPengirimanMengantar`), jadi **worker antrean harus berjalan**; hasilnya masuk Notifikasi (jenis `PENGIRIMAN`). Kunci API tidak pernah masuk ke pesan galat. Alat *Cek Ongkir Mengantar* milik agen AI masih berupa kode di n8n.

**Langkah 2 — jalur masuk (pintu masuk, saring pesan & jeda, gabung pesan, lampiran)**

Dulu WAHA mengirim setiap pesan ke trigger n8n. Sekarang WAHA mengirimnya ke panel: `POST /api/webhook/waha` (`WahaMasukController`). Panel hanya memeriksa tanda tangan dan mengantre `ProsesPesanMasuk`, supaya WAHA langsung dijawab.

| Dulu di n8n | Sekarang di panel |
|---|---|
| Trigger WhatsApp, Cari Nomor Pengirim, Ambil Konteks Awal, Cek Apakah Admin | `WahaMasukController`, `KenaliPengirim` (staf dan ADMIN_WA, termasuk nomor di balik `@lid` lewat `WahaChat`) |
| Jenis Pesan, Filter Pesan Valid, Pesan Diabaikan? | `SaringPesan` (lokasi, kartu kontak, dan stiker jadi teks; pesan sistem dan grup dibuang dan dihitung *Diabaikan* di Monitoring) |
| D1: Cek Jeda Bot dan seterusnya | `AlurPesanMasuk::chat()` + `JedaBot::untuk()` / `masihBerlaku()` / `akhiriKedaluwarsa()` |
| D2: buffer, Tunggu, Gabung Pesan, Tandai Dibaca, Tampilkan Mengetik | `AlurPesanMasuk::tampung()` + `GabungPesanMasuk` (job bertunda `AI_TUNGGU_GABUNG` detik, bawaan 8) |
| C: Susun Pesan Lampiran dan seterusnya | `LampiranMasuk` + `AlurPesanMasuk::lampiran()` |
| Catat Dibaca (`message.ack`) | `WahaMasukController::dibaca()` |

Yang diteruskan ke n8n hanya pesan customer yang sudah digabung, sebagai perintah `PESAN_MASUK` di webhook bertanda tangan yang sama (§2). n8n menjawab "diterima" seketika lalu meneruskan ke D3. Isinya: `chat_id`, `nomor_wa`, `nama_wa`, `pesan` (digabung dengan `
`), `message_id`, `session`, `waktu_pesan` (WIB), `jumlah_pesan`, `pn` (nomor di balik `@lid`), `label_nomor_bot`, `sarah_wa`. `request_id` dibuat dari id pesan WhatsApp, jadi pesan yang terkirim dua kali dijawab sekali.

Jalur masuk berjalan lewat antrean: **worker antrean harus berjalan**, dan jeda gabung hanya bekerja dengan worker sungguhan (bukan `sync`).

Beda yang disengaja dari n8n: pesan yang datang saat pesan sebelumnya sedang diteruskan tidak hilang (buffer hanya menghapus yang sudah digabung); bukti transfer yang datang setelah order berstatus `BATAL OTOMATIS` dicocokkan (n8n hanya mengenali `DIBATALKAN`); petunjuk untuk admin menyebut tombol panel, bukan perintah WhatsApp yang sudah tidak ada.

**Memasang:** di `.env` panel `WAHA_WEBHOOK_SECRET=<rahasia panjang>`; di WAHA arahkan webhook setiap sesi ke panel, dengan kunci HMAC yang sama:

```json
"webhooks": [{ "url": "https://panel.contoh.id/api/webhook/waha", "events": ["message", "message.ack"], "hmac": { "key": "<rahasia yang sama>" } }]
```

Urutan pindah: `BOT_DRIVER=lokal` dan worker berjalan → kirim workflow yang sudah dipangkas → ubah webhook WAHA ke panel → coba satu chat, satu foto bukti, satu pesan beruntun, dan satu pesan saat customer dijeda. Untuk kembali: ubah webhook WAHA ke n8n dan kirim ulang workflow dari `titik-balik/sebelum-langkah-2/`.

**Langkah 3 — balasan, CRM, order, Jawab lewat Adiva, dan pengingat jeda**

Setelah langkah ini n8n tinggal otaknya: D3 (konteks), D4 (agen, alat, ingatan), D5b (coba lagi dengan ingatan baru), agen kecil "Jawab lewat Adiva", dan gerbang webhook. Dari 224 node tersisa **57**.

Pesan customer yang sudah digabung (langkah 2) dikirim sebagai `PESAN_MASUK`; n8n menjawab dengan hasil AI, bukan dengan mengirim WhatsApp:

```json
{ "ok": true, "gagal": false,
  "agen":   { "balasan": "…", "tipe_customer": "RESELLER", "order_siap": false, "…": "…" },
  "src":    { "chatId": "…", "pesan": "…", "data_lama": { "…": "…" }, "karyawan_terverifikasi": false },
  "orders": [ { "order_id": "ORD-…", "status_order": "DIACC", "…": "…" } ],
  "config": [ { "kunci": "JEDA_FOLLOWUP_JAM", "nilai": "10" } ] }
```

Kalau percobaan kedua juga gagal: `{ "ok": true, "gagal": true, "galat": "…", "src": { … } }`. Panel menunggu sampai `N8N_TIMEOUT_JAWAB` (bawaan 180 detik).

| Dulu di n8n | Sekarang di panel |
|---|---|
| Rapikan Balasan (30 KB JS) | `App\Bot\RapikanBalasan` |
| D5: gelembung, tombol, gambar, indikator mengetik | `App\Jobs\KirimBalasanAdiva` + `PesanKeluar` |
| D6: Simpan CRM | `SimpanCrm::penilaian()` lewat `n8n_simpan_crm` |
| D6: Cek Order Berjalan → Putuskan Order Baru → Catat Order → Tutup Order Lama | `App\Bot\PutuskanOrder` + `CatatOrderAdiva` |
| D5b: pesan maaf, notifikasi, insiden | `App\Jobs\BalasanGagalAdiva` |
| B: Parse Perintah Jeda → Rapikan Jawaban → kirim | `App\Bot\LokalJawabAdiva` (n8n hanya membahasakan) |
| B2: pengingat jeda 09:00 | `php artisan jeda:ingatkan` (penjadwal Laravel) |

**Dua node Code yang paling menentukan diport baris demi baris, dan diuji berdampingan dengan JavaScript aslinya.** `tests/Feature/RapikanBalasanTest` (47 kasus) dan `PutuskanOrderTest` (20 kasus) menjalankan berkas asli di `tests/Fixtures/n8n/` dengan Node dan membandingkan hasilnya dengan PHP. Kalau Node tidak terpasang, kedua tes itu dilewati — jalankan di mesin yang ada Node sebelum mengubah salah satunya. `App\Bot\Js` memegang aturan JavaScript yang ditiru (truthiness, `Number()`, `String()`), supaya PHP-nya bisa dibaca sebaris-sebaris dengan JS-nya.

Beda yang disengaja dari n8n: petunjuk untuk supervisor menyebut tombol panel, bukan perintah WhatsApp yang sudah tidak ada; pengingat jeda juga begitu. Catatan order yang dibuat Adiva (kemungkinan dobel, revisi, selisih total) menjadi notifikasi tersendiri "Catatan untuk ORD-…", dulu ikut di pesan WhatsApp ke supervisor.

**Memasang:** jalur ini lewat antrean, jadi worker harus berjalan, dan penjadwal Laravel (`php artisan schedule:run` tiap menit) untuk pengingat jeda. Tidak ada pengaturan baru selain `N8N_TIMEOUT_JAWAB` (opsional). Urutan pindah sama seperti langkah 2; untuk kembali, kirim ulang workflow dari `titik-balik/sebelum-langkah-3/`.

**Pindah ke `lokal`** (urutan penting; untuk langkah 2 lihat urutannya di atas):

1. Di panel: `BOT_DRIVER=lokal`, worker antrean berjalan, `REKENING` dan pengaturan Mengantar terisi, lalu `php artisan bot:cek`.
2. Baru kirim workflow yang sudah dipangkas: `python jalankan.py <panel> --kirim` (n8n tidak lagi punya seksi A; perintah order yang salah alamat dijawab "dijalankan panel sendiri").
3. Coba satu order sungguhan dari ACC sampai SELESAI, dan satu BATAL KIRIM.

**Kembali ke n8n:** `BOT_DRIVER=langsung`, lalu kirim ulang workflow dari `storage/app/n8n-cadangan/titik-balik/sebelum-langkah-1/` (`PUT /api/v1/workflows/<id>` dengan berkas JSON-nya).

## 9. Kabar WhatsApp ke supervisor (dikirim panel)

n8n **tidak** mengirim WhatsApp ke supervisor atau Bu Sarah lagi; setiap kabar ditulis lewat `n8n_notifikasi` (§7). Satu pengecualian: **Notifikasi Error** mengirim WhatsApp sendiri bila notifikasinya gagal ditulis (database mati).

Panel menjalankan `php artisan notifikasi:kirim-wa` tiap 15 detik (penjadwal; dilewati dalam mode demo):
- mengambil notifikasi ≤ 10 menit yang `wa_status`-nya kosong;
- mengirim `*judul*`, `detail`, dan tautan `APP_URL/notifikasi/{id}` lewat `SESI_UTAMA` ke `ADMIN_WA` (atau `SARAH_WA` bila `penerima = SARAH_WA`);
- mengisi `wa_status`: `TERKIRIM`, `GAGAL`, `MATI` (jenisnya dimatikan), atau `PANEL` (jenis yang tidak dikirim WA: `PENGIRIMAN`, `BROADCAST_SELESAI`).

Pengaturan → Notifikasi WhatsApp: tiap baris bisa dimatikan; disimpan di `config_toko.NOTIF_WA_MATI` (daftar baris `App\Support\KabarWa::BARIS`). Jenis baru sejak 9 Okt: `LAMPIRAN_MASUK` (lampiran yang bukan bukti transfer), `PENGIRIMAN` (hasil order Mengantar), `SOSMED_BUTUH_ADMIN`. Judul `Tabel jeda gagal dibaca` membedakan kabar jeda dari `WORKFLOW_ERROR` lain.

## 10. Instagram & Facebook (Chatbot Sosmed)

Halaman panel → **Instagram & Facebook** (`/sosmed`; tidak tampil di menu dalam mode demo).

**Sambungan** (hanya supervisor), disimpan di `config_toko`:

| Kunci | Isi |
|---|---|
| `META_PAGE_ID`, `META_IG_ID`, `META_APP_ID` | ID halaman, akun Instagram, aplikasi Meta |
| `META_PAGE_TOKEN`, `META_APP_SECRET` | rahasia: hanya bisa diganti, tidak pernah dikirim balik ke browser, log hanya "diganti" |
| `META_VERIFY_TOKEN` | verify token webhook (bila kosong, n8n memakai `bangbana-meta-2026`) |
| `SOSMED_BOT_IG`, `SOSMED_BOT_FB` | `AKTIF` / `MATI`: Adiva membalas platform itu atau hanya mencatat |
| `SOSMED_JEDA_JAM` | bot diam berapa jam setelah admin membalas (bawaan 12) |

Alamat webhook di Meta: `…/webhook/meta-bangbana` di n8n (panel menampilkannya dari `N8N_WEBHOOK_URL`).

**Yang dikerjakan n8n:**
- memeriksa `X-Hub-Signature-256` dengan `META_APP_SECRET` (tanpa rahasia, kiriman diterima tanpa diperiksa) dan `hub.verify_token` dengan `META_VERIFY_TOKEN`;
- memanggil Graph API dengan `Authorization: Bearer META_PAGE_TOKEN` (bukan kredensial n8n);
- mencatat setiap pesan lewat `n8n_catat_sosmed(j)` → tabel `sosmed_pesan`: pesan customer dan balasan admin dari aplikasi IG/FB saat masuk; kiriman Adiva setelah terkirim, dengan `mid` dari Meta. Gema kiriman bot dan panel (`app_id = META_APP_ID`) dilewati;
- kontak baru: nama, username, dan foto dari Graph API → `sosmed_kontak.nama/username/foto_url`;
- bot diam bila `status_bot = JEDA` dan `jeda_sampai` belum lewat, atau sakelar platformnya `MATI`;
- butuh admin → `n8n_notifikasi` jenis `SOSMED_BUTUH_ADMIN`.

```json
{ "id_pengirim": "17841400000000000", "platform": "IG", "pengirim": "CUSTOMER", "teks": "kak ready basreng?", "mid": "m_abc…", "waktu": "2026-10-09T07:41:00Z" }
```

**Yang dikerjakan panel:** balasan admin (`POST me/messages`, job `KirimPesanSosmed`, 3× coba) dicatat dulu di `sosmed_pesan` (`pengirim = ADMIN`, `status_kirim` MENUNGGU → TERKIRIM + `mid`, atau GAGAL + alasan Meta, mis. di luar jendela 24 jam), lalu kontak dijeda `SOSMED_JEDA_JAM` (`status_bot = JEDA`, `jeda_sampai` ISO, `jeda_oleh`). "Jeda bot" / "Lanjutkan bot" mengubah kolom yang sama. Broadcast tetap hanya WhatsApp (Meta melarang promosi lewat DM).
