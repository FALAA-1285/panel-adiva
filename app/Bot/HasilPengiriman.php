<?php

namespace App\Bot;

/**
 * How booking (or cancelling) a shipment at Mengantar went, in what the bot must do next.
 */
final readonly class HasilPengiriman
{
    /**
     * @param  bool  $ok  the shipment is booked and paid, with a waybill
     * @param  bool  $simpanCatatan  save the new note even though it is not $ok (booked but unpaid, or cancelled)
     * @param  string  $statusBaru  the order's status afterwards
     * @param  string|null  $pesanCustomer  what the customer is told, if anything
     * @param  string  $pesanAdmin  what the supervisor is told (Notifikasi), written with WhatsApp's *bold*
     * @param  string  $alasan  why it did not work, in one plain sentence (empty when it did)
     */
    public function __construct(
        public bool $ok,
        public bool $simpanCatatan,
        public string $statusBaru,
        public string $resi = '',
        public string $kurirFinal = '',
        public string $catatanAdmin = '',
        public ?string $pesanCustomer = null,
        public string $pesanAdmin = '',
        public string $alasan = '',
    ) {}
}
