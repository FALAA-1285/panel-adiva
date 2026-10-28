<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\ConfigToko;
use App\Models\User;
use App\Support\CsvUnduhan;
use App\Support\KunciPengaturan;
use App\Support\PengaturanToko;
use App\Support\Perangkat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log aktivitas (Aktivitas.dc.html): every important change and action, from the
 * panel, WhatsApp commands, Adiva and the system.
 */
class AktivitasController extends Controller
{
    /**
     * Days shown, today included.
     */
    private const HARI = 7;

    public function index(Request $request): Response
    {
        $filter = $this->filter($request);
        $log = $this->cari($filter)
            ->where('waktu', '>=', now()->startOfDay()->subDays(self::HARI - 1))
            ->with('user:id,name,peran')
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->get();

        $nilaiSekarang = $this->nilaiSekarang($log);
        $bolehKembalikan = Gate::allows('pengaturan');
        $kejadian = $log->map(fn (ActivityLog $baris) => $this->kejadian($baris, $nilaiSekarang, $bolehKembalikan));

        $hari = $kejadian->groupBy('tanggal')->map(fn (Collection $grup, string $tanggal) => [
            'tanggal' => $tanggal,
            'judul' => $this->judulHari(Date::parse($tanggal)),
            'kejadian' => $grup->map(fn (array $k) => collect($k)->except('tanggal')->all())->values(),
        ]);

        // Today always has a panel, even when nothing matches
        if (! $hari->has(now()->toDateString())) {
            $hari->prepend(['tanggal' => now()->toDateString(), 'judul' => $this->judulHari(now()), 'kejadian' => collect()], now()->toDateString());
        }

        return Inertia::render('aktivitas', [
            'hari' => $hari->values(),
            // the latest change that can still be put back, else the newest line
            'pilih' => ($kejadian->firstWhere('bisaDikembalikan', true) ?? $kejadian->first())['id'] ?? null,
            'filter' => $filter,
            'orang' => [
                ...User::query()->whereNull('nonaktif_at')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (User $user) => ['nilai' => (string) $user->id, 'label' => $user->name]),
                ['nilai' => 'adiva', 'label' => 'Adiva'],
                ['nilai' => 'sistem', 'label' => 'Sistem'],
            ],
            'kategori' => array_map(
                fn (KategoriAktivitas $k) => ['nilai' => $k->value, 'label' => $k->label()],
                KategoriAktivitas::pilihanFilter(),
            ),
        ]);
    }

    /**
     * "Ekspor CSV": everything that matches the filters, not only the last days.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $query = $this->cari($this->filter($request))->orderByDesc('waktu')->orderByDesc('id');

        return CsvUnduhan::buat(
            'log-aktivitas-'.now()->format('Y-m-d').'.csv',
            ['Waktu', 'Oleh', 'Aksi', 'Detail', 'Sumber'],
            $query->lazy()->map(fn (ActivityLog $baris) => [
                $baris->waktu->format('Y-m-d H:i'),
                $baris->pelaku,
                $baris->aksi,
                $baris->detail,
                $baris->sumber->label(),
            ]),
        );
    }

    /**
     * "Kembalikan ke 4 jam kerja": put a setting back to its value before this change.
     */
    public function kembalikan(Request $request, ActivityLog $log): RedirectResponse
    {
        Gate::authorize('pengaturan');

        $kunci = $log->data['kunci'] ?? null;
        $sebelum = $log->data['sebelum'] ?? null;

        abort_unless(
            $log->kategori === KategoriAktivitas::Pengaturan->value && is_string($kunci) && is_string($sebelum)
                && PengaturanToko::nilai($kunci) === ($log->data['sesudah'] ?? null),
            409,
        );

        PengaturanToko::ubah($kunci, $sebelum, $request->user() ?? abort(403), Perangkat::dari($request->userAgent()));

        Inertia::flash('toast', 'Pengaturan dikembalikan.');

        return back();
    }

    /**
     * Filters from the query string; unknown values are ignored.
     *
     * @return array{q: string, oleh: string, kategori: string, sumber: string}
     */
    private function filter(Request $request): array
    {
        $oleh = $request->string('oleh')->value();
        $kategori = $request->string('kategori')->value();
        $sumber = $request->string('sumber')->value();

        return [
            'q' => $request->string('q')->trim()->limit(100, '')->value(),
            'oleh' => in_array($oleh, ['adiva', 'sistem'], true) || ctype_digit($oleh) ? $oleh : '',
            'kategori' => in_array(KategoriAktivitas::tryFrom($kategori), KategoriAktivitas::pilihanFilter(), true) ? $kategori : '',
            'sumber' => SumberAktivitas::tryFrom($sumber) ? $sumber : '',
        ];
    }

    /**
     * @param  array{q: string, oleh: string, kategori: string, sumber: string}  $filter
     * @return Builder<ActivityLog>
     */
    private function cari(array $filter): Builder
    {
        return ActivityLog::query()
            ->when($filter['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $cari) => $cari
                ->whereLike('aksi', "%{$filter['q']}%")
                ->orWhereLike('detail', "%{$filter['q']}%")
                ->orWhereLike('pelaku', "%{$filter['q']}%")))
            ->when($filter['oleh'] === 'adiva', fn (Builder $q) => $q->whereNull('user_id')->where('sumber', SumberAktivitas::Adiva))
            ->when($filter['oleh'] === 'sistem', fn (Builder $q) => $q->whereNull('user_id')->where('sumber', SumberAktivitas::Sistem))
            ->when(ctype_digit($filter['oleh']), fn (Builder $q) => $q->where('user_id', (int) $filter['oleh']))
            ->when($filter['kategori'] !== '', fn (Builder $q) => $q->where('kategori', $filter['kategori']))
            ->when($filter['sumber'] !== '', fn (Builder $q) => $q->where('sumber', $filter['sumber']));
    }

    /**
     * Current values of the settings changed in these lines.
     *
     * @param  Collection<int, ActivityLog>  $log
     * @return array<string, string|null>
     */
    private function nilaiSekarang(Collection $log): array
    {
        $kunci = $log->pluck('data.kunci')->filter()->unique()->values();
        $tersimpan = ConfigToko::query()->whereIn('kunci', $kunci)->pluck('nilai', 'kunci');

        return $kunci->mapWithKeys(fn (string $k) => [$k => $tersimpan[$k] ?? KunciPengaturan::bawaan($k)])->all();
    }

    /**
     * One line of the table plus what the side panel shows when it is selected.
     *
     * @param  array<string, string|null>  $nilaiSekarang
     * @return array<string, mixed>
     */
    private function kejadian(ActivityLog $baris, array $nilaiSekarang, bool $bolehKembalikan): array
    {
        $kunci = $baris->kategori === KategoriAktivitas::Pengaturan->value ? ($baris->data['kunci'] ?? null) : null;
        $sebelum = $baris->data['sebelum'] ?? null;
        $sesudah = $baris->data['sesudah'] ?? null;
        $bisaDikembalikan = is_string($kunci) && is_string($sebelum) && is_string($sesudah)
            && ($nilaiSekarang[$kunci] ?? null) === $sesudah;
        $perangkat = $baris->data['perangkat'] ?? null;

        return [
            'id' => $baris->id,
            'tanggal' => $baris->waktu->toDateString(),
            'jam' => $baris->waktu->format('H:i'),
            'oleh' => $baris->pelaku,
            'aksi' => $baris->aksi,
            'detail' => $baris->detail,
            'sumber' => ['label' => $baris->sumber->label(), 'nada' => $baris->sumber->nada()],
            'bisaDikembalikan' => $bisaDikembalikan,
            'rincian' => [
                'judul' => $baris->aksi,
                'sub' => is_string($kunci) ? KunciPengaturan::judul($kunci) : $baris->detail,
                // "Dina · Staf CS"
                'oleh' => collect([$baris->pelaku, $baris->user?->peran->label()])->filter()->implode(' · '),
                'waktu' => $baris->waktu->translatedFormat('j M Y, H:i'),
                // "Panel · Chrome, Android"
                'sumber' => collect([$baris->sumber->label(), is_string($perangkat) ? str_replace(' · ', ', ', $perangkat) : null])->filter()->implode(' · '),
                'kunci' => $kunci,
                'sebelum' => is_string($kunci) && is_string($sebelum) ? KunciPengaturan::teks($kunci, $sebelum) : null,
                'sesudah' => is_string($kunci) && is_string($sesudah) ? KunciPengaturan::teks($kunci, $sesudah) : null,
                'dampak' => is_string($kunci) ? KunciPengaturan::dampak($kunci) : null,
                'kembalikan' => $bisaDikembalikan && $bolehKembalikan ? 'Kembalikan ke '.KunciPengaturan::teks($kunci, $sebelum) : null,
            ],
        ];
    }

    /**
     * "Hari ini · 28 Sep", "Kemarin · 27 Sep", "Jumat · 25 Sep".
     */
    private function judulHari(CarbonInterface $hari): string
    {
        $nama = match (true) {
            $hari->isToday() => 'Hari ini',
            $hari->isYesterday() => 'Kemarin',
            default => $hari->translatedFormat('l'),
        };

        return $nama.' · '.$hari->translatedFormat('j M');
    }
}
