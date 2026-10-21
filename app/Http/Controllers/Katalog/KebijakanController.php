<?php

namespace App\Http\Controllers\Katalog;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\SimpanKebijakanRequest;
use App\Models\Kebijakan;
use App\Models\PemakaianKebijakan;
use App\Models\UjiAdiva;
use App\Support\Aktivitas;
use App\Support\TabKatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kebijakan tab (Kebijakan.dc.html): policies read through the KB_Kebijakan tool.
 */
class KebijakanController extends Controller
{
    public function index(Request $request): Response
    {
        $sejak = now()->subDays(30);
        $uji = UjiAdiva::query()->where('bagian', 'KEBIJAKAN')->latest('id')->first();

        $kebijakan = Kebijakan::query()
            // "Dipakai 64× · 30 hari"
            ->withCount(['pemakaian as dipakai_count' => fn ($q) => $q->where('waktu', '>=', $sejak)])
            ->orderBy('kode')
            ->get();

        // "Pertanyaan customer terbaru yang dijawab dengan kebijakan ini": the latest three per policy
        $terbaru = PemakaianKebijakan::query()
            ->toBase()
            ->select('kebijakan_id', 'pertanyaan', 'waktu')
            ->selectRaw('row_number() over (partition by kebijakan_id order by waktu desc) as urutan')
            ->whereNotNull('pertanyaan');
        $pertanyaan = DB::query()
            ->fromSub($terbaru, 'terbaru')
            ->where('urutan', '<=', 3)
            ->orderBy('urutan')
            ->get()
            ->groupBy('kebijakan_id')
            ->map(fn ($baris) => $baris->pluck('pertanyaan')->all());

        return Inertia::render('katalog/kebijakan', [
            'tab' => TabKatalog::hitungan(),
            // a policy just added opens in the editor
            'pilih' => $request->integer('pilih') ?: null,
            'kebijakan' => $kebijakan->map(fn (Kebijakan $k) => [
                'id' => $k->id,
                'topik' => $k->topik,
                'isi' => $k->jawaban_resmi,
                'kataPemicu' => Str::of((string) $k->kata_pemicu)->explode(',')->map(fn (string $kata) => trim($kata))->filter()->values()->all(),
                'dipakai' => (int) $k->dipakai_count,
                'diubah' => $k->updated_at?->translatedFormat('j M'),
                'pertanyaan' => $pertanyaan->get($k->id, []),
            ]),
            'uji' => $uji ? [
                'pertanyaan' => $uji->pertanyaan,
                'jawaban' => $uji->jawaban,
                'catatan' => $uji->catatan,
                'kebijakanId' => $uji->kebijakan_id,
            ] : null,
        ]);
    }

    /**
     * "Tambah kebijakan": the next KB-nn code, active at once for KB_Kebijakan.
     */
    public function store(SimpanKebijakanRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $terakhir = Kebijakan::query()->pluck('kode')->map(fn (string $kode) => (int) preg_replace('/\D/', '', $kode))->max() ?? 0;
        $kebijakan = Kebijakan::create([
            ...$this->isian($request),
            'kode' => sprintf('KB-%02d', $terakhir + 1),
            'status_data' => StatusData::Aktif->value,
        ]);

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Tambah kebijakan', "{$kebijakan->kode} · {$kebijakan->topik}", $kebijakan);

        Inertia::flash('toast', "Kebijakan {$kebijakan->topik} ditambahkan.");

        return to_route('katalog.kebijakan', ['pilih' => $kebijakan->id]);
    }

    public function update(SimpanKebijakanRequest $request, Kebijakan $kebijakan): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $kebijakan->update($this->isian($request));

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah kebijakan', "{$kebijakan->kode} · {$kebijakan->topik}", $kebijakan);

        Inertia::flash('toast', "Kebijakan {$kebijakan->topik} disimpan.");

        return back();
    }

    /**
     * @return array{topik: mixed, jawaban_resmi: mixed, kata_pemicu: string}
     */
    private function isian(SimpanKebijakanRequest $request): array
    {
        return [
            'topik' => $request->validated('topik'),
            'jawaban_resmi' => $request->validated('jawaban_resmi'),
            // comma separated, like gambar_produk.tags in n8n
            'kata_pemicu' => implode(', ', $request->validated('kata_pemicu', [])),
        ];
    }
}
