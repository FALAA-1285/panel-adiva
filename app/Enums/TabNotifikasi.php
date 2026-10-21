<?php

namespace App\Enums;

use App\Models\Notifikasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The tabs on Notifikasi, in the design's order. Each tab defines both which rows it
 * lists and how it is counted, so the two can never disagree.
 */
enum TabNotifikasi: string
{
    case Semua = 'semua';
    case Belum = 'belum';
    case Order = 'order';
    case ButuhAdmin = 'butuh-admin';
    case Sistem = 'sistem';

    public function label(): string
    {
        return match ($this) {
            self::Semua => 'Semua',
            self::Belum => 'Belum dibaca',
            self::Order => 'Order & pembayaran',
            self::ButuhAdmin => 'Butuh admin',
            self::Sistem => 'Sistem',
        };
    }

    public function kategori(): ?KategoriNotifikasi
    {
        return match ($this) {
            self::Order => KategoriNotifikasi::Order,
            self::ButuhAdmin => KategoriNotifikasi::ButuhAdmin,
            self::Sistem => KategoriNotifikasi::Sistem,
            self::Semua, self::Belum => null,
        };
    }

    /**
     * @param  Builder<Notifikasi>  $query
     * @return Builder<Notifikasi>
     */
    public function saring(Builder $query): Builder
    {
        return match ($this) {
            self::Semua => $query,
            self::Belum => $query->belumDibaca(),
            default => $query->where('kategori', $this->kategori()?->value),
        };
    }

    /**
     * The tab's number from Notifikasi::rekapPerKategori() (rows: kategori, semua, belum).
     *
     * @param  Collection<int, stdClass>  $rekap
     */
    public function hitung(Collection $rekap): int
    {
        return (int) match ($this) {
            self::Semua => $rekap->sum('semua'),
            self::Belum => $rekap->sum('belum'),
            default => $rekap->where('kategori', $this->kategori()?->value)->sum('semua'),
        };
    }
}
