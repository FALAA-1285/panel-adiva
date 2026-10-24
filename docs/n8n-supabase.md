# Mengarahkan workflow n8n ke Supabase

Delapan workflow Bang Bana memakai **82 node Postgres**. Sebelum ini semuanya menunjuk credential `ISI-ID-KREDENSIAL` — placeholder yang ditinggalkan skrip transform, bukan credential sungguhan. Itulah sebabnya kedelapannya nonaktif: tidak satu pun bisa menyentuh database.

## Keadaan sekarang (n8n lokal)

| | |
|---|---|
| Credential | `Supabase (PostgreSQL)`, id `wRR0Fc2yLwNCf6R1` |
| Node terarah | 82 dari 82 |
| Status workflow | semuanya **tetap nonaktif** — lihat "Kenapa belum diaktifkan" |
| Cadangan sebelum | `storage/app/n8n-cadangan/2026-10-24-sebelum-supabase/` |
| Hasil, siap impor | `storage/app/n8n-cadangan/2026-10-24-supabase/` |

## Soal sertifikat: kenapa `allowUnauthorizedCerts` dinyalakan

Percobaan pertama memakai verifikasi sertifikat penuh dan ditolak n8n dengan "Couldn't connect with these settings". Galat aslinya, diambil dengan menjalankan handshake Postgres dari dalam container n8n:

```
self-signed certificate in certificate chain
```

Pooler Supabase memakai rantai sertifikat berakar pada CA milik Supabase, yang tidak ada di trust store container. Credential Postgres n8n **tidak punya kolom untuk CA kustom** — pilihannya hanya `allowUnauthorizedCerts`.

Ini bukan penurunan keamanan dibanding sisa sistem: panel sendiri memakai `DB_SSLMODE=require`, dan di libpq `require` berarti *enkripsi tanpa verifikasi sertifikat*. Hanya `verify-ca` dan `verify-full` yang memverifikasi. Jadi n8n sekarang persis sama ketatnya dengan panel. Koneksinya tetap terenkripsi TLS 1.3 — sudah diuji dari dalam container.

Kalau suatu saat ingin verifikasi penuh, jalurnya adalah memasang CA Supabase ke trust store image n8n (bukan ke credential), lalu mematikan `allowUnauthorizedCerts`.

## Kenapa belum diaktifkan

Kelima workflow terjadwal — Batas Bayar Order, Prospek Ulang Otomatis, Laporan Harian, Arsip Data, Pantau Koneksi WhatsApp — sengaja dibiarkan nonaktif di n8n lokal, karena sekarang mereka menunjuk **database produksi**:

- `Batas Bayar Order` akan membatalkan order sungguhan yang lewat tenggat.
- `Arsip Data` akan memindahkan lalu **menghapus** baris dari tabel aslinya.

Dijadwalkan, dari laptop, tanpa pengawasan. WAHA lokal memang kosong (0 sesi) sehingga tidak ada pesan yang benar-benar terkirim ke customer, tetapi tulisan ke database tetap terjadi. Dan begitu salinan di VPS ikut aktif, keduanya berjalan bersamaan.

**Aktifkan di satu tempat saja: VPS.**

## Catatan API n8n

- `POST /credentials` bisa membuat, `DELETE /credentials/:id` bisa menghapus, tetapi **tidak ada endpoint untuk mengubah**. Mengganti setelan credential berarti membuat yang baru, menambal ulang node-nya, lalu menghapus yang lama.
- `GET /credentials` membalas 200 dengan daftar kosong — API publik memang tidak mengekspos credential yang sudah ada. ID-nya hanya bisa dibaca dari URL di UI atau dari balasan saat dibuat.
- `PUT /workflows/:id` hanya menerima `name`, `nodes`, `connections`, dan `settings`. Menyertakan `active`, `id`, atau `tags` ditolak sebagai additional properties.

## Penanda "(panel adiva)" di nama workflow

n8n di VPS akan memuat **dua generasi workflow bernama sama**: yang lama (dioperasikan dengan perintah WhatsApp dari HP) dan yang baru (dikendalikan panel). Supaya tidak tercampur, kedelapan workflow baru diberi akhiran `(panel adiva)` pada namanya — mis. `chatbot bangbana (main) (panel adiva)`.

Penanda itu bukan sekadar label untuk mata manusia. `n8n:sinkron` mencocokkan workflow **berdasarkan nama** dengan `Str::contains`, jadi `chatbot bangbana (main) (panel adiva)` tetap cocok dengan pola `chatbot bangbana (main)` — dan begitu pula yang lama. Dua kandidat cocok, lalu `->first()` memilih mana saja yang kebetulan muncul duluan di balasan API. Halaman Otomasi dan Monitoring jadi menampilkan workflow yang salah, berganti-ganti tiap sinkron.

`SinkronN8n::PENANDA` menyelesaikannya dengan satu aturan: **workflow bertanda selalu menang**, bahkan atas `n8n_workflow_id` yang sudah tersimpan. Jadi saat workflow baru diimpor, panel otomatis berpindah mengikutinya tanpa perlu mengosongkan kolom itu dengan tangan. Dua tes di `tests/Feature/SelarasN8nTest.php` menjaga aturan ini.

Tiga skrip pendukung ada di `storage/app/n8n-cadangan/skrip/` (tidak masuk git, salin ke VPS saat perlu). Ketiganya membaca `N8N_API_URL` dan `N8N_API_KEY` dari `.env` panel:

| Skrip | Gunanya |
|---|---|
| `n8n-credential.mjs` | membuat credential Supabase lewat API, alih-alih lewat UI |
| `n8n-tambal.mjs` | mengganti credential di semua node Postgres |
| `n8n-tandai.mjs` | menambahkan akhiran `(panel adiva)` ke nama tiap workflow |

## Urutan impor ke n8n VPS yang sudah ada

Workflow lama **tidak disentuh dan tetap aktif** selama peralihan.

1. Impor kedelapan berkas dari `2026-10-24-supabase/` lewat **Workflows → Import from File**. Semuanya masuk dalam keadaan nonaktif.
2. Buat credential Postgres Supabase di n8n VPS (centang "Ignore SSL Issues"), catat ID-nya dari URL.
3. `node n8n-tambal.mjs /opt/panel-adiva <ID-credential>` — mengarahkan 82 node ke credential itu. Skrip hanya menyentuh node bertipe postgres, jadi workflow lama tidak ikut berubah.
4. **Satu per satu, jangan sekaligus**: matikan workflow lama, baru nyalakan pasangan barunya. Jangan pernah dua-duanya aktif — webhook WAHA yang sama akan membuat customer **dibalas dua kali**, dan dua "Batas Bayar Order" akan membatalkan order yang sama.
5. Setelah yang baru terbukti, workflow lama baru dihapus.

Sebelum menghapus yang lama, pastikan keduanya memakai **database yang sama**. Workflow baru sekarang menunjuk Supabase; kalau yang lama memakai database lain, menghapusnya berarti riwayat customer dan order terputus di titik itu.
