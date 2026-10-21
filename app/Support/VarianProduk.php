<?php

namespace App\Support;

use App\Models\Produk;
use Illuminate\Support\Str;

class VarianProduk
{
    /**
     * Product × flavour, the names photos and order lines use:
     * "Molreng Reguler" + "Bawang original, pedas" → "Molreng Reguler Bawang Original",
     * "Molreng Reguler Pedas". A name that already ends in its flavour stays as is
     * ("Basreng Pedas Daun Jeruk").
     *
     * @return list<array{judul: string, produkId: int}>
     */
    public static function semua(): array
    {
        $varian = [];

        foreach (Produk::query()->orderBy('id')->get() as $produk) {
            $judul = collect(explode(',', (string) $produk->rasa))
                ->map(fn (string $r) => Str::title(trim($r)))
                ->filter()
                ->map(fn (string $r) => Str::endsWith(Str::lower($produk->jenis_produk), Str::lower($r))
                    ? $produk->jenis_produk
                    : "{$produk->jenis_produk} {$r}");

            // a product without flavours is a single variant
            foreach ($judul->whenEmpty(fn () => collect([$produk->jenis_produk])) as $j) {
                $varian[$j] = ['judul' => $j, 'produkId' => $produk->id];
            }
        }

        return array_values($varian);
    }
}
