# Kit desain Panel Adiva untuk Claude Code

Kit ini membuat Claude Code membangun panel **sama persis** dengan desain: ia membaca angka asli desain, bukan menebak dari gambar, lalu membuktikan hasilnya dengan uji visual piksel demi piksel.

## Isi

| Path                                 | Fungsi                                                                        |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| `CLAUDE.md`                          | Aturan proyek yang otomatis dibaca Claude Code di setiap sesi                 |
| `.claude/commands/halaman.md`        | Perintah `/halaman <Kode>` — bangun satu halaman                              |
| `.claude/commands/cocokkan.md`       | Perintah `/cocokkan <Kode>` — perbaiki selisih dengan desain                  |
| `design/screens/`                    | Gambar acuan (28 layar + Sidebar & Topbar), dibuat oleh `npm run foto-desain` |
| `design/source/`                     | HTML/CSS asli tiap layar + `support.js` untuk melihatnya di browser           |
| `design/tokens.css`                  | Token Tailwind 4: warna, ukuran teks, radius                                  |
| `design/komponen.md`                 | Ukuran persis komponen bersama                                                |
| `design/halaman.md` + `halaman.json` | Peta halaman → rute → ukuran                                                  |
| `scripts/foto-desain.mjs`            | Memotret ulang desain dari `design/source` menjadi gambar acuan               |
| `scripts/visual-diff.mjs`            | Uji visual (Playwright + pixelmatch)                                          |
| `docs/urutan-prompt.md`              | Urutan prompt dari nol sampai tersambung ke bot                               |

## Cara pakai

1. Buat folder proyek kosong, salin semua isi kit ini ke dalamnya (termasuk folder `.claude`).
2. Buka terminal di folder itu, jalankan `claude`.
3. Ikuti `docs/urutan-prompt.md` mulai Tahap 0. Di tahap itu Claude memasang Playwright lalu menjalankan `npm run foto-desain` untuk membuat gambar acuan di `design/screens/`.
4. Untuk melihat desain aslinya kapan saja: `npx serve design/source`, buka `http://localhost:3000/Main.dc.html`.

## Uji visual

```bash
php artisan serve            # APP_DEMO=true
npm run dev                  # atau npm run build
npm run visual -- Main       # satu halaman
npm run visual               # semua halaman
```

Hasilnya ada di `visual-diff/`: `<Kode>-app.png` (tampilan aplikasi) dan `<Kode>-diff.png` (piksel merah = beda dari desain). Lolos bila selisih ≤ 0,5%, ukurannya sama, halaman tidak lebih panjang dari desain, dan tidak ada error JavaScript. Skrip ini sudah dicoba terhadap desain itu sendiri dan hasilnya 0,00%.

Gambar acuan sengaja dibuat di komputer Anda sendiri, sama dengan tempat uji visual dijalankan, supaya font dan cara browser menggambar teks identik — tidak ada selisih palsu karena beda sistem operasi.

Agar bisa sama persis, aplikasi saat diuji harus memakai **data contoh yang sama** (`DemoSeeder`) dan **jam yang dibekukan** (Senin, 28 Sep 2026 13:42 WIB), karena angka dan jam ikut tampil di layar.
