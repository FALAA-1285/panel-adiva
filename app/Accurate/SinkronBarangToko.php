<?php

namespace App\Accurate;

use App\Enums\JenisNotifikasi;
use App\Models\BarangToko;
use App\Models\Notifikasi;
use App\Models\SinkronAccurate;
use App\Models\User;
use App\Support\KabarBot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies Toko Sawojajar's goods from Accurate into barang_toko: a good with a barcode that is
 * not suspended is sold at the shop; everything else is left out, and a good that left the
 * shop is removed. Stock is not read. Nothing is changed until the whole list has arrived,
 * and an empty list never wipes a filled shelf.
 */
class SinkronBarangToko
{
    /** hours without a good run before the supervisor hears of it */
    public const BASI_JAM = 3;

    public function __construct(private BacaAccurate $accurate) {}

    public function jalankan(?User $oleh = null): SinkronAccurate
    {
        $catatan = SinkronAccurate::create(['mulai' => now(), 'status' => 'BERJALAN', 'oleh_id' => $oleh?->id]);

        try {
            $toko = self::dijualDiToko($this->accurate->barang());

            if ($toko->isEmpty() && BarangToko::query()->exists()) {
                throw new AccurateGagal('Accurate tidak mengirim satu pun barang ber-barcode; data lama dipertahankan.');
            }

            $this->simpan($toko);
            $catatan->update(['status' => 'BERHASIL', 'selesai' => now(), 'jumlah' => $toko->count()]);
        } catch (Throwable $e) {
            if (! $e instanceof AccurateGagal) {
                report($e);
            }

            $catatan->update(['status' => 'GAGAL', 'selesai' => now(), 'galat' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->kabariKalauBasi($e->getMessage());
        }

        return $catatan;
    }

    /**
     * @param  list<array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}>  $barang
     * @return Collection<int, array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}>
     */
    public static function dijualDiToko(array $barang): Collection
    {
        return collect($barang)
            ->filter(fn (array $b) => ! $b['nonaktif'] && filled($b['barcode']))
            ->keyBy('accurate_id');
    }

    /**
     * @param  Collection<int, array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}>  $toko
     */
    private function simpan(Collection $toko): void
    {
        DB::transaction(function () use ($toko) {
            $sekarang = now();
            $kolom = ['kode', 'nama', 'barcode', 'kategori', 'satuan', 'harga', 'disinkron_at'];

            foreach ($toko->chunk(500) as $potong) {
                BarangToko::query()->upsert(
                    $potong->map(fn (array $b) => [
                        'accurate_id' => $b['accurate_id'],
                        'kode' => $b['kode'],
                        'nama' => mb_substr($b['nama'], 0, 255),
                        'barcode' => mb_substr((string) $b['barcode'], 0, 255),
                        'kategori' => $b['kategori'],
                        'satuan' => $b['satuan'],
                        'harga' => $b['harga'],
                        'disinkron_at' => $sekarang,
                        'created_at' => $sekarang,
                        'updated_at' => $sekarang,
                    ])->values()->all(),
                    ['accurate_id'],
                    [...$kolom, 'updated_at'],
                );
            }

            $hilang = BarangToko::query()->pluck('accurate_id')->diff($toko->keys());

            foreach ($hilang->chunk(1000) as $potong) {
                BarangToko::query()->whereIn('accurate_id', $potong->all())->delete();
            }
        });
    }

    /**
     * One notice once the shelf has gone BASI_JAM hours without a good run, and no second
     * one until a run goes through again.
     */
    private function kabariKalauBasi(string $galat): void
    {
        $berhasil = SinkronAccurate::terakhirBerhasil();
        $sejak = $berhasil?->mulai ?? SinkronAccurate::query()->oldest('mulai')->value('mulai');

        if ($sejak === null || now()->diffInHours($sejak, absolute: true) < self::BASI_JAM) {
            return;
        }

        $sudah = Notifikasi::query()
            ->where('jenis', JenisNotifikasi::SinkronAccurate->value)
            ->where('waktu', '>=', $berhasil?->mulai ?? $sejak)
            ->exists();

        if (! $sudah) {
            KabarBot::notifikasi(
                JenisNotifikasi::SinkronAccurate,
                'Data barang toko dari Accurate belum terbarui',
                'Terakhir berhasil: '.($berhasil ? $berhasil->mulai->translatedFormat('j M H:i') : 'belum pernah').' · '.mb_substr($galat, 0, 200),
            );
        }
    }
}
