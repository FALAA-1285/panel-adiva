<?php

namespace App\Support;

use App\Enums\AksiPesanan;
use App\Enums\KategoriAktivitas;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\ConfigToko;
use App\Models\LogOrder;
use App\Models\Riwayat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Says why an order command does not apply, and runs it in the panel's own database
 * for TiruanPerintahOrder (demo mode, tests). With BOT_DRIVER=langsung n8n runs the
 * command instead (N8nPerintahOrder): it makes the invoice and messages the customer.
 */
class PerintahPesanan
{
    /**
     * Why the command cannot run now (the grey button's tooltip), or null when it can.
     * $catatan is the text typed with it: for ACC an exact shipping cost, which also
     * allows ACC again on a DIACC order (a revised cost, the same invoice number).
     */
    public static function alasanTidakBerlaku(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): ?string
    {
        $pickup = $order->ambil_sendiri;
        [$catatan, $packing] = $aksi === AksiPesanan::Acc ? self::pisahPacking($catatan) : [$catatan, null];
        $ongkir = self::ongkir($catatan);

        $alasan = match (true) {
            $aksi === AksiPesanan::Siap && ! $pickup => 'SIAP hanya untuk pesanan ambil sendiri',
            $aksi === AksiPesanan::Kirim && $pickup => 'Pesanan ambil sendiri tidak dikirim kurir',
            $aksi === AksiPesanan::Acc && $ongkir === false => 'Tulis ongkir dalam rupiah, mis. 25000 atau gratis',
            $aksi === AksiPesanan::Acc && $packing === false => 'Tulis packing dalam rupiah, mis. packing 5000 atau packing gratis',
            $aksi === AksiPesanan::Acc && $ongkir !== null && $pickup => 'Pesanan ambil sendiri tidak memakai ongkir',
            self::revisiOngkir($aksi, $order, $ongkir) => null,
            $aksi === AksiPesanan::Acc && $order->status_order === StatusOrder::Diacc && $order->lunas_at === null => 'Order sudah di-ACC · tulis ongkir baru di catatan untuk revisi ongkir',
            ! in_array($order->status_order, $aksi->dariStatus(), true) => "{$aksi->value} tidak berlaku untuk status {$order->status_order->value}",
            $aksi === AksiPesanan::Siap && $order->status_order === StatusOrder::Diacc && ! str_contains((string) $order->metode_bayar, 'BAYAR DI TEMPAT') => 'SIAP butuh LUNAS atau bayar di tempat',
            default => null,
        };

        if ($alasan !== null || self::boleh($aksi, $order, $oleh)) {
            return $alasan;
        }

        $peran = $order->tipe_customer === TipeCustomer::Karyawan
            ? [...HakAkses::AKSI[$aksi->hakAkses()]['peran'], ...HakAkses::AKSI['order_karyawan']['peran']]
            : HakAkses::AKSI[$aksi->hakAkses()]['peran'];

        return "{$aksi->value} hanya untuk ".collect($peran)->unique()->map->label()->join(', ', ' dan ');
    }

    /**
     * Change the order, add a history line and log it.
     */
    public static function jalankan(AksiPesanan $aksi, LogOrder $order, User $oleh, ?string $catatan = null): void
    {
        $catatan = filled($catatan) ? trim((string) $catatan) : null;

        DB::transaction(function () use ($aksi, $order, $oleh, $catatan) {
            $jamBayar = $order->jamBatasBayar();
            [$catatanOngkir, $packing] = $aksi === AksiPesanan::Acc ? self::pisahPacking($catatan) : [$catatan, null];
            $ongkir = self::ongkir($catatanOngkir);
            $revisi = self::revisiOngkir($aksi, $order, $ongkir);
            $catatanBaru = self::catatanBerantai($aksi, $order, $catatan, $revisi, $ongkir);
            $ubah = match ($aksi) {
                AksiPesanan::Acc => [
                    'diacc_at' => now(),
                    'invoice_number' => $revisi ? $order->invoice_number : self::invoiceBerikutnya(),
                    'batas_bayar_at' => $jamBayar === null ? null : JamKerja::dariPengaturan()->tambah(now(), $jamBayar),
                    ...(is_int($ongkir) ? ['ongkir' => $ongkir] : []),
                    ...(is_int($packing) ? ['biaya_packing' => $packing] : []),
                    'catatan_admin' => $catatanBaru,
                ],
                AksiPesanan::Lunas => ['lunas_at' => now(), 'catatan_admin' => $catatanBaru],
                // the company's own courier and truck carry no waybill: the note is only who drives
                AksiPesanan::Kirim => [
                    'dikirim_at' => now(),
                    'catatan_admin' => $catatanBaru,
                    ...(self::kurirSendiri($order) === null ? ['no_resi' => $catatan] : []),
                ],
                AksiPesanan::Siap => ['siap_diambil_at' => now(), 'catatan_admin' => $catatanBaru],
                AksiPesanan::Selesai => ['selesai_at' => now(), 'catatan_admin' => $catatanBaru],
                AksiPesanan::Batal, AksiPesanan::Tolak, AksiPesanan::Tutup => ['catatan_admin' => $catatanBaru],
            };

            $order->update([...$ubah, 'status_order' => $aksi->keStatus() ?? $order->status_order]);

            // the counter Pengaturan shows as "Invoice terakhir"; not a setting change, so not logged
            if ($aksi === AksiPesanan::Acc && ! $revisi) {
                ConfigToko::query()->updateOrCreate(['kunci' => 'INVOICE_TERAKHIR'], ['nilai' => $order->invoice_number]);
            }

            Riwayat::create([
                'subjek_type' => $order->getMorphClass(),
                'subjek_id' => $order->id,
                'waktu' => now(),
                'jenis' => $aksi->value,
                'nada' => 'blue',
                'teks' => collect([
                    "{$aksi->value} dari panel",
                    $revisi ? 'revisi ongkir · invoice dikirim ulang' : $aksi->keterangan(),
                    is_int($ongkir) ? 'ongkir '.($ongkir === 0 ? 'gratis' : Rupiah::teks($ongkir)).($order->ongkirCod() ? ' (COD ke kurir)' : '') : null,
                    is_int($packing) ? 'packing '.($packing === 0 ? 'gratis' : Rupiah::teks($packing)) : null,
                    self::alasan($aksi, $catatan),
                ])->filter()->implode(' · '),
            ]);

            // Log aktivitas: "ACC order" · "ORD-260928-014 · invoice INV/2026/09/142 terkirim"
            Aktivitas::catat($oleh, KategoriAktivitas::Pesanan, "{$aksi->value} order", collect([
                $order->order_id,
                match ($aksi) {
                    AksiPesanan::Acc => "invoice {$order->invoice_number} terkirim",
                    AksiPesanan::Kirim => $order->no_resi ? "resi {$order->no_resi}" : 'tanpa resi',
                    AksiPesanan::Lunas => Rupiah::teks($order->tagihan()),
                    default => $aksi->keterangan(),
                },
                self::alasan($aksi, $catatan),
            ])->filter()->implode(' · '), $order);
        });
    }

    /**
     * "motor" or "armada" for an order the company delivers itself (no waybill), else null.
     */
    public static function kurirSendiri(LogOrder $order): ?string
    {
        if ($order->ambil_sendiri) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/ARMADA KANTOR/i', (string) $order->kurir_pilihan) => 'armada',
            (bool) preg_match('/KURIR MOTOR/i', (string) $order->kurir_pilihan) => 'motor',
            default => null,
        };
    }

    /**
     * The order's note with this command added, written the way n8n always did:
     * "ACC 2026-09-28 13:42 ongkir Rp25.000 | LUNAS 2026-09-28 15:05". Other workflows
     * (Batas Bayar, Laporan Harian) and the AI's Cek Order tool read those words to know
     * when an order was ACC'd, paid or sent, so the wording and the " | " must not change.
     */
    public static function catatanBerantai(AksiPesanan $aksi, LogOrder $order, ?string $catatan, bool $revisi, int|false|null $ongkir): string
    {
        $waktu = now()->timezone('Asia/Jakarta')->format('Y-m-d H:i');
        $rapi = trim((string) preg_replace('/^[\s|:,;-]+|[\s|:,;-]+$/', '', (string) $catatan));
        $alasan = $rapi === '' ? '' : ' - '.$rapi;

        $baru = match ($aksi) {
            // 'REVISI ONGKIR' is not written with a leading 'ACC ' so the payment deadline still counts from the first ACC
            AksiPesanan::Acc => ($revisi ? 'REVISI ONGKIR ' : 'ACC ').$waktu
                .(is_int($ongkir) ? ' ongkir '.($ongkir === 0 ? 'GRATIS' : Rupiah::teks($ongkir)) : ''),
            AksiPesanan::Tutup => "DITUTUP {$waktu}{$alasan}",
            AksiPesanan::Tolak => "DITOLAK {$waktu}{$alasan}",
            AksiPesanan::Batal => "DIBATALKAN (permintaan customer) {$waktu}{$alasan}",
            AksiPesanan::Siap => "SIAP DIAMBIL {$waktu}{$alasan}",
            AksiPesanan::Lunas => "LUNAS {$waktu}".($order->status_order === StatusOrder::BatalOtomatis ? ' (dibuka lagi dari batal otomatis)' : ''),
            AksiPesanan::Kirim => match (self::kurirSendiri($order)) {
                'armada' => "DIKIRIM {$waktu} armada kantor{$alasan}",
                'motor' => "DIKIRIM {$waktu} kurir motor{$alasan}",
                default => "DIKIRIM {$waktu} resi {$rapi}",
            },
            AksiPesanan::Selesai => "SELESAI {$waktu}",
        };

        return trim(((string) $order->catatan_admin !== '' ? $order->catatan_admin.' | ' : '').$baru);
    }

    private static function boleh(AksiPesanan $aksi, LogOrder $order, User $oleh): bool
    {
        return HakAkses::boleh($oleh, $aksi->hakAkses())
            || ($order->tipe_customer === TipeCustomer::Karyawan && HakAkses::boleh($oleh, 'order_karyawan'));
    }

    /**
     * The reason typed for BATAL, TOLAK or TUTUP.
     */
    private static function alasan(AksiPesanan $aksi, ?string $catatan): ?string
    {
        return in_array($aksi, [AksiPesanan::Batal, AksiPesanan::Tolak, AksiPesanan::Tutup], true) ? $catatan : null;
    }

    /**
     * "25000 packing 5000" → ["25000", 5000]: the packing fee typed with ACC (as n8n reads
     * "ACC ORD-xxx 25000 packing 5000"), and the rest. Null when none was typed, false
     * when it is not an amount.
     *
     * @return array{0: string|null, 1: int|false|null}
     */
    public static function pisahPacking(?string $catatan): array
    {
        if (! preg_match('/\bpacking\s*:?\s*(\S+)/i', (string) $catatan, $m)) {
            return [$catatan, null];
        }

        $sisa = trim((string) str_replace($m[0], '', (string) $catatan));
        $nilai = self::ongkir($m[1]);

        // the shared reader refuses small amounts; packing may be a few thousand rupiah
        if ($nilai === false && preg_match('/^(?:rp\.?)?(\d[\d.]*)$/i', $m[1], $n)) {
            $nilai = (int) str_replace('.', '', $n[1]);
        }

        return [$sisa === '' ? null : $sisa, $nilai];
    }

    /**
     * Whether KIRIM needs a waybill: a parcel sent by an expedition, not picked up, not the
     * company's own courier or truck, not an employee order.
     */
    public static function butuhResi(LogOrder $order): bool
    {
        return ! $order->ambil_sendiri && self::kurirSendiri($order) === null && ! PesanPerintah::potongGaji($order);
    }

    /**
     * The shipping cost typed with ACC, read like n8n's Parse Perintah: "25000", "25rb",
     * "Rp25.000", "gratis" or "0". Null when nothing was typed, false when it is not a
     * cost (below Rp1.000 or above Rp2.000.000).
     */
    public static function ongkir(?string $catatan): int|false|null
    {
        $teks = trim((string) $catatan);

        if ($teks === '') {
            return null;
        }

        if (preg_match('/^(?:ongkir\s*:?\s*)?(?:gratis|free|rp\.?\s*0|0)$/i', $teks)) {
            return 0;
        }

        if (! preg_match('/^(?:ongkir\s*:?\s*)?(?:rp\.?\s*)?(\d[\d.,]*)\s*(rb|ribu|k)?\s*$/i', $teks, $m)) {
            return false;
        }

        $nilai = filled($m[2] ?? null)
            ? (int) round((float) str_replace(',', '.', str_replace('.', '', $m[1])) * 1000)
            : (int) preg_replace('/[.,]/', '', $m[1]);

        return $nilai >= 1000 && $nilai <= 2_000_000 ? $nilai : false;
    }

    /**
     * ACC with a new cost on an order already ACC'd and not yet paid: the invoice is sent
     * again with the same number and a new payment deadline.
     */
    public static function revisiOngkir(AksiPesanan $aksi, LogOrder $order, int|false|null $ongkir): bool
    {
        return $aksi === AksiPesanan::Acc && is_int($ongkir) && $order->status_order === StatusOrder::Diacc && $order->lunas_at === null;
    }

    /**
     * "INV/2026/09/142": the number after INVOICE_TERAKHIR (CONFIG_TOKO) or after the
     * highest number used this month, whichever is higher. A new month starts at 001.
     */
    public static function invoiceBerikutnya(): string
    {
        $awalan = 'INV/'.now()->format('Y/m').'/';
        $nomor = fn (?string $invoice) => str_starts_with((string) $invoice, $awalan) ? (int) substr((string) $invoice, strlen($awalan)) : 0;

        $config = ConfigToko::query()->where('kunci', 'INVOICE_TERAKHIR')->lockForUpdate()->first();
        $terakhirConfig = $nomor($config ? $config->nilai : PengaturanToko::nilai('INVOICE_TERAKHIR'));

        $terakhirOrder = LogOrder::query()
            ->where('invoice_number', 'like', $awalan.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $terakhir = max($terakhirConfig, $nomor($terakhirOrder));

        return $awalan.sprintf('%03d', $terakhir + 1);
    }
}
