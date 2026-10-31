<?php

namespace App\Http\Controllers\Katalog;

use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Controller;
use App\Jobs\SinkronkanBarangToko;
use App\Models\BarangToko;
use App\Models\SinkronAccurate;
use App\Support\Aktivitas;
use App\Support\Demo;
use App\Support\Rupiah;
use App\Support\TabKatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Katalog → Barang toko: Toko Sawojajar's goods as Accurate has them. Read only; the shelf
 * changes in Accurate and arrives here within a minute (accurate:sinkron).
 */
class BarangTokoController extends Controller
{
    private const PER_HALAMAN = 50;

    public function index(Request $request): Response
    {
        $halaman = max(1, $request->integer('halaman', 1));
        $terakhir = SinkronAccurate::query()->latest('mulai')->latest('id')->first();
        $berhasil = $terakhir?->status === 'BERHASIL' ? $terakhir : SinkronAccurate::terakhirBerhasil();

        return Inertia::render('katalog/toko', [
            'tab' => TabKatalog::hitungan(),
            'filter' => [
                'cari' => (string) $request->query('cari'),
                'kategori' => (string) $request->query('kategori'),
            ],
            'barang' => $this->cari($request)
                ->orderBy('nama')
                ->forPage($halaman, self::PER_HALAMAN)
                ->get()
                ->map(fn (BarangToko $b) => [
                    'id' => $b->id,
                    'nama' => $b->nama,
                    'kode' => $b->kode,
                    'barcode' => $b->barcode,
                    'kategori' => $b->kategori,
                    'satuan' => $b->satuan,
                    'harga' => $b->harga === null ? null : Rupiah::teks($b->harga),
                ]),
            'halaman' => ['sekarang' => $halaman, 'total' => $this->cari($request)->count(), 'per' => self::PER_HALAMAN],
            'kategori' => BarangToko::query()->whereNotNull('kategori')->distinct()->orderBy('kategori')->pluck('kategori'),
            'sinkron' => [
                'jumlah' => BarangToko::query()->count(),
                'tanpaHarga' => BarangToko::query()->whereNull('harga')->count(),
                'berhasil' => $berhasil?->mulai->translatedFormat('j M H:i'),
                'gagal' => $terakhir?->status === 'GAGAL' ? [
                    'waktu' => $terakhir->mulai->translatedFormat('j M H:i'),
                    'galat' => $terakhir->galat,
                ] : null,
                'berjalan' => $terakhir?->status === 'BERJALAN' && $terakhir->mulai->gt(Demo::jamNyata()->subMinutes(10)),
            ],
        ]);
    }

    /**
     * "Sinkron sekarang": the scheduled run, now.
     */
    public function sinkron(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        SinkronkanBarangToko::dispatch($user);

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Sinkron barang toko', 'Accurate → Katalog · Barang toko');

        return back();
    }

    /**
     * @return Builder<BarangToko>
     */
    private function cari(Request $request): Builder
    {
        $cari = trim((string) $request->query('cari'));
        $kategori = trim((string) $request->query('kategori'));

        return BarangToko::query()
            ->when($kategori !== '', fn (Builder $q) => $q->where('kategori', $kategori))
            ->when($cari !== '', function (Builder $q) use ($cari) {
                // every word in the name, or the barcode / code as typed
                $q->where(function (Builder $q) use ($cari) {
                    $q->where(function (Builder $q) use ($cari) {
                        foreach (preg_split('/\s+/', $cari) ?: [] as $kata) {
                            $q->where('nama', 'ilike', '%'.addcslashes($kata, '%_\\').'%');
                        }
                    })->orWhere('barcode', $cari)->orWhere('kode', 'ilike', addcslashes($cari, '%_\\'));
                });
            });
    }
}
