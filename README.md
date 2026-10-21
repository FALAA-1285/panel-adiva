# Panel admin Adiva (Bang Bana)

Panel web untuk memantau dan mengendalikan bot WhatsApp "Adiva". Bot-nya berjalan di n8n + WAHA; panel ini membaca dan menulis database yang sama, dan sejak langkah 1–3 ia juga menjalankan sendiri perintah order, jalur pesan masuk, balasan, CRM, dan pengingat.

## Stack

- Laravel 13 (PHP 8.4), Inertia 3, React 19 + TypeScript, Tailwind CSS 4
- PostgreSQL 18, Redis + Horizon untuk antrean
- Radix UI primitives tanpa gaya bawaan, `lucide-react`, TanStack Table, dnd-kit, grafik SVG sendiri

## Menjalankan

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run dev          # atau npm run build
php artisan serve
```

Pengaturan yang perlu diisi untuk menjalankan bot sungguhan: `BOT_DRIVER`, `WAHA_URL`, `WAHA_KEY`, `N8N_WEBHOOK_URL`, `N8N_WEBHOOK_SECRET`. `MENGANTAR_API_KEY` disimpan di tabel `config_toko` dan bisa diubah dari halaman Pengaturan. `GEMINI_API_KEY` tidak diperlukan panel — kunci itu tetap hanya di credential n8n.

## Mode demo

`APP_DEMO=true` mengisi data contoh lewat `DemoSeeder`, membekukan jam aplikasi ke Senin, 28 Sep 2026 13:42 WIB, dan memaksa semua driver bot memakai tiruan. Database demo terpisah:

```bash
APP_DEMO=true DB_DATABASE=panel_adiva_demo php artisan migrate:fresh --seed
APP_DEMO=true DB_DATABASE=panel_adiva_demo php artisan serve --port=8010
```

Rute `/_demo/login` (masuk sebagai Falah, Supervisor) dan `/_demo/komponen/{sidebar|topbar}` hanya aktif saat `APP_DEMO=true`.

## Uji

```bash
php artisan test            # Pest
npm run types               # tsc --noEmit
npm run responsif           # cek tidak ada yang meluber ke samping di 1280/1024/768/390
./vendor/bin/pint --test
./vendor/bin/phpstan
```

`npm run responsif` membaca daftar halaman dari `scripts/halaman.json` dan menulis gambar ke `visual-diff/responsif/`.

## Dokumen

| Path | Isi |
|---|---|
| `CLAUDE.md` | Aturan proyek untuk Claude Code |
| `docs/kontrak-n8n.md` | Kontrak antara panel dan workflow n8n |
| `docs/audit-kepadatan.md` | Hasil ukur tiap halaman sebelum desain dirombak |
| `docs/urutan-prompt.md` | Catatan urutan pembangunan panel |
