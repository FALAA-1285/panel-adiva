<?php

namespace App\Http\Controllers\Katalog;

use App\Accurate\OauthAccurate;
use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Controller;
use App\Jobs\SinkronkanBarangToko;
use App\Models\AksesAccurate;
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
            'akses' => $this->akses(),
        ]);
    }

    /**
     * The "Sambungan Accurate" box: how the panel reaches Accurate. No token leaves the server.
     *
     * @return array{cara: 'oauth'|'token'|null, oleh: string|null, sejak: string|null, berlaku: string|null, bisaOauth: bool}
     */
    private function akses(): array
    {
        $akses = AksesAccurate::query()->with('disambungkanOleh:id,name')->latest('id')->first();

        return [
            'cara' => match (true) {
                $akses !== null => 'oauth',
                filled(config('services.accurate.token')) => 'token',
                default => null,
            },
            'oleh' => $akses?->disambungkanOleh?->name,
            'sejak' => $akses?->disambungkan_at->translatedFormat('j M Y H:i'),
            'berlaku' => $akses?->kedaluwarsa_at?->translatedFormat('j M Y'),
            'bisaOauth' => OauthAccurate::siap(),
        ];
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
