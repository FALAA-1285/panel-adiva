# Studi Kasus: Panel Adiva

> Panel admin untuk bot WhatsApp "Adiva" milik Bang Bana (produsen makanan ringan, Malang).
> Dikembangkan sebagai aplikasi percontohan kelas produksi untuk menguji arsitektur
> **full-stack SPA tanpa API terpisah**: Laravel 13 + Inertia.js v3 + React 19 + TypeScript,
> dengan panel disajikan dari **Vercel Serverless** dan data di **Supabase PostgreSQL**.

Angka dan nama berkas di dokumen ini dicocokkan langsung dengan kode pada 24 Okt 2026.

---

## 1. PostgreSQL sejak hari pertama, lalu dipindah ke Supabase

Proyek ini **tidak pernah memakai MySQL.** `DB_CONNECTION=pgsql` ada sejak commit pertama, dan skemanya memang dirancang untuk PostgreSQL — bukan hasil tambal setelah migrasi:

- Pencarian nama customer memakai `ILIKE`, bukan `LOWER()` + `LIKE`.
- Seluruh kolom waktu memakai `timestampTz`, karena bot melayani satu zona waktu (`Asia/Jakarta`) tetapi dijalankan di server yang bisa di mana saja.
- Penilaian AI dan payload workflow disimpan sebagai `jsonb`, dikueri dengan operator `->>` dan `count(*) filter (where …)` — mis. perhitungan "chat diteruskan ke admin" di `RingkasanController`.
- Lapisan adaptor `n8n_*` berupa *view* dan *function* SQL, supaya node Postgres di n8n tidak perlu tahu bentuk tabel aslinya.

Yang dikerjakan: memindahkan instance lokal (Laragon) ke **Supabase region `ap-southeast-1` (Singapore)** lewat Session Pooler pada port 5432 dengan `DB_SSLMODE=require`, lalu menjalankan 45 migrasi dan `DemoSeeder`.

**Pelajaran yang didapat:** `phpunit.xml` menimpa `DB_DATABASE` tetapi **tidak** menimpa `DB_HOST`. Begitu `.env` diarahkan ke Supabase, seluruh test suite ikut menyambung ke Singapura — 29 tes memakan 119 detik, dan suite penuh diperkirakan ±40 menit. Setelah host, port, user, dan `DB_SSLMODE` ikut dipaku ke PostgreSQL lokal di `phpunit.xml`, suite penuh kembali **62 detik**. Pengujian yang menyentuh database jarak jauh bukan hanya lambat — ia juga membuat database `panel_adiva_test` ikut terbuat di proyek Supabase.

---

## 2. Laravel monolit di Vercel Serverless

Vercel tidak punya runtime PHP resmi dan filesystem-nya read-only. Empat hal yang harus diselesaikan:

1. **Entrypoint serverless** — `api/index.php` sebagai jembatan, dengan seluruh cache Laravel dialihkan ke `/tmp` lewat `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `VIEW_COMPILED_PATH`, dan `LARAVEL_STORAGE_PATH`.
2. **Runtime PHP** — `vercel-php@0.9.0`. `composer.json` meminta `php: ^8.3`, sehingga runtime di bawah itu gagal mem-parse kode.
3. **Aset Vite** — Vite memanggil `php artisan wayfinder:generate` saat build untuk menghasilkan `resources/js/routes` dan `resources/js/actions`. Build machine Vercel tidak menyediakan PHP CLI untuk itu, jadi aset dibangun lokal dan `!public/build` didaftarkan di `.vercelignore`.
4. **HTTPS di balik proxy** — `trustProxies(at: '*')` di `bootstrap/app.php` dan `URL::forceScheme('https')`, supaya URL aset tidak dibuat sebagai `http://` lalu diblokir browser sebagai mixed content.

**Optimasi latensi:** respon awal ±3 detik karena fungsi berjalan di `iad1` (Washington D.C.) sementara database di Singapura — setiap kueri menyeberangi Pasifik dua kali. Setelah `"regions": ["sin1"]` dan memori dinaikkan ke 1024 MB, `/up` membalas dalam **35 ms**.

Live: [panel-adiva.vercel.app](https://panel-adiva.vercel.app)

### Batas arsitektur yang jujur

Serverless menjalankan panelnya dengan sangat baik, tetapi tiga bagian sistem ini tidak bisa hidup di sana, dan itu sifat platformnya, bukan kekurangan konfigurasi:

| Yang dibutuhkan | Kenapa tidak bisa serverless |
|---|---|
| `queue:work` | 9 job di `app/Jobs/` butuh proses yang tidak pernah berhenti. Fungsi serverless dimatikan setelah tiap request |
| `schedule:work` | `notifikasi:kirim-wa` berjalan tiap 15 detik dan `n8n:sinkron` tiap menit; cron Vercel paket Hobby hanya harian |
| Unggah foto produk | `LARAVEL_STORAGE_PATH=/tmp/storage` bersifat ephemeral — berkas hilang saat instance berganti |

Penyelesaiannya bukan memaksakan serverless, melainkan **memisahkan beban**: panel tetap di Vercel, sementara worker dan scheduler berjalan sebagai container di VPS yang sudah menampung WAHA dan n8n. Keduanya berbagi satu database Supabase, jadi tidak ada sinkronisasi tambahan yang perlu ditulis.

Catatan lisensi: paket Hobby Vercel dibatasi untuk pemakaian non-komersial. Panel ini dibangun sebagai portofolio dan tidak melayani transaksi sungguhan, jadi statusnya sesuai. Kalau suatu saat dipakai menjalankan order Bang Bana, ia memerlukan paket Pro.

---

## 3. Peningkatan UX halaman Ambil Alih Chat (`/jeda`)

### A. Pop-up serah terima catatan

**Sebelumnya:** kolom catatan serah terima berada di kartu statis di kolom kanan, sedangkan tombol yang memakainya ("Lanjutkan bot") ada di tabel kolom kiri. Tidak ada apa pun yang menyatakan keduanya terhubung, dan tidak terlihat catatan itu akan menempel ke customer yang mana.

**Sekarang:** kartu statis dihapus, diganti modal yang muncul saat admin menekan "Lanjutkan bot" pada baris tertentu. Modal menampilkan ringkasan customer (nama, nomor WA, sesi CS, alasan jeda), kolom catatan untuk Adiva dengan batas 500 karakter beserta penghitungnya, tombol batal, dan tombol lanjutkan dengan indikator proses. Pintasan: `Esc` menutup, `Ctrl`/`Cmd`+`Enter` mengirim.

Catatannya tersimpan di profil customer dan riwayat aktivitas, sehingga Adiva tahu apa yang sudah disepakati admin selama mengambil alih.

### B. Durasi jeda bebas, bukan tombol tetap

**Sebelumnya:** lima tombol kaku — `30 menit`, `1 jam`, `2 jam`, `1 hari`, `Tanpa batas`.

**Sekarang:** input angka bebas + dropdown satuan (detik, menit, jam, hari), ditambah checkbox "Tanpa batas waktu" yang menonaktifkan kolom angka saat dicentang, dan ringkasan dinamis di bawahnya. Controller dan FormRequest dihitung ulang hingga presisi detik lewat `now()->addSeconds(...)`, dengan test suite lama tetap hijau.

---

## 4. Jaminan kualitas

| Pemeriksaan | Hasil |
|---|---|
| Pest | **596 tes lolos, 3.690 asersi** (`JedaTest`: 10 tes, 98 asersi) |
| PHPStan (larastan) | 0 error |
| TypeScript | 0 error (`tsc --noEmit`) |
| Laravel Pint | lolos |
| Responsif | 100 pemeriksaan di 1280/1024/768/390 px, tidak ada yang meluber ke samping |

---

## Ringkasan untuk portofolio

| Kategori | Teknologi | Nilai tambah |
|---|---|---|
| Backend | Laravel 13, PHP 8.3+, Pest 5 | FormRequest berlapis, driver pattern tiga implementasi (`N8n*`/`Tiruan*`/`Lokal*`), 596 tes |
| Frontend | Inertia.js v3, React 19, TypeScript, Tailwind 4 | SPA tanpa REST API terpisah, modal interaktif, skala teks 7 langkah hasil audit kepadatan |
| Database | PostgreSQL 18, Supabase | `ILIKE`, `timestampTz`, `jsonb`, view & function sebagai lapisan adaptor |
| DevOps | Vercel Serverless, Docker, Git CI/CD | Monolit PHP di serverless, co-location Singapore (3 dtk → 35 ms), pemisahan beban worker ke container |
| Integrasi | n8n, WAHA, Mengantar, Gemini | Migrasi 224 → 57 node dengan pengujian kesetaraan berdampingan terhadap JavaScript aslinya |
