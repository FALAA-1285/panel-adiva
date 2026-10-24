# Deploy Panel Adiva ke VPS

Panel berjalan di VPS yang sama dengan n8n dan WAHA. Database tetap di Supabase.

| Yang sudah ada di VPS | Port |
|---|---|
| n8n **(proyek lain)** | 5678 |
| WAHA **(proyek lain)** | 3001 → 3000 di container |
| n8n Bang Bana (`compose.bot.yml`) | 127.0.0.1:5679 |
| WAHA Bang Bana (`compose.bot.yml`) | 127.0.0.1:3002 |
| sandbox n8n | tidak dipublikasikan |
| **panel (baru)** | **8080** |

Empat container dari satu image:

| Container | Perintah | Tugasnya |
|---|---|---|
| `panel-migrasi` | `migrate --force` | Sekali jalan lalu berhenti; yang lain menunggu sampai ia selesai |
| `panel-web` | bawaan image | Melayani panel di `:8080` — **tidak perlu kalau panel disajikan Vercel** |
| `panel-worker` | `queue:work` | 9 job di `app/Jobs/`: pesan masuk, balasan keluar, broadcast, order Mengantar |
| `panel-cron` | `schedule:work` | `broadcast:lanjutkan` tiap 5 menit, `notifikasi:kirim-wa` tiap 15 detik, `n8n:sinkron` tiap menit, `jeda:ingatkan` 09.00 |

Tanpa `panel-worker`, pesan customer berhenti di tabel `jobs` dan Adiva tidak pernah menjawab. Tanpa `panel-cron`, broadcast berhenti di tengah dan notifikasi WhatsApp tidak terkirim.

**Kalau panel sudah disajikan Vercel**, VPS cukup menjalankan yang tidak bisa serverless:

```bash
docker compose up -d --build worker cron
```

## 1. Ambil kodenya ke VPS

Repo private, jadi pakai deploy key supaya tidak ada token yang tertinggal di riwayat shell.

Di VPS:

```bash
ssh-keygen -t ed25519 -C "vps-panel-adiva" -f ~/.ssh/panel_adiva -N ""
cat ~/.ssh/panel_adiva.pub
```

Salin isinya ke **github.com/FALAA-1285/panel-adiva → Settings → Deploy keys → Add deploy key** (cukup read-only, jangan centang write access). Lalu:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-panel
    HostName github.com
    User git
    IdentityFile ~/.ssh/panel_adiva
EOF

mkdir -p /opt && cd /opt
git clone git@github-panel:FALAA-1285/panel-adiva.git
cd panel-adiva
```

## 2. Isi variabelnya

```bash
cp .env.produksi.example .env.produksi
nano .env.produksi
```

Yang wajib diisi: `APP_KEY` (salin dari `.env` di laptop), `DB_USERNAME` dan `DB_PASSWORD` dari Supabase. Sisanya sudah terisi benar untuk VPS ini.

Tiga nilai yang paling mudah salah:

- `APP_DEMO=false` — kalau `true`, jam aplikasi beku di 28 Sep 2026 dan semua driver bot jadi tiruan.
- `SESSION_SECURE_COOKIE=false` — **harus** false selama panel diakses lewat `http://`. Kalau `true`, browser menolak mengirim cookie dan login selalu gagal tanpa pesan yang jelas.
- `APP_LOCALE=id` — tanpa ini tanggal muncul "Monday, 28 Sep", bukan "Senin, 28 Sep".

`.env.produksi` sudah masuk `.gitignore` dan `.dockerignore`, jadi isinya tidak ikut ter-commit maupun masuk ke dalam image.

## 3. Jalankan

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f web
```

Build pertama ±3–5 menit (Composer, npm, dan aset Vite). Setelahnya Docker memakai cache dan jauh lebih cepat.

Buka `http://158.220.117.56:8080/masuk`.

## 4. Pastikan hidup

```bash
# web sehat
docker compose ps                      # web harus "healthy"

# cron bekerja: tiap menit ada n8n:sinkron
docker compose logs --tail=20 cron

# worker bekerja: antrean tidak menumpuk
docker compose exec web php artisan queue:monitor default
```

Di panel, halaman **Monitoring** juga menampilkan keadaan antrean dan workflow.

## 5. Sambungkan bot

Selama `BOT_DRIVER=tiruan`, panel tidak mengirim apa pun — aman untuk mencoba. Begitu siap:

1. Isi `WAHA_API_KEY` dan `WAHA_WEBHOOK_SECRET` di `.env.produksi`.
2. Ubah `BOT_DRIVER` ke `lokal` (panel mengerjakan sendiri) atau `langsung` (lewat n8n).
3. Di WAHA, arahkan webhook ke **`http://158.220.117.56:8080/api/webhook/waha`**. Pakai IP, bukan `host.docker.internal` — nilai itu hanya berlaku dari dalam container panel, bukan dari WAHA.
4. `docker compose up -d` (tanpa `--build`, karena hanya env yang berubah).

Setelah WAHA tersambung, nyalakan lagi verifikasi perangkat baru dari **Tim & akses** — sekarang `PANEL_KODE_PERANGKAT_BARU` dimatikan supaya akun supervisor bisa masuk tanpa OTP.

## 6. Memperbarui nanti

```bash
cd /opt/panel-adiva
git pull
docker compose up -d --build
```

Migrasi jalan sendiri lewat container `panel-migrasi`, yang sekali jalan lalu berhenti. Container lain menunggu sampai ia selesai, jadi migrasi tidak pernah tereksekusi dua kali bersamaan.

## Caddy: panel di balik HTTPS

VPS sudah menjalankan Caddy di port 80/443 dengan `/etc/caddy/Caddyfile`, yang saat ini memetakan `n8n.tktbanafood.com` dan `n8n.tokobangbana.com` ke `localhost:5678`. Panel ikut di belakangnya, jadi tidak perlu container Caddy tambahan dan tidak perlu SSH tunnel.

Tambahkan blok ini ke `/etc/caddy/Caddyfile`:

```
panel.tokobangbana.com {
        encode zstd gzip
        reverse_proxy localhost:8080
}

n8n-bangbana.tokobangbana.com {
        encode zstd gzip
        reverse_proxy localhost:5679
}
```

Lalu arahkan A record kedua subdomain itu ke `158.220.117.56` dan muat ulang:

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

Caddy mengurus sertifikat Let's Encrypt sendiri — gratis, otomatis diperpanjang, tanpa konfigurasi tambahan.

Container panel dan n8n Bang Bana sengaja hanya terikat `127.0.0.1`, jadi port 8080 dan 5679 tidak pernah terbuka ke internet; hanya Caddy yang menghadap keluar. `bootstrap/app.php` sudah memuat `trustProxies(at: '*')` dan `AppServiceProvider` memanggil `URL::forceScheme('https')` saat `APP_URL` berawalan `https://`, jadi tidak ada perubahan kode yang diperlukan.

Setelah HTTPS hidup, pastikan `.env.produksi` memakai `APP_URL=https://panel.tokobangbana.com` dan `SESSION_SECURE_COOKIE=true`. Kalau nilai kedua itu `true` sementara panel masih diakses lewat `http://` polos, browser menolak mengirim cookie dan **login selalu gagal tanpa pesan yang masuk akal**.

### Dua hal yang perlu Anda periksa

1. **`n8n.tokobangbana.com` saat ini menunjuk n8n proyek lain** (`localhost:5678`). Subdomain itu memakai domain Bang Bana tapi melayani proyek lain. Kalau itu tidak disengaja, pemetaannya perlu ditukar.
2. **WAHA (3001) milik proyek lain masih terbuka langsung ke internet tanpa TLS**, di luar Caddy. Sebaiknya ikut dipindah ke belakang Caddy lalu portnya ditutup:
   ```bash
   ufw deny 3001/tcp
   ufw deny 5678/tcp
   ```

## Kalau suatu saat pindah ke platform berbayar

`Dockerfile` dan `docker/mulai.sh` tidak terikat VPS ini. Railway, Render, atau Fly.io bisa memakainya apa adanya: satu layanan tanpa perintah khusus untuk web, satu dengan `php artisan queue:work`, satu dengan `php artisan schedule:work`, ditambah volume di `/app/storage/app/public`.

## Memori VPS dan swap

`free -h` pada 24 Okt 2026: **7,8 GB total, 3,3 GB terpakai, 4,5 GB tersedia, swap 0**.

Yang akan ditambahkan Bang Bana, dengan batas yang dipasang di compose:

| Container | `mem_limit` | Perkiraan nyata |
|---|---|---|
| `bangbana-waha` | 2 GB | ±700 MB per sesi WhatsApp; engine WEBJS menjalankan satu Chromium per nomor |
| `bangbana-n8n` | 768 MB | ±400 MB |
| `panel-worker` | 512 MB | ±150 MB |
| `panel-cron` | 512 MB | ±100 MB |

Batas itu adalah langit-langit, bukan jatah yang dikunci, jadi pemakaian sebenarnya jauh di bawahnya. Tetap muat di 4,5 GB yang tersedia.

**Pasang swap 2 GB sebelum menjalankan apa pun.** Tanpa swap, lonjakan memori sesaat langsung memicu OOM killer kernel, dan kernel memilih korbannya sendiri berdasarkan skor — bisa jatuh ke n8n proyek lain, bukan ke container yang menyebabkan lonjakan. Chromium di WAHA membuat lonjakan itu bukan hipotetis.

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl -w vm.swappiness=10
echo 'vm.swappiness=10' > /etc/sysctl.d/99-swap.conf
free -h
```

`swappiness=10` membuat kernel memakai swap hanya saat benar-benar perlu, bukan memindahkan memori aktif ke disk tanpa alasan.

Kalau suatu saat WAHA terasa terlalu berat, ada engine yang jauh lebih ringan (`GOWS`/`NOWEB`, tanpa Chromium sama sekali). Jangan ditukar begitu saja: `PesanMasuk`, `WahaChat`, dan `LampiranMasuk` di panel diport berdasarkan bentuk payload WEBJS, dan engine lain mengirim bentuk yang berbeda. Perlu diuji dulu di nomor percobaan.
