# Panel admin Adiva (Bang Bana)

Panel web untuk memantau dan mengendalikan bot WhatsApp "Adiva". Bot-nya berjalan di n8n + WAHA; panel ini membaca dan menulis database yang sama, dan sejak langkah 1–3 ia juga menjalankan sendiri perintah order, jalur pesan masuk, balasan, CRM, dan pengingat.

## Stack

- Laravel 13 (PHP 8.4), Inertia 3, React 19 + TypeScript, Tailwind CSS 4
- PostgreSQL (Supabase di produksi); antrean, cache, dan sesi memakai driver `database`
- Komponen UI buatan sendiri di `resources/js/components/ui/` (tanpa pustaka komponen), ikon SVG sendiri, TanStack Table, grafik SVG sendiri
- Build aset: `vite-plus` (`npm run build`)

## Menjalankan

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run dev          # atau npm run build
php artisan serve
php artisan queue:work   # pesan masuk, balasan, broadcast
php artisan schedule:work
```

Pengaturan yang perlu diisi untuk menjalankan bot sungguhan: `BOT_DRIVER`, `WAHA_URL`, `WAHA_KEY`, `WAHA_WEBHOOK_SECRET`, `N8N_WEBHOOK_URL`, `N8N_WEBHOOK_SECRET`. Rekening, ongkir, dan packing disimpan di tabel `config_toko` dan diubah dari halaman Pengaturan. `GEMINI_API_KEY` tidak diperlukan panel — kunci itu tetap hanya di credential n8n.

> `.env` lokal bisa menunjuk ke Supabase. Perintah yang menulis database (`migrate`, `db:seed`) sebaiknya selalu diberi target yang jelas.

## Mode demo

`APP_DEMO=true` mengisi data contoh lewat `DemoSeeder`, membekukan jam aplikasi ke Senin, 28 Sep 2026 13:42 WIB, dan memaksa semua driver bot memakai tiruan. Database demo terpisah:

```bash
APP_DEMO=true DB_DATABASE=panel_adiva_demo php artisan migrate:fresh --seed
APP_DEMO=true DB_DATABASE=panel_adiva_demo php artisan serve --port=8010
```

Rute `/_demo/komponen/{sidebar|topbar}` aktif saat `APP_DEMO=true`. Rute `/_demo/login` (masuk sebagai Falah, Supervisor, **tanpa kata sandi**) hanya terbuka di luar `APP_ENV=production`; di produksi ia 404 kecuali `APP_DEMO_LOGIN=true` dipasang dengan sengaja.

## Deploy ke Vercel

Vercel **tidak** membangun aset sendiri (`vercel.json`: `buildCommand` hanya `echo`), karena build Vite memanggil `php artisan wayfinder:generate` dan mesin build Vercel tidak punya PHP. `public/build` juga tidak masuk git. Akibatnya **push ke GitHub saja tidak mengubah situs**. Deploy dari mesin lokal:

```bash
php artisan migrate --force   # bila ada migrasi baru; .env harus menunjuk ke Supabase
npm run build
vercel --prod                 # .vercelignore mengikutkan public/build
```

Env di `vercel.json`: `CACHE_STORE=database` dan `SESSION_DRIVER=database`. Jangan diganti `array`/`cookie`: pembatas percobaan login dan OTP, kunci perintah order, dan "keluarkan sesi" bergantung pada penyimpanan bersama.

Worker antrean dan scheduler tidak bisa berjalan di Vercel; jalankan di VPS (`docs/deploy-vps.md`).

## Uji

```bash
php artisan test            # Pest
npm run types               # tsc --noEmit
npm run responsif           # cek tidak ada yang meluber ke samping di 1280/1024/768/390
./vendor/bin/pint --test
./vendor/bin/phpstan
```

`npm run responsif` membaca daftar halaman dari `scripts/halaman.json` dan menulis gambar ke `visual-diff/responsif/`. Set `APP_URL` ke server demo (mis. `http://127.0.0.1:8010`).

## Dokumen

| Path                        | Isi                                                      |
| --------------------------- | -------------------------------------------------------- |
| `CLAUDE.md`                 | Aturan proyek untuk Claude Code (lokal, tidak ikut repo) |
| `docs/kontrak-n8n.md`       | Kontrak antara panel dan workflow n8n                    |
| `docs/deploy-vps.md`        | Worker, scheduler, WAHA, dan n8n di VPS                  |
| `docs/studi-kasus.md`       | Ringkasan arsitektur untuk portofolio                    |
| `docs/PRD-Panel-Adiva.docx` | Dokumen kebutuhan produk (PRD)                           |
| `docs/audit-kepadatan.md`   | Hasil ukur tiap halaman sebelum desain dirombak          |
| `docs/urutan-prompt.md`     | Catatan urutan pembangunan panel                         |
