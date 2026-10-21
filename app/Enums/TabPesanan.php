<?php

namespace App\Enums;

use App\Models\LogOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status tabs on Pesanan (Orders.dc.html), in the design's order.
 */
enum TabPesanan: string
{
    case Semua = 'semua';
    case MenungguAcc = 'menunggu-acc';
    case MenungguBayar = 'menunggu-bayar';
    case BuktiMasuk = 'bukti-masuk';
    case Lunas = 'lunas';
    case Dikirim = 'dikirim';
    case Karyawan = 'karyawan';
    case Selesai = 'selesai';
    case Batal = 'batal';

    public function label(): string
    {
        return match ($this) {
            self::Semua => 'Semua',
            self::MenungguAcc => 'Menunggu ACC',
            self::MenungguBayar => 'Menunggu bayar',
            self::BuktiMasuk => 'Bukti masuk',
            self::Lunas => 'Lunas',
            self::Dikirim => 'Dikirim',
            self::Karyawan => 'Karyawan',
            self::Selesai => 'Selesai',
            self::Batal => 'Batal / tolak',
        };
    }

    /**
     * @param  Builder<LogOrder>  $query
     */
    public function terapkan(Builder $query): void
    {
        if ($status = $this->status()) {
            $query->whereIn('status_order', $status);
        }

        // a proof waiting to be checked (LogOrder::buktiMenunggu)
        if ($this === self::BuktiMasuk) {
            $query->whereNotNull('bukti_masuk_at');
        }
    }

    /**
     * Every tab's count, from one query grouped by status.
     *
     * @param  Builder<LogOrder>  $query  the orders Pesanan lists
     * @return array<string, int> tab => count
     */
    public static function hitungan(Builder $query): array
    {
        $perStatus = $query->toBase()
            ->selectRaw('status_order, count(*) as jumlah, count(bukti_masuk_at) as bukti')
            ->groupBy('status_order')
            ->get()
            ->keyBy('status_order');
        $jumlah = fn (array $status) => (int) $perStatus->only(array_map(fn (StatusOrder $s) => $s->value, $status))->sum('jumlah');

        $hitungan = [];

        foreach (self::cases() as $tab) {
            $hitungan[$tab->value] = match ($tab) {
                self::Semua => (int) $perStatus->sum('jumlah'),
                self::BuktiMasuk => (int) ($perStatus[StatusOrder::Diacc->value]->bukti ?? 0),
                default => $jumlah($tab->status() ?? []),
            };
        }

        return $hitungan;
    }

    /**
     * The statuses in the tab; null for every status.
     *
     * @return list<StatusOrder>|null
     */
    private function status(): ?array
    {
        return match ($this) {
            self::Semua => null,
            self::MenungguAcc => [StatusOrder::MenungguKonfirmasi],
            self::MenungguBayar, self::BuktiMasuk => [StatusOrder::Diacc],
            self::Lunas => [StatusOrder::Lunas],
            self::Dikirim => [StatusOrder::Dikirim],
            self::Karyawan => [StatusOrder::Karyawan],
            self::Selesai => [StatusOrder::Selesai],
            self::Batal => StatusOrder::batal(),
        };
    }
}
