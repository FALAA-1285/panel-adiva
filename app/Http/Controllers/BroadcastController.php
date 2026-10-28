<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastPenerima;
use App\Models\Karyawan;
use App\Models\LogOrder;
use App\Support\Aktivitas;
use App\Support\AturanBroadcast;
use App\Support\CsvUnduhan;
use App\Support\NomorWa;
use App\Support\Rupiah;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Broadcast (Broadcast.dc.html): this month's figures, the campaigns, and the report
 * of the one picked.
 */
class BroadcastController extends Controller
{
    /**
     * Finished campaigns stay on the list for a week (like finished orders on Pesanan).
     */
    private const HARI_SELESAI = 7;

    /**
     * The choices of "Filter status", as in the design.
     *
     * @var list<StatusBroadcast>
     */
    private const FILTER = [StatusBroadcast::Draf, StatusBroadcast::Terjadwal, StatusBroadcast::Mengirim, StatusBroadcast::Selesai];

    public function index(Request $request): Response
    {
        $status = StatusBroadcast::tryFrom((string) $request->query('status'));
        $daftar = fn () => $this->daftar($status)->get();

        return Inertia::render('broadcast', [
            'filter' => ['status' => $status->value ?? ''],
            'pilihanStatus' => array_map(fn (StatusBroadcast $s) => ['nilai' => $s->value, 'label' => $s->label()], self::FILTER),
            'angka' => fn () => $this->angka(),
            'aturan' => AturanBroadcast::daftar(),
            'daftar' => fn () => $daftar()->map(fn (Broadcast $b) => $this->baris($b)),
            'laporan' => function () use ($request, $daftar) {
                $broadcast = $request->filled('pilih')
                    ? Broadcast::query()->denganHitungan()->find($request->integer('pilih'))
                    : $daftar()->first();

                return $broadcast ? $this->laporan($broadcast) : null;
            },
        ]);
    }

    /**
     * "Kirim ulang ke yang gagal": the failed recipients go back in the queue and the
     * campaign sends again (this also resumes one that paused itself).
     */
    public function kirimUlang(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $jumlah = $broadcast->penerima()
            ->where('status', BroadcastPenerima::GAGAL)
            ->update(['status' => BroadcastPenerima::MENUNGGU, 'keterangan_gagal' => null]);

        if ($jumlah === 0) {
            throw ValidationException::withMessages(['broadcast' => 'Tidak ada kiriman yang gagal.']);
        }

        $broadcast->update(['status' => StatusBroadcast::Mengirim, 'selesai_at' => null, 'catatan' => null]);
        $broadcast->mulaiKirim();
        Aktivitas::catat($user, KategoriAktivitas::Broadcast, 'Kirim ulang broadcast', "{$broadcast->nama} · {$jumlah} penerima gagal", $broadcast);

        Inertia::flash('toast', "Kirim ulang ke {$jumlah} penerima yang gagal.");

        return back();
    }

    /**
     * "Ekspor penerima": one line per recipient.
     */
    public function ekspor(Broadcast $broadcast): StreamedResponse
    {
        $penerima = $broadcast->penerima()->with(['customer', 'order'])->orderBy('id');

        return CsvUnduhan::buat(
            'broadcast-'.Str::slug($broadcast->nama).'.csv',
            ['Nama', 'Nomor WA', 'Status', 'Terkirim', 'Dibaca', 'Dibalas', 'Balasan', 'Order'],
            $penerima->lazy()->map(fn (BroadcastPenerima $p) => [
                $p->customer?->nama,
                Str::before($p->chat_id, '@'),
                $p->status,
                $p->terkirim_at?->format('Y-m-d H:i'),
                $p->dibaca_at?->format('Y-m-d H:i'),
                $p->dibalas_at?->format('Y-m-d H:i'),
                $p->balasan,
                $p->order?->order_id,
            ]),
        );
    }

    /**
     * The four figures: campaigns started this month.
     *
     * @return list<array{label: string, nilai: string, meta: string}>
     */
    private function angka(): array
    {
        $penerima = BroadcastPenerima::query()
            ->whereHas('broadcast', fn (Builder $q) => $q->whereBetween('mulai_at', [now()->startOfMonth(), now()->endOfMonth()]));

        $hitung = (clone $penerima)->toBase()
            ->selectRaw('count(*) filter (where terkirim_at is not null) as terkirim')
            ->selectRaw('count(*) filter (where status = ?) as gagal', [BroadcastPenerima::GAGAL])
            ->selectRaw('count(*) filter (where dibalas_at is not null) as dibalas')
            ->selectRaw('count(*) filter (where log_order_id is not null) as "order"')
            ->selectRaw('count(*) filter (where minta_stop) as stop')
            ->first();

        $terkirim = (int) ($hitung->terkirim ?? 0);
        $gagal = (int) ($hitung->gagal ?? 0);
        $dibalas = (int) ($hitung->dibalas ?? 0);
        $stop = (int) ($hitung->stop ?? 0);
        $nilai = (int) (clone $penerima)->join('log_order', 'log_order.id', '=', 'broadcast_penerima.log_order_id')
            ->sum(DB::raw(LogOrder::sqlTagihan('log_order')));

        $persen = fn (int $n) => number_format($terkirim > 0 ? $n / $terkirim * 100 : 0, 1, ',', '.').'%';

        return [
            ['label' => 'Terkirim bulan ini', 'nilai' => $this->angkaId($terkirim), 'meta' => 'dari '.$this->angkaId($terkirim + $gagal)." target · {$gagal} gagal"],
            ['label' => 'Tingkat balas', 'nilai' => $persen($dibalas), 'meta' => "{$dibalas} customer membalas"],
            ['label' => 'Order dari broadcast', 'nilai' => (string) ($hitung->order ?? 0), 'meta' => $this->rupiahSingkat($nilai).' · dalam 7 hari setelah kirim'],
            ['label' => 'Opt-out', 'nilai' => $persen($stop), 'meta' => "{$stop} orang balas STOP · otomatis dikecualikan"],
        ];
    }

    /**
     * @return Builder<Broadcast>
     */
    private function daftar(?StatusBroadcast $status): Builder
    {
        return Broadcast::query()
            ->denganHitungan()
            ->where(fn (Builder $q) => $q
                ->where('status', '<>', StatusBroadcast::Selesai)
                ->orWhere('selesai_at', '>=', now()->subDays(self::HARI_SELESAI)))
            ->when($status, fn (Builder $q, StatusBroadcast $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(Broadcast $b): array
    {
        $mengirim = $b->status === StatusBroadcast::Mengirim;
        $progres = $b->jumlah_target > 0 ? intdiv($b->jumlah_terkirim * 100, $b->jumlah_target) : 0;
        $jadwal = $b->jadwal_at ?? $b->mulai_at;

        return [
            'id' => $b->id,
            'nama' => $b->nama,
            'segmen' => $b->segmen_keterangan,
            'target' => $this->angkaId($b->jumlah_target),
            'status' => ['label' => $mengirim ? "{$b->status->label()} {$progres}%" : $b->status->label(), 'nada' => $b->status->nada()],
            'progres' => $mengirim ? $progres : null,
            'hasil' => $b->jumlah_terkirim > 0 ? "{$b->jumlah_dibalas} · {$b->jumlah_order} order" : '—',
            'jadwal' => $jadwal ? ($jadwal->isToday() ? 'Hari ini '.$jadwal->format('H:i') : $jadwal->translatedFormat('j M H:i')) : '—',
        ];
    }

    /**
     * "Hapus" on a draft. A campaign that was sent or scheduled stays as its record.
     */
    public function destroy(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($broadcast->status === StatusBroadcast::Draf, 409, 'Hanya draf yang bisa dihapus.');

        $broadcast->delete();
        Aktivitas::catat($request->user() ?? abort(403), KategoriAktivitas::Broadcast, 'Hapus draf broadcast', $broadcast->nama);
        Inertia::flash('toast', "Draf {$broadcast->nama} dihapus.");

        return to_route('broadcast');
    }

    /**
     * @return array<string, mixed>
     */
    private function laporan(Broadcast $b): array
    {
        $langkah = [
            'Target' => $b->jumlah_target,
            'Terkirim' => $b->jumlah_terkirim,
            'Dibaca' => $b->jumlah_dibaca,
            'Dibalas' => $b->jumlah_dibalas,
            'Order' => $b->jumlah_order,
        ];

        // the latest three replies with a message, oldest of them first (as drawn)
        $balasan = $b->penerima()
            ->whereNotNull('dibalas_at')
            ->whereNotNull('balasan')
            ->with('customer')
            ->orderByDesc('dibalas_at')
            ->limit(3)
            ->get()
            ->reverse()
            ->values();

        // employees are not CRM customers: their name comes from Karyawan
        $karyawan = Karyawan::query()
            ->whereIn('nomor_wa', $balasan->whereNull('crm_customer_id')->map(fn (BroadcastPenerima $p) => Str::before($p->chat_id, '@')))
            ->pluck('nama', 'nomor_wa');

        return [
            'id' => $b->id,
            'nama' => $b->nama,
            'segmen' => $b->segmen_keterangan,
            'corong' => collect($langkah)->map(function (int $n, string $label) use ($b) {
                $persen = $b->jumlah_target > 0 ? (int) round($n / $b->jumlah_target * 100) : 0;

                return [
                    'label' => $label,
                    'n' => $this->angkaId($n),
                    'judul' => "{$label} · {$n} ({$persen}%)",
                    'lebar' => max($persen, $n > 0 ? 2 : 0),
                ];
            })->values(),
            'catatan' => $this->catatan($b),
            'dampak' => ['naik' => $b->jumlah_naik, 'hot' => $b->jumlah_hot, 'stop' => $b->jumlah_stop],
            'balasan' => $balasan->map(fn (BroadcastPenerima $p) => [
                'nama' => $p->customer?->namaTampil() ?? $karyawan[Str::before($p->chat_id, '@')] ?? NomorWa::samarkanKartu($p->chat_id),
                'jam' => $p->dibalas_at?->format('H:i'),
                'teks' => $p->balasan,
            ]),
            'bisaKirimUlang' => $b->jumlah_gagal > 0,
            'draf' => $b->status === StatusBroadcast::Draf,
        ];
    }

    /**
     * What n8n wrote about the campaign, otherwise a line from its numbers.
     */
    private function catatan(Broadcast $b): ?string
    {
        if (filled($b->catatan)) {
            return $b->catatan;
        }

        $jeda = 'jeda acak '.AturanBroadcast::JEDA_MIN_DETIK.'–'.AturanBroadcast::JEDA_MAKS_DETIK.' detik';

        return match ($b->status) {
            StatusBroadcast::Selesai => collect([
                $b->jumlah_gagal > 0 ? "{$b->jumlah_gagal} gagal: ".$b->penerima()->where('status', BroadcastPenerima::GAGAL)->whereNotNull('keterangan_gagal')->distinct()->pluck('keterangan_gagal')->implode(', ').'.' : null,
                $b->mulai_at && $b->selesai_at ? "Pengiriman {$this->jamTitik($b->mulai_at)}–{$this->jamTitik($b->selesai_at)} dengan {$jeda}." : null,
            ])->filter()->implode(' '),
            StatusBroadcast::Mengirim => "Sedang berjalan · {$b->jumlah_menunggu} pesan tersisa · estimasi selesai "
                .now()->addSeconds($b->jumlah_menunggu * AturanBroadcast::jedaRataRataDetik())->format('H:i')
                .' · tetap patuh batas '.AturanBroadcast::BATAS_HARIAN.'/hari.',
            StatusBroadcast::Terjadwal => 'Belum dikirim. Jumlah penerima dihitung ulang saat mulai (yang opt-out atau sedang dijeda dikeluarkan).',
            StatusBroadcast::Draf => 'Draf. Tinjau pesan dan kirim uji ke nomor supervisor sebelum dijadwalkan.',
            StatusBroadcast::Dijeda => null,
        };
    }

    /**
     * "10.00" (Broadcast.dc.html writes the sending window with dots).
     */
    private function jamTitik(CarbonInterface $waktu): string
    {
        return $waktu->format('H.i');
    }

    private function angkaId(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /**
     * 6400000 → "Rp6,4 jt".
     */
    private function rupiahSingkat(int $nilai): string
    {
        return $nilai >= 1_000_000 ? 'Rp'.number_format($nilai / 1_000_000, 1, ',', '.').' jt' : Rupiah::teks($nilai);
    }
}
