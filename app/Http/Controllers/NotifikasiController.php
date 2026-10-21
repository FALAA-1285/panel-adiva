<?php

namespace App\Http\Controllers;

use App\Enums\Peran;
use App\Enums\TabNotifikasi;
use App\Models\Notifikasi;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifikasi (Notifikasi.dc.html): copies of what the workflows sent to the
 * supervisor and the PJ karyawan on WhatsApp.
 */
class NotifikasiController extends Controller
{
    /**
     * Days listed, today included (unread ones are listed whatever their age).
     */
    private const HARI = 7;

    public function index(Request $request): Response
    {
        $tab = TabNotifikasi::tryFrom($request->string('tab')->value()) ?? TabNotifikasi::Semua;
        $rekap = Notifikasi::rekapPerKategori(self::HARI);

        $notifikasi = $tab->saring(Notifikasi::query()->terlihat(self::HARI))
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('notifikasi', [
            'tab' => $tab->value,
            'jumlah' => array_map(fn (TabNotifikasi $t) => [
                'kode' => $t->value,
                'label' => $t->label(),
                'n' => $t->hitung($rekap),
            ], TabNotifikasi::cases()),
            'hari' => $this->perHari($notifikasi),
            // "Bu Sarah · SARAH_WA" in Penerima di WhatsApp
            'pj' => User::pjKaryawan()->name ?? Peran::PjKaryawan->label(),
        ]);
    }

    /**
     * A notification's button: mark it read, then open the page it points to.
     * A GET on purpose: the design uses <a href> (open in new tab keeps working), and
     * marking read is idempotent. Never prefetch these links.
     */
    public function buka(Notifikasi $notifikasi): RedirectResponse
    {
        $tujuan = $notifikasi->jenisDikenal()?->tujuan($notifikasi);

        // types without a button have nothing to open
        abort_if($tujuan === null, 404);

        $notifikasi->tandaiDibaca();

        return redirect($tujuan);
    }

    /**
     * "Tandai semua dibaca", for the whole team.
     */
    public function bacaSemua(): RedirectResponse
    {
        Notifikasi::query()->belumDibaca()->update(['dibaca_at' => now()]);

        Inertia::flash('toast', 'Semua notifikasi ditandai dibaca.');

        return back();
    }

    /**
     * @param  EloquentCollection<int, Notifikasi>  $notifikasi
     * @return Collection<int, array{tanggal: string, judul: string, notifikasi: Collection<int, array{id: int, judul: string, detail: string|null, jam: string, dibaca: bool, aksi: string|null}>}>
     */
    private function perHari(EloquentCollection $notifikasi): Collection
    {
        return $notifikasi
            ->toBase()
            ->groupBy(fn (Notifikasi $n) => $n->waktu->toDateString())
            ->map(fn (Collection $grup, string $tanggal) => [
                'tanggal' => $tanggal,
                'judul' => $this->judulHari(Date::parse($tanggal)),
                'notifikasi' => $grup->map(fn (Notifikasi $n) => [
                    'id' => $n->id,
                    'judul' => $n->judul,
                    'detail' => $n->detail,
                    'jam' => $n->waktu->format('H:i'),
                    'dibaca' => $n->dibaca_at !== null,
                    'aksi' => $n->jenisDikenal()?->aksi(),
                ])->values(),
            ])
            ->values();
    }

    /**
     * "Hari ini", "Kemarin · Minggu", "Jumat, 25 Sep".
     */
    private function judulHari(CarbonInterface $hari): string
    {
        return match (true) {
            $hari->isToday() => 'Hari ini',
            $hari->isYesterday() => 'Kemarin · '.$hari->translatedFormat('l'),
            default => $hari->translatedFormat('l, j M'),
        };
    }
}
