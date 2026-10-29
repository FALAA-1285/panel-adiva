<?php

namespace App\Support;

/**
 * Panel security settings (Tim.dc.html: "Sesi & keamanan"), kept in CONFIG_TOKO.
 * Defaults and names live in KunciPengaturan.
 */
class PengaturanKeamanan
{
    public const KODE_PERANGKAT_BARU = 'PANEL_KODE_PERANGKAT_BARU';

    public const KELUAR_OTOMATIS = 'PANEL_KELUAR_OTOMATIS_JAM';

    /**
     * "Keluar otomatis" choices, in the design's order.
     *
     * @var list<int>
     */
    public const PILIHAN_JAM = [12, 4, 24];

    /**
     * "Kode WhatsApp di perangkat baru" (on unless switched off; always on where it is required).
     */
    public static function kodePerangkatBaru(): bool
    {
        return self::kodePerangkatWajib() || PengaturanToko::nilai(self::KODE_PERANGKAT_BARU) !== '0';
    }

    /**
     * The second factor cannot be switched off in production (BCA CSA butir 26). The public
     * demo is left out: its WhatsApp sender is a stand-in, so a code would never arrive.
     */
    public static function kodePerangkatWajib(): bool
    {
        return app()->isProduction() && ! Demo::aktif();
    }

    /**
     * Hours without activity before a panel session ends (12 unless changed).
     */
    public static function keluarOtomatisJam(): int
    {
        $jam = (int) PengaturanToko::nilai(self::KELUAR_OTOMATIS);

        return in_array($jam, self::PILIHAN_JAM, true) ? $jam : 12;
    }
}
