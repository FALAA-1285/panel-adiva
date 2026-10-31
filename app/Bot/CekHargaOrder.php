<?php

namespace App\Bot;

use App\Models\BarangToko;
use App\Models\Produk;
use App\Support\Rupiah;

/**
 * Every line of a new order checked against the official price list (n8n's "Cek Harga ke
 * Daftar"). It never blocks the order: a wrong or unknown price only adds a warning to
 * the supervisor's notice and a "CEK HARGA" note on the order, so it is caught before ACC.
 */
final class CekHargaOrder
{
    private const KATA_ABAI = ['kg', 'g', 'gram', 'kilogram', 'pack', 'isi', 'pcs', 'kemasan', 'botol', 'besar'];

    /**
     * @param  array<string, mixed>  $rb  what RapikanBalasan made
     * @param  array<string, mixed>  $putusan  what PutuskanOrder decided
     * @param  iterable<Produk>|null  $produk  the price list (all products when null)
     * @return array<string, mixed> $putusan with catatan_notif and catatan_log added to
     */
    public static function periksa(array $rb, array $putusan, ?iterable $produk = null): array
    {
        // an employee order uses the employee prices, checked by the PJ
        if (preg_match('/POTONG GAJI/i', (string) ($rb['metode_bayar'] ?? ''))) {
            return $putusan;
        }

        $items = $rb['order_items_detail'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        $daftar = [];
        foreach ($produk ?? Produk::query()->dijual()->get() as $p) {
            $d = [
                'p' => $p,
                'kata' => self::kataNama($p->jenis_produk),
                'uk' => self::ukuran($p->jenis_produk) ?: self::ukuran($p->gramasi),
                'isi' => self::isiPack($p->jenis_produk) ?: self::isiPack($p->kategori_kemasan),
                't' => self::tingkat($p),
            ];
            if ($d['t'] !== [] && $d['kata'] !== []) {
                $daftar[] = $d;
            }
        }

        if (! is_array($items) || $items === [] || $daftar === []) {
            return $putusan;
        }

        $baris = array_map(function ($it) use ($daftar) {
            $nama = trim((string) ($it['nama'] ?? $it['name'] ?? ''));
            $calon = self::cocokkan($nama, $daftar);

            return [
                'nama' => $nama,
                'qty' => (float) ($it['qty'] ?? 0),
                'harga' => self::angka($it['harga_satuan'] ?? $it['harga'] ?? null),
                'calon' => $calon,
                'd' => $calon[0] ?? null,
            ];
        }, array_values(array_filter($items, 'is_array')));

        // the quantity per price group: a LOSS pack counts per product, other packs may be
        // mixed across products with the same pack and the same price table
        $totalPerKelompok = [];
        foreach ($baris as $b) {
            if ($b['d'] !== null) {
                $k = self::kunciTier($b['d']);
                $totalPerKelompok[$k] = ($totalPerKelompok[$k] ?? 0) + $b['qty'];
            }
        }

        $salah = $takDikenal = $barangToko = [];
        foreach ($baris as $b) {
            if ($b['nama'] === '' || $b['harga'] === 0) {
                continue;
            }
            if ($b['d'] === null) {
                // goods from the shop's shelf (Accurate) are sold at the shop only
                if (self::barangToko($b['nama'])) {
                    $barangToko[] = '• '.$b['nama'].' — '.Rupiah::teks($b['harga']);
                } else {
                    $takDikenal[] = '• '.$b['nama'].' — '.Rupiah::teks($b['harga']);
                }

                continue;
            }
            // several products fit an incomplete name: the price of any of them will do
            if (count($b['calon']) > 1 && self::cocokSalahSatu($b['calon'], $b['harga'], $b['qty'])) {
                continue;
            }

            $gabungan = $totalPerKelompok[self::kunciTier($b['d'])] ?? $b['qty'];
            $hSendiri = self::hargaTier($b['d'], $b['qty']);
            $hGabung = self::hargaTier($b['d'], $gabungan);
            if ($b['harga'] === $hSendiri || $b['harga'] === $hGabung) {
                continue;
            }

            $qty = self::qty($b['qty']);
            $teks = implode(', ', array_map(fn ($x) => match (true) {
                $x['max'] === null => '≥'.$x['min'],
                $x['min'] === $x['max'] => (string) $x['min'],
                default => $x['min'].'-'.$x['max'],
            }.': '.Rupiah::teks($x['harga']), $b['d']['t']));
            $nama = $b['d']['p']->jenis_produk;

            $salah[] = in_array($b['harga'], array_column($b['d']['t'], 'harga'), true)
                ? '• '.$b['nama'].' ('.$qty.' pcs, total untuk tingkat harga '.self::qty($gabungan).') ditulis '.Rupiah::teks($b['harga']).', seharusnya '.Rupiah::teks($hGabung ?: $hSendiri).' sesuai jumlah ('.$teks.')'
                : '• '.$b['nama'].' ('.$qty.' pcs) ditulis '.Rupiah::teks($b['harga']).' — TIDAK ADA di daftar harga '.$nama.' ('.$teks.')';
        }

        if ($salah === [] && $takDikenal === [] && $barangToko === []) {
            return $putusan;
        }

        $bagian = array_filter([
            $barangToko !== [] ? "⛔ *BARANG TOKO* — hanya dijual langsung di Toko Sawojajar, tidak bisa dipesan lewat WhatsApp:\n".implode("\n", $barangToko) : null,
            $salah !== [] ? "⚠️ *CEK HARGA SEBELUM ACC* — harga di pesanan ini tidak cocok dengan daftar harga:\n".implode("\n", $salah) : null,
            $takDikenal !== [] ? "ℹ️ Produk berikut tidak ketemu di daftar harga, mohon cek manual:\n".implode("\n", $takDikenal) : null,
            'Kalau harganya memang salah: minta customer konfirmasi ulang atau *TOLAK* lalu buat ulang. Kalau memang harga khusus yang disepakati, abaikan pesan ini.',
        ]);

        return [
            ...$putusan,
            'catatan_notif' => implode("\n\n", $bagian)."\n\n".($putusan['catatan_notif'] ?? ''),
            'catatan_log' => collect([
                $putusan['catatan_log'] ?? '',
                $barangToko !== [] ? 'CEK HARGA: '.count($barangToko).' barang toko (tidak dijual lewat WA)' : '',
                $salah !== [] ? 'CEK HARGA: '.count($salah).' item tidak cocok daftar harga' : '',
                $takDikenal !== [] ? 'CEK HARGA: '.count($takDikenal).' item tidak dikenal' : '',
            ])->filter()->implode(' | '),
        ];
    }

    /**
     * Whether an order line names a good on the shop's shelf: every word of the line
     * (sizes and packs aside) is in the good's name.
     */
    private static function barangToko(string $nama): bool
    {
        $kata = array_values(array_filter(
            self::kataNama($nama),
            fn (string $k) => mb_strlen($k) >= 3 && ! in_array($k, self::KATA_ABAI, true),
        ));

        if ($kata === []) {
            return false;
        }

        $q = BarangToko::query();
        foreach ($kata as $k) {
            $q->where('nama', 'ilike', '%'.addcslashes($k, '%_\\').'%');
        }

        return $q->exists();
    }

    /**
     * The price tiers: harga_bertingkat when its prices are filled in, else read from
     * informasi_harga ("1 - 14 Pcs: Rp 35.000", "≥ 50 Pcs: Rp 25.000").
     *
     * @return list<array{min: int, max: int|null, harga: int}>
     */
    private static function tingkat(Produk $p): array
    {
        $tier = array_values(array_filter($p->harga_bertingkat['tier'] ?? [], fn ($t) => ($t['harga'] ?? null) !== null));
        if ($tier !== []) {
            return array_map(fn ($t) => ['min' => (int) $t['min'], 'max' => $t['max'] === null ? null : (int) $t['max'], 'harga' => (int) $t['harga']], $tier);
        }

        $out = [];
        foreach (preg_split('/\n|\//', (string) $p->informasi_harga) ?: [] as $l) {
            if (! preg_match('/rp\s*([\d.]+)/i', $l, $h)) {
                continue;
            }
            $harga = self::angka($h[1]);
            $out[] = match (true) {
                (bool) preg_match('/(\d+)\s*-\s*(\d+)\s*[a-z]*\s*:/i', $l, $m) => ['min' => (int) $m[1], 'max' => (int) $m[2], 'harga' => $harga],
                (bool) preg_match('/(?:≥|>=|>)\s*(\d+)/u', $l, $m) => ['min' => (int) $m[1], 'max' => null, 'harga' => $harga],
                (bool) preg_match('/(\d+)\s*[a-z]+\s*:/i', $l, $m) => ['min' => (int) $m[1], 'max' => (int) $m[1], 'harga' => $harga],
                default => ['min' => 1, 'max' => null, 'harga' => $harga],
            };
        }

        return $out;
    }

    /**
     * The products whose name fits best (at least 60% of its words, same size and pack).
     *
     * @param  list<array<string, mixed>>  $daftar
     * @return list<array<string, mixed>>|null
     */
    private static function cocokkan(string $nama, array $daftar): ?array
    {
        $n = self::norm($nama);
        $uk = self::ukuran($nama);
        $isi = self::isiPack($nama);
        $calon = [];
        $skorBest = 0.0;

        foreach ($daftar as $d) {
            if (($uk && $d['uk'] && $uk !== $d['uk']) || ($isi && $d['isi'] !== $isi) || ($uk && ! $d['uk'])) {
                continue;
            }
            $kena = count(array_filter($d['kata'], fn ($w) => str_contains($n, " {$w} ")));
            if ($kena / count($d['kata']) < 0.6) {
                continue;
            }
            $skor = $kena / count($d['kata']) + $kena * 0.01 + ($uk && $d['uk'] === $uk ? 0.05 : 0);
            if ($skor > $skorBest + 1e-9) {
                $skorBest = $skor;
                $calon = [$d];
            } elseif (abs($skor - $skorBest) < 1e-9) {
                $calon[] = $d;
            }
        }

        return $calon !== [] ? $calon : null;
    }

    /**
     * Whether the price is a tier price of any of the products, for that quantity or more.
     *
     * @param  list<array<string, mixed>>  $calon
     */
    private static function cocokSalahSatu(array $calon, int $harga, float $qty): bool
    {
        foreach ($calon as $d) {
            foreach ($d['t'] as $x) {
                if ($x['harga'] === $harga && $qty >= $x['min']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private static function kunciTier(array $d): string
    {
        $kemasan = strtoupper((string) $d['p']->kategori_kemasan);
        if (str_contains($kemasan, 'LOSS') || preg_match('/\bLOSS\b/i', $d['p']->jenis_produk)) {
            return 'P:'.$d['p']->jenis_produk;
        }

        return 'K:'.$kemasan.'|'.implode(',', array_map(fn ($x) => $x['min'].'-'.($x['max'] ?? 'INF').':'.$x['harga'], $d['t']));
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private static function hargaTier(array $d, float $qty): int
    {
        foreach ($d['t'] as $x) {
            if ($qty >= $x['min'] && ($x['max'] === null || $qty <= $x['max'])) {
                return $x['harga'];
            }
        }

        return 0;
    }

    private static function norm(?string $s): string
    {
        $t = mb_strtolower((string) $s);
        $t = (string) preg_replace('/(\d),(\d)/', '$1.$2', $t);
        $t = (string) preg_replace('/(\d)\s*(kilogram|kilo|kg)\b/', '$1 kg', $t);
        $t = (string) preg_replace('/(\d)\s*(gram|gr|g)\b/', '$1 g', $t);
        $t = (string) preg_replace('/[^a-z0-9.\s]/', ' ', $t);

        return ' '.trim((string) preg_replace('/\s+/', ' ', $t)).' ';
    }

    /**
     * The size in grams ("1,8 kg" is 1800, "500 gram" is 500); 0 when none is written.
     */
    private static function ukuran(?string $s): int
    {
        return preg_match('/(\d+(?:\.\d+)?) (kg|g) /', self::norm($s), $m)
            ? (int) round((float) $m[1] * ($m[2] === 'kg' ? 1000 : 1))
            : 0;
    }

    private static function isiPack(?string $s): int
    {
        return preg_match('/isi\s*(\d+)/i', (string) $s, $m) ? (int) $m[1] : 0;
    }

    /**
     * @return list<string>
     */
    private static function kataNama(?string $s): array
    {
        return array_values(array_filter(explode(' ', trim(self::norm($s))), fn ($w) => $w !== '' && ! in_array($w, self::KATA_ABAI, true) && ! preg_match('/^\d+(\.\d+)?$/', $w)));
    }

    private static function angka(mixed $v): int
    {
        return (int) preg_replace('/\D/', '', (string) $v);
    }

    private static function qty(float $q): string
    {
        return $q == (int) $q ? (string) (int) $q : (string) $q;
    }
}
