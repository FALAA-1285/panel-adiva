<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusData;
use App\Enums\StatusOrder;
use App\Http\Requests\Katalog\SimpanPaketRequest;
use App\Models\PaketReseller;
use App\Models\Produk;
use App\Models\UjiAdiva;
use App\Support\Aktivitas;
use App\Support\Rupiah;
use App\Support\TabKatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Katalog & pengetahuan": everything Adiva reads before answering.
 */
class KatalogController extends Controller
{
    /**
     * Produk tab (Katalog.dc.html).
     */
    public function produk(): Response
    {
        $uji = UjiAdiva::query()->where('bagian', 'KATALOG')->latest('id')->first();

        return Inertia::render('katalog/produk', [
            'tab' => TabKatalog::hitungan(),
            'produk' => Produk::query()
                // "Foto: Ada" means a photo file has been uploaded, not just a row
                ->withExists(['gambar as ada_foto' => fn ($q) => $q->adaFile()])
                ->orderBy('id')
                ->get()
                ->map(fn (Produk $produk) => [
                    'id' => $produk->id,
                    'nama' => $produk->jenis_produk,
                    'kategori' => $produk->kategori_produk,
                    'kemasan' => trim($produk->kategori_kemasan.' '.$produk->gramasi),
                    'rasa' => $produk->rasa,
                    'tier' => $this->labelTier($produk),
                    'harga' => $this->teksHarga($produk),
                    'target' => $produk->target_pasar,
                    'adaFoto' => (bool) $produk->ada_foto,
                    // the edit form
                    'isian' => [
                        'jenis_produk' => $produk->jenis_produk,
                        'kategori_produk' => (string) $produk->kategori_produk,
                        'brand' => (string) $produk->brand,
                        'kategori_kemasan' => (string) $produk->kategori_kemasan,
                        'gramasi' => (string) $produk->gramasi,
                        'rasa' => (string) $produk->rasa,
                        'target_pasar' => (string) $produk->target_pasar,
                        'deskripsi' => (string) $produk->deskripsi,
                        'satuan' => $produk->harga_bertingkat['satuan'] ?? 'pcs',
                        'tier' => collect($produk->harga_bertingkat['tier'] ?? [])->map(fn (array $t) => [
                            'min' => (string) $t['min'],
                            'max' => isset($t['max']) ? (string) $t['max'] : '',
                            'harga' => isset($t['harga']) ? (string) $t['harga'] : '',
                        ])->all(),
                    ],
                ]),
            'uji' => $uji ? [
                'pertanyaan' => $uji->pertanyaan,
                'jawaban' => $uji->jawaban,
                'catatan' => $uji->catatan,
                'produkId' => $uji->produk_id,
            ] : null,
        ]);
    }

    /**
     * Paket reseller tab (Paket.dc.html).
     */
    public function paket(Request $request): Response
    {
        $bulanIni = [now()->startOfMonth(), now()->endOfMonth()];

        return Inertia::render('katalog/paket', [
            'tab' => TabKatalog::hitungan(),
            // a package just added opens in the editor
            'pilih' => $request->integer('pilih') ?: null,
            // "Tambah produk" in a package suggests the catalog's names
            'namaProduk' => Produk::query()->orderBy('jenis_produk')->pluck('jenis_produk'),
            'paket' => PaketReseller::query()
                ->with('diubahOleh:id,name')
                // "Terjual 14× bulan ini": packages in this month's orders that were not cancelled
                ->withSum(['orderItems as terjual' => fn ($q) => $q->whereHas('order', fn ($o) => $o
                    ->whereBetween('tanggal', $bulanIni)
                    ->whereNotIn('status_order', StatusOrder::batal()))], 'qty')
                ->orderBy('kode_paket')
                ->get()
                ->map(fn (PaketReseller $paket) => [
                    'id' => $paket->id,
                    'kode' => $paket->kode_paket,
                    'nama' => $paket->nama_paket,
                    'isi' => $paket->isi,
                    'isiDetail' => $paket->isi_detail ?? [],
                    'harga' => $paket->harga,
                    'hargaTeks' => $paket->harga === null ? null : Rupiah::teks($paket->harga),
                    'cocok' => $paket->cocok_untuk,
                    'berat' => $paket->berat,
                    'aktif' => $paket->status_data === StatusData::Aktif->value,
                    'tawarkan' => $paket->tawarkan_ketika,
                    'terjual' => (int) $paket->terjual,
                    'diubah' => $paket->updated_at?->translatedFormat('j M'),
                    'diubahOleh' => $paket->diubahOleh?->name,
                ]),
        ]);
    }

    /**
     * "Tambah paket": the table's short "Isi" is made from the items, and can be
     * rewritten later like the others.
     */
    public function tambahPaket(SimpanPaketRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $paket = PaketReseller::create([
            ...$this->isianPaket($request),
            'isi' => collect((array) $request->validated('isi_detail', []))->map(fn ($i) => trim(((array) $i)['nama'].' '.((array) $i)['jumlah']))->implode(' + ') ?: null,
        ]);

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Tambah paket', "{$paket->kode_paket} · {$paket->nama_paket}", $paket);

        Inertia::flash('toast', "Paket {$paket->nama_paket} ditambahkan.");

        return to_route('katalog.paket', ['pilih' => $paket->id]);
    }

    /**
     * Save the package open in the editor.
     */
    public function simpanPaket(SimpanPaketRequest $request, PaketReseller $paket): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $paket->update($this->isianPaket($request));

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah paket', "{$paket->kode_paket} · {$paket->nama_paket}", $paket);

        Inertia::flash('toast', "Paket {$paket->nama_paket} disimpan.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function isianPaket(SimpanPaketRequest $request): array
    {
        return [
            ...$request->safe()->only(['kode_paket', 'nama_paket', 'harga', 'berat']),
            'isi_detail' => array_values($request->validated('isi_detail', [])),
            'tawarkan_ketika' => $request->validated('tawarkan_ketika'),
            'status_data' => ($request->boolean('aktif') ? StatusData::Aktif : StatusData::Nonaktif)->value,
            'diubah_oleh_id' => $request->user()?->id,
        ];
    }

    /**
     * "1–9 · 10–49 · 50+ pcs" from harga_bertingkat {satuan, tier: [{min, max, harga}]}.
     */
    private function labelTier(Produk $produk): ?string
    {
        $tier = $produk->harga_bertingkat['tier'] ?? [];

        if ($tier === []) {
            return null;
        }

        return collect($tier)
            ->map(fn (array $t) => isset($t['max']) ? "{$t['min']}–{$t['max']}" : "{$t['min']}+")
            ->implode(' · ').' '.($produk->harga_bertingkat['satuan'] ?? '');
    }

    /**
     * Price per tier, or the design's placeholder while prices are not filled in.
     */
    private function teksHarga(Produk $produk): ?string
    {
        $tier = collect($produk->harga_bertingkat['tier'] ?? []);

        if ($tier->isEmpty()) {
            return null;
        }

        if ($tier->contains(fn (array $t) => ($t['harga'] ?? null) === null)) {
            return '[harga per tier]';
        }

        return $tier->map(fn (array $t) => Rupiah::teks((int) $t['harga']))->implode(' · ');
    }
}
