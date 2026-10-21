<?php

namespace App\Bot;

use App\Models\LogOrder;
use App\Models\User;

/**
 * "Kirim ulang ke customer": the order's invoice PDF sent to the customer again.
 */
interface KirimUlangInvoice
{
    /**
     * @throws PerintahGagal
     */
    public function kirim(LogOrder $order, User $oleh): void;
}
