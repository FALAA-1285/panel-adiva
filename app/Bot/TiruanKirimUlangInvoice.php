<?php

namespace App\Bot;

use App\Models\LogOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Stands in for n8n (demo mode, the tests): nothing is sent, it is only logged.
 */
class TiruanKirimUlangInvoice implements KirimUlangInvoice
{
    public function kirim(LogOrder $order, User $oleh): void
    {
        Log::info('Kirim ulang invoice tiruan (tidak dikirim)', ['order_id' => $order->order_id, 'invoice' => $order->invoice_number]);
    }
}
