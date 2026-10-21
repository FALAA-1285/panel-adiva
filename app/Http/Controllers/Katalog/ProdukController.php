<?php

namespace App\Http\Controllers\Katalog;

use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\SimpanProdukRequest;
use App\Models\Produk;
use App\Support\Aktivitas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Katalog: "Tambah produk", editing a product, and "Impor CSV".
 */
class ProdukController extends Controller
{
    /** "Impor CSV": the PRODUK columns, then the unit and the tiered prices. */
    public const KOLOM_CSV = ['jenis_produk', 'kategori_produk', 'brand', 'kategori_kemasan', 'gramasi', 'rasa', 'target_pasar', 'deskripsi', 'satuan', 'harga'];

    public function store(SimpanProdukRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $produk = Produk::create($request->isian());

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Tambah produk', $produk->jenis_produk, $produk);

        Inertia::flash('toast', "Produk {$produk->jenis_produk} ditambahkan.");

        return back();
    }

    public function update(SimpanProdukRequest $request, Produk $produk): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $produk->update($request->isian());

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah produk', $produk->jenis_produk, $produk);

        Inertia::flash('toast', "Produk {$produk->jenis_produk} disimpan.");

        return back();
    }

    /**
     * Rows with a known name update that product, the others are added. Prices are
     * "1-9:15000;10-49:14000;50+:13000".
     */
    public function impor(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [
            'file.required' => 'Pilih file CSV.',
            'file.mimes' => 'File harus berformat CSV.',
        ]);

        $path = (string) $request->file('file')?->getRealPath();
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'File CSV tidak dapat dibaca.']);
        }

        $pertama = fgets($handle);
        if ($pertama === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'File CSV kosong.']);
        }
        $pertama = preg_replace('/^\xEF\xBB\xBF/', '', $pertama) ?? '';
        $pemisah = substr_count($pertama, ';') > substr_count($pertama, ',') ? ';' : ',';
        $kepala = array_map(fn (?string $k) => strtolower(trim((string) $k)), str_getcsv($pertama, $pemisah, escape: ''));

        if (! in_array('jenis_produk', $kepala, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Baris pertama harus berisi nama kolom: '.implode(',', self::KOLOM_CSV).'.']);
        }

        [$tambah, $ubah, $lewati] = [0, 0, []];
        $barisNomor = 1;

        while (($row = fgetcsv($handle, 0, $pemisah, escape: '')) !== false) {
            $barisNomor++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $data = array_combine($kepala, array_pad(array_slice($row, 0, count($kepala)), count($kepala), ''));
            $nama = trim((string) ($data['jenis_produk'] ?? ''));

            if ($nama === '') {
                $lewati[] = "baris {$barisNomor}: nama kosong";

                continue;
            }

            $tier = self::bacaHarga((string) ($data['harga'] ?? ''));

            if ($tier === false) {
                $lewati[] = "baris {$barisNomor}: format harga";

                continue;
            }

            $isian = collect(['kategori_produk', 'brand', 'kategori_kemasan', 'gramasi', 'rasa', 'target_pasar', 'deskripsi'])
                ->mapWithKeys(fn (string $k) => [$k => filled($data[$k] ?? null) ? trim((string) $data[$k]) : null])
                ->all();

            if ($tier !== []) {
                $isian['harga_bertingkat'] = ['satuan' => filled($data['satuan'] ?? null) ? trim((string) $data['satuan']) : 'pcs', 'tier' => $tier];
            }

            $produk = Produk::query()->whereRaw('lower(jenis_produk) = ?', [mb_strtolower($nama)])->first();

            if ($produk) {
                $produk->update(array_filter($isian, fn ($v) => $v !== null));
                $ubah++;
            } else {
                Produk::create(['jenis_produk' => $nama, ...$isian]);
                $tambah++;
            }
        }

        fclose($handle);

        $hasil = collect(["{$tambah} ditambah", "{$ubah} diperbarui", $lewati ? count($lewati).' baris dilewati ('.implode(', ', $lewati).')' : null])->filter()->implode(' · ');
        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Impor produk', $hasil);

        return response()->json(['hasil' => $hasil]);
    }

    /**
     * "1-9:15000;10-49:14000;50+:13000" → tiers; "" → no tiers; false when unreadable.
     *
     * @return list<array{min: int, max: int|null, harga: int|null}>|false
     */
    public static function bacaHarga(string $teks): array|false
    {
        $tier = [];

        foreach (array_filter(array_map('trim', explode(';', $teks))) as $bagian) {
            if (! preg_match('/^(\d+)\s*(?:-\s*(\d+)|(\+))?\s*(?::\s*([\d.]*))?$/', $bagian, $m)) {
                return false;
            }

            $harga = preg_replace('/\D/', '', $m[4] ?? '');
            $tier[] = [
                'min' => (int) $m[1],
                'max' => ($m[2] ?? '') !== '' ? (int) $m[2] : (($m[3] ?? '') === '+' ? null : (int) $m[1]),
                'harga' => $harga === '' || $harga === null ? null : (int) $harga,
            ];
        }

        return $tier;
    }
}
