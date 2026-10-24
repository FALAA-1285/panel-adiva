<?php

namespace App\Support;

/**
 * How an order is shipped and what of it is billed, as Adiva decides it (n8n's Rapikan
 * Balasan and Cek_Ongkir). An expedition parcel is ONGKIR COD: the customer pays the
 * shipping cost to the courier, so only the products and the packing are transferred.
 * The company's own motorbike courier and truck, pickups, salary orders and parcels
 * marked GRATIS ONGKIR are not.
 */
class AturanKirim
{
    public const KURIR_COD = 'EKSPEDISI (ONGKIR COD)';

    public const METODE_COD = 'TRANSFER (ONGKIR COD)';

    /**
     * Whether the order is marked ONGKIR COD.
     */
    public static function ongkirCod(?string $metodeBayar, ?string $kurir): bool
    {
        return (bool) preg_match('/ONGKIR COD/i', "{$metodeBayar} {$kurir}");
    }

    /**
     * Sent by an expedition (not picked up, not the company's own courier or truck, not
     * a salary order), whatever is written about its cost.
     */
    public static function ekspedisi(?string $kurir, ?string $metodeBayar): bool
    {
        return trim((string) $kurir) !== ''
            && ! preg_match('/AMBIL SENDIRI|PICKUP|KARYAWAN|KURIR MOTOR|ARMADA KANTOR|POTONG GAJI/i', "{$kurir} {$metodeBayar}");
    }

    /**
     * Whether an order the AI wrote up is ONGKIR COD: by expedition, and not a parcel
     * the shop sends free.
     */
    public static function jadiCod(?string $kurir, ?string $metodeBayar): bool
    {
        return self::ekspedisi($kurir, $metodeBayar) && ! preg_match('/GRATIS ONGKIR/i', (string) $kurir);
    }

    /**
     * "JNE" → "EKSPEDISI (ONGKIR COD) - JNE"; a courier already marked stays as it is.
     */
    public static function kurirCod(string $kurir): string
    {
        if (preg_match('/ONGKIR COD/i', $kurir)) {
            return $kurir;
        }

        return mb_substr(self::KURIR_COD.(preg_match('/^\s*EKSPEDISI\b/i', $kurir) ? '' : ' - '.trim($kurir)), 0, 80);
    }

    /**
     * The packing fee of an expedition parcel: PACKING_MINIMAL or PACKING_PER_KG per
     * started kilo, whichever is higher; free from PACKING_GRATIS_DI_ATAS worth of
     * products. 0 when the settings are empty or the weight is unknown.
     *
     * @param  array<string, string|null>|null  $config  CONFIG_TOKO already read (the saved settings when null)
     */
    public static function packing(float $beratKg, int $totalProduk, ?string $kurir, ?string $metodeBayar, ?array $config = null): int
    {
        $config ??= PengaturanToko::nilaiBanyak(['PACKING_PER_KG', 'PACKING_MINIMAL', 'PACKING_GRATIS_DI_ATAS']);
        $angka = fn (?string $v) => (int) preg_replace('/\D/', '', (string) $v);
        $perKg = $angka($config['PACKING_PER_KG'] ?? null);
        $minimal = $angka($config['PACKING_MINIMAL'] ?? null);
        $gratisDiAtas = $angka($config['PACKING_GRATIS_DI_ATAS'] ?? null);

        if (($perKg === 0 && $minimal === 0) || $beratKg <= 0 || ! self::jadiCod($kurir, $metodeBayar)) {
            return 0;
        }

        if ($gratisDiAtas > 0 && $totalProduk >= $gratisDiAtas) {
            return 0;
        }

        return max($minimal, (int) ceil($beratKg) * $perKg);
    }
}
