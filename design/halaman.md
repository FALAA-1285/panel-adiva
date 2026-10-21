# Peta halaman

Ukuran = ukuran board di desain (viewport untuk uji visual). Rute adalah usulan; kalau diubah, ubah juga `design/halaman.json`.

| Kode | Halaman | Rute | Ukuran | Gambar | Sumber | Keadaan |
|---|---|---|---|---|---|---|
| `Login` | 00a · Masuk | `/masuk` | 1440×900 | `screens/Login.png` | `source/Login.dc.html` | awal |
| `Daftar` | 00b · Daftar staf | `/daftar` | 1440×900 | `screens/Daftar.png` | `source/Daftar.dc.html` | awal |
| `Daftar_2` | 00b2 · Daftar — verifikasi WhatsApp | `/daftar` | 1440×900 | `screens/Daftar_2.png` | `source/Daftar.dc.html` | klik “Kirim kode verifikasi lewat WhatsApp” |
| `LupaSandi` | 00c · Lupa kata sandi | `/lupa-sandi` | 1440×900 | `screens/LupaSandi.png` | `source/LupaSandi.dc.html` | awal |
| `LupaSandi_2` | 00c2 · Lupa sandi — kode reset | `/lupa-sandi` | 1440×900 | `screens/LupaSandi_2.png` | `source/LupaSandi.dc.html` | klik “Kirim kode reset” |
| `LupaSandi_3` | 00c3 · Lupa sandi — sandi baru | `/lupa-sandi` | 1440×900 | `screens/LupaSandi_3.png` | `source/LupaSandi.dc.html` | klik “Kirim kode reset” → klik “Lanjut” |
| `Main` | 01 · Ringkasan | `/` | 1440×1648 | `screens/Main.png` | `source/Main.dc.html` | awal |
| `Inbox` | 02 · Percakapan | `/percakapan` | 1440×1080 | `screens/Inbox.png` | `source/Inbox.dc.html` | awal |
| `Orders` | 03 · Pesanan | `/pesanan` | 1440×1124 | `screens/Orders.png` | `source/Orders.dc.html` | awal |
| `OrderDetail` | 03a · Detail pesanan & invoice | `/pesanan/ORD-260928-014` | 1440×1250 | `screens/OrderDetail.png` | `source/OrderDetail.dc.html` | awal |
| `OrderNew` | 03b · Catat order manual | `/pesanan/baru` | 1440×1120 | `screens/OrderNew.png` | `source/OrderNew.dc.html` | awal |
| `Jeda` | 04 · Ambil alih chat | `/jeda` | 1440×1210 | `screens/Jeda.png` | `source/Jeda.dc.html` | awal |
| `Pipeline` | 05 · CRM Pipeline | `/pipeline` | 2112×1080 | `screens/Pipeline.png` | `source/Pipeline.dc.html` | awal |
| `Customer` | 06 · Pelanggan | `/pelanggan` | 1440×1184 | `screens/Customer.png` | `source/Customer.dc.html` | awal |
| `Broadcast` | 07 · Broadcast | `/broadcast` | 1440×1080 | `screens/Broadcast.png` | `source/Broadcast.dc.html` | awal |
| `BroadcastNew` | 08 · Buat kampanye | `/broadcast/baru` | 1440×1500 | `screens/BroadcastNew.png` | `source/BroadcastNew.dc.html` | awal |
| `Katalog` | 09 · Katalog — produk | `/katalog` | 1440×1080 | `screens/Katalog.png` | `source/Katalog.dc.html` | awal |
| `Paket` | 09a · Paket reseller | `/katalog/paket` | 1440×1080 | `screens/Paket.png` | `source/Paket.dc.html` | awal |
| `Gambar` | 09b · Gambar produk | `/katalog/gambar` | 1440×1080 | `screens/Gambar.png` | `source/Gambar.dc.html` | awal |
| `Kebijakan` | 09c · Kebijakan | `/katalog/kebijakan` | 1440×1080 | `screens/Kebijakan.png` | `source/Kebijakan.dc.html` | awal |
| `Karyawan` | 09d · Program karyawan | `/katalog/karyawan` | 1440×1290 | `screens/Karyawan.png` | `source/Karyawan.dc.html` | awal |
| `Prompt` | 10 · Prompt Adiva | `/prompt` | 1440×1060 | `screens/Prompt.png` | `source/Prompt.dc.html` | awal |
| `Otomasi` | 10a · Otomasi | `/otomasi` | 1440×2576 | `screens/Otomasi.png` | `source/Otomasi.dc.html` | awal |
| `Settings` | 11 · Pengaturan | `/pengaturan` | 1440×2112 | `screens/Settings.png` | `source/Settings.dc.html` | awal |
| `Monitoring` | 12 · Monitoring | `/monitoring` | 1440×1164 | `screens/Monitoring.png` | `source/Monitoring.dc.html` | awal |
| `Tim` | 13 · Tim & akses | `/tim` | 1440×1120 | `screens/Tim.png` | `source/Tim.dc.html` | awal |
| `Aktivitas` | 14 · Log aktivitas | `/aktivitas` | 1440×1152 | `screens/Aktivitas.png` | `source/Aktivitas.dc.html` | awal |
| `Notifikasi` | 15 · Notifikasi | `/notifikasi` | 1440×1424 | `screens/Notifikasi.png` | `source/Notifikasi.dc.html` | awal |
| `Sidebar` | Komponen · Sidebar | `/_demo/komponen/sidebar` | 248×1100 | `screens/Sidebar.png` | `source/Sidebar.dc.html` | awal |
| `Topbar` | Komponen · Topbar | `/_demo/komponen/topbar` | 1192×64 | `screens/Topbar.png` | `source/Topbar.dc.html` | awal |

Semua halaman panel memakai komponen `Sidebar` (lebar 248px, prop `active`) dan `Topbar` (tinggi 64px). Bangun dan cocokkan keduanya lebih dulu.
