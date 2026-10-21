<?php

namespace App\Support;

use App\Enums\JenisNotifikasi;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The supervisor's short WhatsApp notices (docs/kontrak-n8n.md §9). n8n only writes the
 * notification; the panel sends "judul · detail · link" from SESI_UTAMA. Each row of
 * Pengaturan → Notifikasi WhatsApp can be switched off (NOTIF_WA_MATI).
 */
class KabarWa
{
    /**
     * The card's rows, in the design's order. The scheduled ones under them always go out.
     *
     * @var list<string>
     */
    public const BARIS = [
        'order_baru', 'order_karyawan', 'butuh_admin', 'lampiran', 'balasan_gagal', 'invoice_gagal', 'jeda_chat',
        'jeda_kedaluwarsa', 'jeda_error', 'dobel', 'order_otomatis', 'bukti_belum_dicek', 'nomor_putus', 'workflow_error',
    ];

    /** Notifications older than this are not sent any more (the panel was down, the queue cleared). */
    public const UMUR_MENIT = 10;

    /**
     * The row a notification belongs to; "terjadwal" for the daily and weekly ones, null for
     * the kinds that stay in the panel (a campaign finished, a shipment booked from the panel).
     */
    public static function baris(Notifikasi $n): ?string
    {
        $teks = Str::lower($n->judul.' '.$n->detail);

        return match ($n->jenisDikenal()) {
            JenisNotifikasi::OrderBaru => $n->order?->dobel_dengan !== null ? 'dobel' : 'order_baru',
            JenisNotifikasi::OrderKaryawan => 'order_karyawan',
            JenisNotifikasi::ButuhAdmin, JenisNotifikasi::SosmedButuhAdmin => 'butuh_admin',
            JenisNotifikasi::BuktiMasuk => str_contains($teks, 'menunggu dicek') ? 'bukti_belum_dicek' : 'lampiran',
            JenisNotifikasi::LampiranMasuk => 'lampiran',
            JenisNotifikasi::BalasanGagal => 'balasan_gagal',
            JenisNotifikasi::InvoiceGagal => 'invoice_gagal',
            JenisNotifikasi::JedaChat => 'jeda_chat',
            JenisNotifikasi::JedaKedaluwarsa => 'jeda_kedaluwarsa',
            JenisNotifikasi::WorkflowError => str_contains($teks, 'jeda') ? 'jeda_error' : 'workflow_error',
            JenisNotifikasi::OrderDibatalkan, JenisNotifikasi::OrderDitutup => 'order_otomatis',
            JenisNotifikasi::NomorPutus => 'nomor_putus',
            JenisNotifikasi::PengingatJeda, JenisNotifikasi::LaporanHarian, JenisNotifikasi::Arsip => 'terjadwal',
            JenisNotifikasi::BroadcastSelesai, JenisNotifikasi::Pengiriman, null => null,
        };
    }

    /**
     * @return list<string> the rows switched off
     */
    public static function mati(): array
    {
        return array_values(array_intersect(
            self::BARIS,
            array_map('trim', explode(',', (string) PengaturanToko::nilai('NOTIF_WA_MATI'))),
        ));
    }

    /**
     * @param  list<string>  $mati
     */
    public static function simpan(array $mati, User $oleh, ?string $perangkat = null): void
    {
        PengaturanToko::ubah('NOTIF_WA_MATI', implode(',', array_values(array_intersect(self::BARIS, $mati))), $oleh, $perangkat);
    }

    /**
     * "*Order baru menunggu ACC*\nRina Maharani · ORD-260928-014 · Rp1.283.000\n\nBuka di panel: …/notifikasi/12"
     */
    public static function teks(Notifikasi $n): string
    {
        return collect([
            '*'.$n->judul.'*',
            filled($n->detail) ? Str::limit((string) $n->detail, 600) : null,
            '',
            'Buka di panel: '.route('notifikasi.buka', $n),
        ])->filter(fn (?string $baris) => $baris !== null)->implode("\n");
    }
}
