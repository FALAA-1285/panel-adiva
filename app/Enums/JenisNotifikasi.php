<?php

namespace App\Enums;

use App\Models\Notifikasi;

/**
 * notifikasi.jenis, as written by the n8n workflows. The column stays a plain string
 * (n8n may add types the panel does not know yet); read it with Notifikasi::jenisDikenal().
 */
enum JenisNotifikasi: string
{
    case JedaChat = 'JEDA_CHAT';
    case ButuhAdmin = 'BUTUH_ADMIN';
    case JedaKedaluwarsa = 'JEDA_KEDALUWARSA';
    case OrderBaru = 'ORDER_BARU';
    case InvoiceGagal = 'INVOICE_GAGAL';
    case OrderKaryawan = 'ORDER_KARYAWAN';
    case OrderDitutup = 'ORDER_DITUTUP';
    case OrderDibatalkan = 'ORDER_DIBATALKAN';
    case BuktiMasuk = 'BUKTI_MASUK';
    case BalasanGagal = 'BALASAN_GAGAL';
    case NomorPutus = 'NOMOR_PUTUS';
    case WorkflowError = 'WORKFLOW_ERROR';
    case PengingatJeda = 'PENGINGAT_JEDA';
    case LaporanHarian = 'LAPORAN_HARIAN';
    case BroadcastSelesai = 'BROADCAST_SELESAI';
    case Arsip = 'ARSIP';
    // since the bot is run from the panel only (docs/kontrak-n8n.md §9)
    case LampiranMasuk = 'LAMPIRAN_MASUK';
    case Pengiriman = 'PENGIRIMAN';
    case SosmedButuhAdmin = 'SOSMED_BUTUH_ADMIN';
    // an account locked after repeated wrong passwords (KunciMasuk)
    case LoginMencurigakan = 'LOGIN_MENCURIGAKAN';

    /**
     * The tab the workflows file this type under.
     */
    public function kategori(): KategoriNotifikasi
    {
        return match ($this) {
            self::JedaChat, self::ButuhAdmin, self::JedaKedaluwarsa, self::LampiranMasuk,
            self::SosmedButuhAdmin => KategoriNotifikasi::ButuhAdmin,
            self::OrderBaru, self::InvoiceGagal, self::OrderKaryawan, self::OrderDitutup,
            self::OrderDibatalkan, self::BuktiMasuk, self::Pengiriman => KategoriNotifikasi::Order,
            self::BalasanGagal, self::NomorPutus, self::WorkflowError, self::PengingatJeda,
            self::LaporanHarian, self::BroadcastSelesai, self::Arsip, self::LoginMencurigakan => KategoriNotifikasi::Sistem,
        };
    }

    /**
     * The button label, or null when the design shows no button.
     */
    public function aksi(): ?string
    {
        return match ($this) {
            self::JedaChat => 'Balas',
            self::ButuhAdmin, self::LampiranMasuk, self::SosmedButuhAdmin => 'Buka chat',
            self::Pengiriman => 'Lihat order',
            self::BalasanGagal => 'Kirim ulang',
            self::NomorPutus, self::WorkflowError => 'Monitoring',
            self::OrderBaru => 'Tinjau',
            self::InvoiceGagal => 'Lihat order',
            self::BuktiMasuk => 'Lihat bukti',
            self::OrderKaryawan, self::OrderDitutup, self::OrderDibatalkan => 'Lihat',
            self::LaporanHarian => 'Ringkasan',
            self::BroadcastSelesai => 'Laporan',
            self::LoginMencurigakan => 'Log aktivitas',
            self::JedaKedaluwarsa, self::PengingatJeda, self::Arsip => null,
        };
    }

    /**
     * The page the button opens. Paths follow design/halaman.json for pages not built
     * yet; switch them to named routes as those pages arrive.
     */
    public function tujuan(Notifikasi $notifikasi): ?string
    {
        return match ($this) {
            self::JedaChat, self::ButuhAdmin, self::LampiranMasuk => route('percakapan', array_filter(['pilih' => $notifikasi->crm_customer_id]), false),
            self::SosmedButuhAdmin => route('sosmed', absolute: false),
            self::Pengiriman => $notifikasi->order ? '/pesanan/'.$notifikasi->order->order_id : '/pesanan',
            self::BalasanGagal, self::NomorPutus, self::WorkflowError => '/monitoring',
            // the order relation is only loaded for the one type that needs it
            self::OrderBaru => $notifikasi->order ? '/pesanan/'.$notifikasi->order->order_id : '/pesanan',
            self::InvoiceGagal, self::OrderDitutup, self::OrderDibatalkan, self::BuktiMasuk => '/pesanan',
            self::OrderKaryawan => route('katalog.karyawan', absolute: false),
            self::LaporanHarian => route('home', absolute: false),
            self::BroadcastSelesai => '/broadcast',
            self::LoginMencurigakan => route('aktivitas', absolute: false),
            self::JedaKedaluwarsa, self::PengingatJeda, self::Arsip => null,
        };
    }
}
