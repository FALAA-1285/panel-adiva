<?php

namespace App\Http\Controllers\Katalog;

use App\Enums\PengirimPesan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\UbahGambarRequest;
use App\Http\Requests\Katalog\UnggahGambarRequest;
use App\Models\GambarProduk;
use App\Support\TabKatalog;
use App\Support\VarianProduk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gambar produk tab (Gambar.dc.html): photos Adiva may send in a chat.
 */
class GambarController extends Controller
{
    public function index(): Response
    {
        $bulanIni = [now()->startOfMonth(), now()->endOfMonth()];

        return Inertia::render('katalog/gambar', [
            'tab' => TabKatalog::hitungan(),
            'gambar' => GambarProduk::query()
                // "Dikirim 42× bulan ini"
                ->withCount(['pesan as dikirim_count' => fn ($q) => $q
                    ->where('pengirim', PengirimPesan::Adiva)
                    ->whereBetween('waktu', $bulanIni)])
                ->orderBy('id')
                ->get()
                ->map(fn (GambarProduk $gambar) => [
                    'id' => $gambar->id,
                    'judul' => $gambar->title,
                    'tags' => $gambar->tags,
                    'caption' => $gambar->caption,
                    'produkId' => $gambar->produk_id,
                    'aktif' => $gambar->aktif,
                    'adaFile' => $gambar->punyaFile(),
                    'url' => $gambar->image_url,
                    'filename' => $gambar->filename,
                    'lebar' => $gambar->lebar,
                    'tinggi' => $gambar->tinggi,
                    'ukuranKb' => $gambar->ukuran_byte === null ? null : (int) round($gambar->ukuran_byte / 1024),
                    'dikirim' => (int) $gambar->dikirim_count,
                ]),
            'varian' => VarianProduk::semua(),
        ]);
    }

    /**
     * "Unggah foto" in the header: a new photo, product chosen afterwards.
     */
    public function store(UnggahGambarRequest $request): RedirectResponse
    {
        /** @var UploadedFile $foto */
        $foto = $request->file('foto');

        $gambar = GambarProduk::create([
            'title' => pathinfo($foto->getClientOriginalName(), PATHINFO_FILENAME),
            'aktif' => true,
        ]);
        $this->simpanFile($gambar, $foto);

        Inertia::flash('toast', 'Foto diunggah.');

        return back();
    }

    /**
     * Autosave from the detail panel.
     */
    public function update(UbahGambarRequest $request, GambarProduk $gambar): RedirectResponse
    {
        $gambar->update($request->validated());

        Inertia::flash('toast', 'Foto disimpan.');

        return back();
    }

    /**
     * "Ganti foto", or "Unggah foto" on a slot without a file.
     */
    public function gantiFile(UnggahGambarRequest $request, GambarProduk $gambar): RedirectResponse
    {
        /** @var UploadedFile $foto */
        $foto = $request->file('foto');
        $this->simpanFile($gambar, $foto);

        Inertia::flash('toast', 'Foto diganti.');

        return back();
    }

    public function destroy(GambarProduk $gambar): RedirectResponse
    {
        if ($gambar->path) {
            Storage::disk('public')->delete($gambar->path);
        }

        $gambar->delete();

        Inertia::flash('toast', 'Foto dihapus.');

        return back();
    }

    /**
     * Store the uploaded file on the public disk (n8n fetches it by URL) and replace the old one.
     */
    private function simpanFile(GambarProduk $gambar, UploadedFile $foto): void
    {
        $lama = $gambar->path;
        $path = $foto->store('gambar-produk', 'public');
        [$lebar, $tinggi] = getimagesize($foto->getRealPath()) ?: [null, null];

        $gambar->update([
            'filename' => $foto->getClientOriginalName(),
            'path' => $path,
            'image_url' => Storage::disk('public')->url((string) $path),
            'mime_type' => $foto->getMimeType(),
            'lebar' => $lebar,
            'tinggi' => $tinggi,
            'ukuran_byte' => $foto->getSize(),
        ]);

        if ($lama && $lama !== $path) {
            Storage::disk('public')->delete($lama);
        }
    }
}
