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

## Menerapkan ke n8n VPS

n8n VPS tidak bisa dijangkau dari luar (port 5678 tertutup), jadi langkahnya dijalankan dari sana.

1. Buat credential Postgres di UI n8n VPS: **Credentials → New → Postgres**, isi host/database/user/password Supabase, `SSL = require`, dan **centang "Ignore SSL Issues"** (itu nama kolom `allowUnauthorizedCerts` di UI).
2. Catat ID-nya dari URL saat credential itu dibuka: `/home/credentials/<ID>`.
3. Salin dua skrip ini ke VPS (ada di `storage/app/n8n-cadangan/skrip/`, tidak masuk git):
   - `n8n-credential.mjs` — membuat credential dari `.env` panel, kalau ingin lewat API alih-alih UI
   - `n8n-tambal.mjs` — mengganti credential di semua node Postgres
4. Jalankan penambalannya:

   ```bash
   node n8n-tambal.mjs /opt/panel-adiva <ID-credential>
   ```

   Skrip membaca `N8N_API_URL` dan `N8N_API_KEY` dari `.env` panel di VPS. Tanpa argumen id workflow, ia menambal semuanya; dengan argumen, hanya yang disebut.
5. Aktifkan kelima workflow terjadwal dari UI.

Kalau lebih suka impor berkas: `2026-10-24-supabase/*.json` bisa diimpor lewat **Workflows → Import from File**, tetapi ID credential di dalamnya milik n8n lokal, jadi langkah 4 tetap perlu dijalankan setelahnya.

## Catatan API n8n

- `POST /credentials` bisa membuat, `DELETE /credentials/:id` bisa menghapus, tetapi **tidak ada endpoint untuk mengubah**. Mengganti setelan credential berarti membuat yang baru, menambal ulang node-nya, lalu menghapus yang lama.
- `GET /credentials` membalas 200 dengan daftar kosong — API publik memang tidak mengekspos credential yang sudah ada. ID-nya hanya bisa dibaca dari URL di UI atau dari balasan saat dibuat.
- `PUT /workflows/:id` hanya menerima `name`, `nodes`, `connections`, dan `settings`. Menyertakan `active`, `id`, atau `tags` ditolak sebagai additional properties.

## n8n dan WAHA Bang Bana dipisah dari milik proyek lain

n8n (5678) **dan** WAHA (3001) yang sudah berjalan di VPS keduanya milik proyek lain. Bang Bana mendapat sepasang miliknya sendiri lewat `compose.bot.yml`, di port **5679** dan **3002**, keduanya hanya terikat ke loopback.

Alasan dipisah, bukan digabung:

1. **Satu n8n = satu credential store dan satu encryption key.** Kata sandi Supabase akan duduk bersama credential proyek lain.
2. **API key panel bisa membaca semuanya.** `n8n:sinkron` memanggil `GET /workflows` lalu mencocokkan berdasarkan nama dengan `Str::contains`. Digabung berarti panel Bang Bana membaca seluruh workflow proyek lain, dan nama seumum "Chatbot Sosmed" berisiko salah cocok sehingga halaman Otomasi menampilkan workflow yang bukan miliknya.
3. **Satu versi untuk dua proyek.** Menaikkan versi demi satu proyek ikut menaikkan yang lain.
4. **Cadangan jadi borongan.** Mengekspor Bang Bana saja tidak bisa lagi dipisahkan.

Memasangnya di VPS:

```bash
cd /opt/panel-adiva
cp .env.bot.example .env.bot
sed -i "s/^N8N_ENCRYPTION_KEY=.*/N8N_ENCRYPTION_KEY=$(openssl rand -hex 24)/" .env.bot
sed -i "s/^WAHA_API_KEY=.*/WAHA_API_KEY=$(openssl rand -hex 24)/" .env.bot
nano .env.bot   # isi WAHA_DASHBOARD_PASSWORD
docker compose -f compose.bot.yml --env-file .env.bot up -d
```

`N8N_ENCRYPTION_KEY` **harus dibuat sekali lalu tidak pernah diganti** — kalau hilang, semua credential di dalamnya tidak bisa didekripsi lagi dan harus diisi ulang. Volume `waha` berisi sesi WhatsApp yang sudah dipindai QR; kalau terhapus, nomornya harus dipindai ulang. `.env.bot` sudah masuk `.gitignore`.

Keduanya hanya terikat `127.0.0.1`. UI n8n dibuka lewat Caddy yang sudah ada di VPS (`n8n-bangbana.tokobangbana.com`, lihat `docs/deploy-vps.md`); WAHA tidak diberi subdomain karena dashboard-nya jarang dibuka, jadi cukup SSH tunnel saat perlu:

```bash
ssh -L 3002:127.0.0.1:3002 root@158.220.117.56
```

lalu buka `http://127.0.0.1:3002`. Panel di VPS memanggil keduanya dengan `N8N_API_URL=http://host.docker.internal:5679` dan `WAHA_URL=http://host.docker.internal:3002`.

WAHA Core sudah tidak punya batas sesi sejak split Core/Plus dihapus — dua nomor bot Bang Bana muat di image gratisnya, lengkap dengan pengiriman media.

Setelah instance ini hidup: impor kedelapan workflow dari `2026-10-24-supabase/`, buat credential Postgres Supabase (dengan "Ignore SSL Issues" dicentang), lalu jalankan `n8n-tambal.mjs` seperti di bagian sebelumnya, baru aktifkan kelima workflow terjadwal.
