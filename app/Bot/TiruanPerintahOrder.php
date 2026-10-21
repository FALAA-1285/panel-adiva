<?php

namespace App\Bot;

use App\Enums\AksiPesanan;
use App\Models\LogOrder;
use App\Models\User;
use App\Support\PerintahPesanan;

/**
 * Stands in for n8n (demo mode, the tests, or before n8n is connected): the order is
 * changed in the database here, and no invoice or WhatsApp message goes out.
 */
class TiruanPerintahOrder implements PerintahOrder
{
    public function jalankan(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): void
    {
        PerintahPesanan::jalankan($aksi, $order, $oleh, $catatan);
    }
}
