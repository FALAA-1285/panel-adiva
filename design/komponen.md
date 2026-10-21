
# Komponen bersama — spesifikasi persis

Semua angka di bawah diambil dari generator desain (`design/source/*.dc.html`). Kalau sebuah halaman di source memakai angka berbeda dari daftar ini, **ikuti source halaman itu** (beberapa halaman lama memakai input 40px radius 9px dan toggle 44×26px).

Letakkan komponen di `resources/js/components/ui/` (satu file per komponen). Semua warna lewat token di `tokens.css`.

## Font

Muat di `resources/views/app.blade.php`, sama dengan desain:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
```

## Kerangka halaman (`AppLayout`)

- Root: lebar penuh, tinggi = tinggi viewport, `display: flex`, latar `surface`, font Geist, warna `ink`, `overflow: hidden`.
- Kiri: `Sidebar` (248px). Kanan: `main` flex kolom → `Topbar` → `PageHeader` → isi.
- Isi halaman: padding `20px 32px 32px 32px`, kolom dengan `gap: 20px`.

## Sidebar (248px)

- `nav`: lebar 248, tinggi penuh, latar `#F9FAFB`, border kanan `#E5E7EB`, padding `16px 12px 12px 12px`, kolom `gap: 18px`, warna `#374151`.
- Brand: link ke Ringkasan, padding `2px 8px 6px 8px`, gap 10. Kotak 30×30 radius 7 latar `#1D4ED8`, teks "BB" 12px 700 putih, letter-spacing -0.02em. Nama "Bang Bana" 14px 600; sub "Panel admin Adiva" 12px `#6B7280`.
- Grup: judul 12px 500 `#6B7280`, padding `0 10px 6px 10px`; item bertumpuk `gap: 1px`.
- Item: tinggi 34, padding `0 10px`, radius 7, gap 10, 13.5px, ikon 16px stroke 1.8.
  - Aktif: latar `#EAF1FF`, warna `#1D4ED8`, 600.
  - Tidak aktif: transparan, `#374151`, 500.
- Hitungan: kuat = 11.5px 600 putih di `#2563EB`, radius 999, padding `1px 7px`; biasa = 12px 500 `#6B7280`.
- Urutan menu: Operasional (Ringkasan, Percakapan 6 kuat, Pesanan 4, Ambil alih chat 3) · Pelanggan (Pipeline, Pelanggan, Broadcast) · Adiva (Katalog & pengetahuan, Prompt Adiva, Otomasi, Pengaturan) · Sistem (Monitoring 2, Tim & akses, Log aktivitas).
- Bawah (setelah spacer): blok status dengan border atas, padding `12px 10px`: titik 7px `#16A34A` + "Adiva sedang aktif" 13px 600; baris 12px/1.45 `#6B7280`. Lalu baris user: border atas, padding `10px 8px 0 8px`, avatar 30 latar `#E5E7EB`, nama 13px 600, peran 12px `#6B7280`, tombol keluar 30×30.

## Topbar (tinggi 64)

- Padding `0 24px 0 20px`, gap 12, border bawah `#E5E7EB`.
- Tombol ciutkan 34×34 radius 8, ikon 18, warna `#6B7280`.
- Cari: 380×36, radius 8, border `#E5E7EB`, latar `#F9FAFB`, padding `0 6px 0 11px`, ikon 16 stroke 2, input 13.5px, `kbd` "⌘K" mono 11px border `#E5E7EB` radius 5 padding `1px 6px` latar putih.
- Kanan: tanggal 12.5px `#6B7280` · pemisah 1×20 `#E5E7EB` · lonceng (link ke Notifikasi) 34 tinggi, ikon 18, badge 11.5px 600 putih di `#2563EB` radius 999 padding `1px 7px`.

## PageHeader

- Padding `24px 32px 0 32px`; baris flex `align-items: flex-end`, gap 12.
- Breadcrumb (opsional): 12.5px, link `#6B7280` 500, pemisah "/" `#D1D5DB`, gap 6.
- `h1` 22px 600, letter-spacing -0.015em, line-height 1.25. Sub-judul 13.5px `#6B7280` line-height 1.45. Judul & sub bertumpuk gap 4.
- Aksi di kanan, gap 8.
- Tab (opsional): margin-top 14, gap 24, border bawah `#E5E7EB`; tiap tab tinggi 40, 13.5px, margin-bottom -1px; aktif `#111827` 600 + garis bawah 2px `#2563EB`; lainnya `#4B5563` 500. Angka tab 12px 500 (`#1D4ED8` saat aktif, `#6B7280` lainnya), gap 6.

## StatStrip

- Grid kolom sama rata, border `#E5E7EB`, radius 10, latar putih.
- Sel: padding `14px 20px`, kolom gap 4, sel ke-2 dst. border kiri `#E5E7EB`.
- Label 12.5px 500 `#6B7280`; angka 24px 600 letter-spacing -0.015em `tabular-nums`; delta 12.5px 500 warna nada; meta 12.5px `#6B7280` line-height 1.4.

## Panel

- `section`: border `#E5E7EB`, radius 10, latar putih, kolom.
- Header: padding `14px 18px`, border bawah `#E5E7EB`, gap 12; `h2` 14.5px 600; sub 12.5px `#6B7280` line-height 1.4 (gap 2); aksi di kanan.
- Badan: padding default `16px 18px` (tiap halaman bisa berbeda — lihat source).

## DataTable

- Baris judul: tinggi 38, padding `0 18px`, latar `#F9FAFB`, border bawah `#E5E7EB`, label 12px 500 `#6B7280`.
- Baris isi: padding `11px 18px`, border bawah `#F1F2F4`, gap kolom 16, `align-items: center`.
- Terpilih: latar `#F5F8FF` + `box-shadow: inset 2px 0 0 #2563EB`.
- Lebar kolom = `grid-template-columns` persis dari source tiap halaman.

## Teks

- `t1`: 13.5px 500 `#111827` line-height 1.4. `t2`: 12.5px `#6B7280` line-height 1.4.
- `Mono`: Geist Mono 12.5px 500, `white-space: nowrap` (nomor order, kunci config).
- `KeyValue`: label 12px `#6B7280`, nilai 13.5px 500 `#111827`, gap 3.

## Status (titik + teks)

Titik 6×6 bulat + label 12.5px 500, gap 6, `nowrap`. Warna teks/titik per nada: blue `#1D4ED8/#3B82F6`, amber `#B45309/#F59E0B`, green `#15803D/#16A34A`, red `#B91C1C/#DC2626`, gray `#4B5563/#9CA3AF`, violet `#6D28D9/#8B5CF6`. Varian `ink`: teks `#111827`, titik tetap berwarna.

## Tag, Count, Avatar

- Tag: 11.5px 500 `#4B5563`, latar `#F3F4F6`, radius 5, padding `2px 6px`.
- Avatar: bulat, latar `#F3F4F6`, teks `#374151` 600, ukuran huruf = 0,38 × diameter.

## Button

| Ukuran | Tinggi | Padding | Teks |
|---|---|---|---|
| sm | 32 | 0 11px | 12.5px |
| md | 36 | 0 14px | 13px |
| lg | 44 | 0 18px | 14px |

Semua: radius 8, 600, `inline-flex`, gap 6, ikon 15px stroke 2, `nowrap`.

- primary: border & latar `#2563EB`, teks putih.
- secondary: border `#D1D5DB`, latar putih, teks `#111827`.
- danger: border `#E5E7EB`, latar putih, teks `#B91C1C`.
- ghost: border transparan, latar transparan, teks `#374151`.
- link: tanpa border & padding, tinggi auto, teks `#2563EB`.
- disabled: border `#E5E7EB`, latar `#F9FAFB`, teks `#9CA3AF`.
- IconButton: kotak 32×32 (default), radius 8, border `#D1D5DB`, latar putih, ikon 16 `#374151`.

## Form

- Field: label 13px 600 `#111827`; kunci config di sebelahnya Geist Mono 11px `#6B7280` 400 (baseline, gap 8); hint 12px `#6B7280` line-height 1.45; susunan kolom gap 6.
- Input/Select: tinggi 38, radius 8, border `#D1D5DB`, latar putih, 13.5px, padding `0 11px` (select: kanan 8px). Varian mono: Geist Mono 13px.
- Textarea: sama, tinggi auto, padding `9px 11px`, line-height 1.5.
- Checkbox: 16×16, `accent-color: #2563EB`, label 13.5px, sub 12px `#6B7280`.
- Toggle: 36×20 radius 999; nyala `#2563EB`, mati `#D1D5DB`; bulatan 16×16 putih di `top: 2px`, `left: 18px` (nyala) / `2px` (mati).
- Segmented: tiap pilihan tinggi 32, padding `0 12px`, radius 7, 13px; aktif border `#2563EB` latar `#EFF6FF` teks `#1D4ED8` 600; lainnya border `#D1D5DB` latar putih `#111827` 500; gap 6.
- Note: padding `10px 12px`, radius 8, 12.5px line-height 1.5; nada gray/blue/amber/red memakai token `note-*`.

## Ikon

Pakai `lucide-react` dengan `strokeWidth={1.8}` (nav, 16px) atau `2` (tombol & cari). Kalau bentuk ikon di desain tidak sama dengan ikon Lucide, salin path SVG dari source.
