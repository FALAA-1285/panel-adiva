<?php

namespace App\Enums;

/**
 * The order commands, the same as the admin's WhatsApp commands (Orders.dc.html), in
 * the design's button order.
 */
enum AksiPesanan: string
{
    case Acc = 'ACC';
    case Lunas = 'LUNAS';
    case Siap = 'SIAP';
    case Kirim = 'KIRIM';
    case Selesai = 'SELESAI';
    case Batal = 'BATAL';
    case Tolak = 'TOLAK';
    // under "Lainnya" (once a WhatsApp command only): close without telling the customer
    case Tutup = 'TUTUP';

    /**
     * The seven buttons of the design's grid; the rest sit under "Lainnya".
     *
     * @return list<self>
     */
    public static function utama(): array
    {
        return [self::Acc, self::Lunas, self::Siap, self::Kirim, self::Selesai, self::Batal, self::Tolak];
    }

    /**
     * @return list<self>
     */
    public static function lainnya(): array
    {
        return [self::Tutup];
    }

    /**
     * The statuses the command applies to, following the workflow: LUNAS after ACC,
     * KIRIM after LUNAS.
     *
     * @return list<StatusOrder>
     */
    public function dariStatus(): array
    {
        return match ($this) {
            self::Acc => [StatusOrder::MenungguKonfirmasi],
            self::Tolak, self::Batal => [StatusOrder::MenungguKonfirmasi, StatusOrder::Diacc, StatusOrder::Karyawan],
            self::Lunas => [StatusOrder::Diacc, StatusOrder::Karyawan, StatusOrder::BatalOtomatis],
            self::Kirim => [StatusOrder::Lunas],
            self::Siap => [StatusOrder::Lunas, StatusOrder::Diacc, StatusOrder::Karyawan],
            self::Selesai => [StatusOrder::Dikirim, StatusOrder::Lunas],
            self::Tutup => [StatusOrder::MenungguKonfirmasi, StatusOrder::Diacc, StatusOrder::Karyawan, StatusOrder::Lunas, StatusOrder::Dikirim],
        };
    }

    /**
     * The status after the command; SIAP keeps it and only marks "siap diambil".
     */
    public function keStatus(): ?StatusOrder
    {
        return match ($this) {
            self::Acc => StatusOrder::Diacc,
            self::Tolak => StatusOrder::Ditolak,
            self::Batal => StatusOrder::Dibatalkan,
            self::Lunas => StatusOrder::Lunas,
            self::Kirim => StatusOrder::Dikirim,
            self::Selesai => StatusOrder::Selesai,
            self::Tutup => StatusOrder::Ditutup,
            self::Siap => null,
        };
    }

    /**
     * What happens, as the button's tooltip and the history line say it.
     */
    public function keterangan(): string
    {
        return match ($this) {
            self::Acc => 'invoice PDF dibuat & dikirim',
            self::Tolak => 'customer dikabari',
            self::Batal => 'dibatalkan atas permintaan customer',
            self::Lunas => 'pembayaran dikonfirmasi',
            self::Kirim => 'resi dikirim ke customer',
            self::Siap => 'customer dikabari alamat & jam ambil',
            self::Selesai => 'bot minta ulasan · CRM → REPEAT',
            self::Tutup => 'ditutup tanpa chat ke customer',
        };
    }

    /**
     * The button on Detail pesanan, as a verb.
     */
    public function label(): string
    {
        return match ($this) {
            self::Acc => 'ACC & kirim invoice',
            self::Tolak => 'Tolak',
            self::Batal => 'Batalkan',
            self::Lunas => 'Tandai lunas',
            self::Siap => 'Siap diambil',
            self::Kirim => 'Tandai dikirim',
            self::Selesai => 'Tandai selesai',
            self::Tutup => 'Tutup',
        };
    }

    /**
     * The HakAkses action that allows it.
     */
    public function hakAkses(): string
    {
        return match ($this) {
            self::Acc, self::Tolak, self::Batal, self::Tutup => 'acc',
            default => 'status_order',
        };
    }

    /**
     * Blue when it moves the order forward, red text when it ends it.
     */
    public function gaya(): string
    {
        return match ($this) {
            self::Tolak, self::Batal, self::Tutup => 'bahaya',
            default => 'utama',
        };
    }
}
