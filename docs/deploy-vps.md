# Deploy Panel Adiva ke VPS

Panel berjalan di VPS yang sama dengan n8n dan WAHA. Database tetap di Supabase.

| Yang sudah ada di VPS | Port |
|---|---|
| n8n | 5678 |
| WAHA | 3001 → 3000 di container |
| sandbox n8n | tidak dipublikasikan |
| **panel (baru)** | **8080** |

Tiga container dari satu image:

| Container | Perintah | Tugasnya |
|---|---|---|
| `panel-web` | bawaan image | Melayani panel di `:8080` |
| `panel-worker` | `queue:work` | 9 job di `app/Jobs/`: pesan masuk, balasan keluar, broadcast, order Mengantar |
| `panel-cron` | `schedule:work` | `n8n:sinkron` tiap menit, `jeda:ingatkan` tiap 09.00 |

Tanpa `panel-worker`, pesan customer berhenti di tabel `jobs` dan Adiva tidak pernah menjawab.

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

Migrasi jalan sendiri saat `panel-web` start, karena hanya container itu yang punya `JALANKAN_MIGRASI=1`.

## Yang belum aman, dan cara membereskannya

**Panel berjalan tanpa HTTPS.** Kata sandi staf dan cookie sesi terkirim polos di jaringan; cookie yang tercuri berarti akses supervisor penuh. Dua cara menutupnya:

*Sementara* — batasi port 8080 hanya untuk IP Anda:

```bash
ufw allow from <ip-kantor-anda> to any port 8080 proto tcp
ufw deny 8080/tcp
```

*Benar* — pasang domain dan Caddy. Arahkan A record ke `158.220.117.56`, lalu tambahkan satu service di `compose.yml`:

```yaml
    caddy:
        image: caddy:2-alpine
        restart: unless-stopped
        ports: ['80:80', '443:443']
        command: caddy reverse-proxy --from panel.domain-anda.id --to web:8080
        volumes: ['caddy:/data']
```

Caddy mengurus sertifikat Let's Encrypt sendiri, gratis dan otomatis diperpanjang. Setelah itu ubah `APP_URL` ke `https://…`, `SESSION_SECURE_COOKIE=true`, hapus `ports` dari service `web`, lalu `docker compose up -d`.

Catatan lain: n8n (5678) dan WAHA (3001) juga terbuka ke internet tanpa TLS. Begitu Caddy ada, keduanya sebaiknya ikut di belakangnya dan port langsungnya ditutup.

## Kalau suatu saat pindah ke platform berbayar

`Dockerfile` dan `docker/mulai.sh` tidak terikat VPS ini. Railway, Render, atau Fly.io bisa memakainya apa adanya: satu layanan tanpa perintah khusus untuk web, satu dengan `php artisan queue:work`, satu dengan `php artisan schedule:work`, ditambah volume di `/app/storage/app/public`.
