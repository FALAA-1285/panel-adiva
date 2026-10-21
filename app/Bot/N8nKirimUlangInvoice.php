<?php

namespace App\Bot;

use App\Models\LogOrder;
use App\Models\User;

/**
 * perintah KIRIM_ULANG_INVOICE: n8n sends the same invoice PDF again, from the bot
 * number the customer uses.
 */
class N8nKirimUlangInvoice implements KirimUlangInvoice
{
    public function __construct(private N8nWebhook $n8n) {}

    public function kirim(LogOrder $order, User $oleh): void
    {
        $this->n8n->kirim('KIRIM_ULANG_INVOICE', [
            'order_id' => $order->order_id,
            'invoice_number' => $order->invoice_number,
            'oleh' => ['id' => $oleh->id, 'nama' => $oleh->name, 'nomor_wa' => $oleh->nomor_wa],
        ]);
    }
}
