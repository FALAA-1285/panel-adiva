<?php

namespace App\Bot;

/**
 * A bot number's WAHA session (Monitoring: "Lihat QR", "Restart sesi").
 */
interface SesiWaha
{
    /**
     * WAHA's status: STOPPED, STARTING, SCAN_QR_CODE, WORKING or FAILED.
     *
     * @throws SesiGagal
     */
    public function status(string $sesi): string;

    /**
     * The QR code to link the number again, as a PNG.
     *
     * @throws SesiGagal
     */
    public function qr(string $sesi): string;

    /**
     * Stop and start the session again.
     *
     * @throws SesiGagal
     */
    public function restart(string $sesi): void;
}
