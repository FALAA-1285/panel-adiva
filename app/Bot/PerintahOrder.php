<?php

namespace App\Bot;

use App\Enums\AksiPesanan;
use App\Models\LogOrder;
use App\Models\User;

/**
 * Runs an order command from the panel (ACC, TOLAK, BATAL, LUNAS, SIAP, KIRIM, SELESAI,
 * TUTUP). The note carries the receipt number, the shipping cost for ACC,
 * or the reason.
 * Whether it may run is checked before, by PerintahPesanan::alasanTidakBerlaku().
 */
interface PerintahOrder
{
    /**
     * @throws PerintahGagal when the command did not run; its message is shown to the admin
     */
    public function jalankan(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): void;
}
