<?php

namespace App\Bot;

use Illuminate\Support\Facades\Log;

/**
 * Stands in for WAHA's sessions (demo mode, the tests): every number counts as
 * connected and a restart is only written to the log.
 */
class TiruanSesi implements SesiWaha
{
    public function status(string $sesi): string
    {
        return 'WORKING';
    }

    public function qr(string $sesi): string
    {
        throw new SesiGagal('Mode tiruan: QR tidak tersedia');
    }

    public function restart(string $sesi): void
    {
        Log::info('Restart sesi WAHA tiruan (tidak dijalankan)', ['sesi' => $sesi]);
    }
}
