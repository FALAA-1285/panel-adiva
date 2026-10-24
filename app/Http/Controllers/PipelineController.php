<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Pesan;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM Pipeline (Pipeline.dc.html): the customers active in the last 30 days, one
 * column per funnel stage, with a few cards each that can be moved by hand.
 */
class PipelineController extends Controller
{
    /**
     * Cards per column; the rest sit behind "Lihat N lainnya".
     */
    private const KARTU = 3;

    /**
     * Only customers active in the last 3 days get a card (like Riwayat on Jeda).
     */
    private const HARI_KARTU = 3;

    /**
     * The column descriptions, as written in the design.
     *
     * @var array<string, string>
     */
    private const DEFINISI = [
        'BARU' => 'Baru menyapa, belum ada kebutuhan jelas',
        'TANYA_PRODUK' => 'Tanya produk, varian, atau rekomendasi',
        'TANYA_HARGA' => 'Sudah tanya harga, paket, atau ongkir',
        'NEGO' => 'Minta harga khusus atau membandingkan',
        'SIAP_ORDER' => 'Pesanan disepakati, kumpulkan alamat/kurir',
        'ORDER_DIBUAT' => 'Data order lengkap, diteruskan ke admin',
        'CART_DITINGGAL' => 'Order tertutup otomatis, disapa sekali (FU-03)',
        'TIDAK_MINAT' => 'Menyatakan tidak jadi',
    ];

    /**
     * Stages where a prospect that stops replying is greeted again (Otomasi: FU-01…05).
     *
     * @var list<TahapFunnel>
     */
    private const TAHAP_PROSPEK = [TahapFunnel::Baru, TahapFunnel::TanyaProduk, TahapFunnel::TanyaHarga, TahapFunnel::Nego, TahapFunnel::SiapOrder];

    /**
     * The latest of the last chat, the latest order, and a move by hand.
     */
    private const AKTIVITAS = 'greatest(tgl_interaksi_terakhir, tahap_manual_at, (select max(log_order.tanggal) from log_order where log_order.crm_customer_id = crm_customer.id))';

    /**
     * Prospects Adiva greets again later today come first in their column.
     */
    private const DISAPA_NANTI = "(tahap_funnel in ('BARU', 'TANYA_PRODUK', 'TANYA_HARGA', 'NEGO', 'SIAP_ORDER') and tgl_followup_berikutnya > ? and tgl_followup_berikutnya <= ?)";

    public function index(Request $request): Response
    {
        return Inertia::render('pipeline', [
            'filter' => [
                'cari' => (string) $request->query('cari'),
                'tipe' => TipeCustomer::tryFrom((string) $request->query('tipe'))->value ?? '',
                'skor' => SkorProspek::tryFrom((string) $request->query('skor'))->value ?? '',
                'admin' => $request->boolean('admin'),
            ],
            'angka' => fn () => $this->angka(),
            'kolom' => fn () => $this->kolom($request, TahapFunnel::tryFrom((string) $request->query('buka'))),
        ]);
    }

    /**
     * "← Mundur" / "Maju →": one stage back or forward, in the column order.
     */
    public function pindah(Request $request, CrmCustomer $customer): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        Gate::authorize('balas');

        $arah = (int) $request->validate(['arah' => ['required', 'integer', Rule::in([-1, 1])]])['arah'];
        $urutan = TahapFunnel::cases();
        $lama = $customer->tahap_funnel;
        $baru = $urutan[(int) array_search($lama, $urutan, true) + $arah] ?? null;

        if ($baru === null) {
            throw ValidationException::withMessages(['arah' => 'Tahap ini sudah di ujung.']);
        }

        $customer->update([
            'tahap_funnel' => $baru,
            'tahap_sejak' => [...$customer->tahap_sejak ?? [], $baru->value => now()->toIso8601String()],
            'tahap_manual_oleh_id' => $user->id,
            'tahap_manual_at' => now(),
        ]);
        $customer->riwayat()->create([
            'waktu' => now(),
            'teks' => "Tahap → {$baru->labelSingkat()} · dipindah manual oleh {$user->name}",
            'nada' => 'blue',
        ]);
        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Pindah tahap', "{$customer->namaTampil()} · {$lama->label()} → {$baru->label()}", $customer);

        Inertia::flash('toast', "{$customer->namaTampil()} dipindah ke {$baru->label()}.");

        return back();
    }

    /**
     * The five figures above the board.
     *
     * @return list<array{label: string, nilai: string}>
     */
    private function angka(): array
    {
        $aktif = CrmCustomer::query()->where('tgl_interaksi_terakhir', '>=', now()->subDays(30));
        $jumlah = (clone $aktif)->count();
        $sampaiOrder = (clone $aktif)->whereNotNull('tahap_sejak->'.TahapFunnel::OrderDibuat->value)->get(['tahap_sejak']);

        // days from the first chat to the order, for those with both times
        $hari = $sampaiOrder
            ->map(fn (CrmCustomer $c) => isset($c->tahap_sejak[TahapFunnel::Baru->value], $c->tahap_sejak[TahapFunnel::OrderDibuat->value])
                ? CarbonImmutable::parse($c->tahap_sejak[TahapFunnel::Baru->value])->diffInMinutes(CarbonImmutable::parse($c->tahap_sejak[TahapFunnel::OrderDibuat->value])) / (60 * 24)
                : null)
            ->filter(fn (?float $h) => $h !== null);

        return [
            ['label' => 'Customer aktif · 30 hari', 'nilai' => number_format($jumlah, 0, ',', '.')],
            ['label' => 'Baru menyapa → order dibuat', 'nilai' => ($jumlah > 0 ? (int) round($sampaiOrder->count() / $jumlah * 100) : 0).'%'],
            ['label' => 'Rata-rata sampai order', 'nilai' => number_format((float) ($hari->avg() ?? 0), 1, ',', '.').' hari'],
            ['label' => 'Disapa ulang hari ini', 'nilai' => (string) Pesan::query()
                ->where('pengirim', PengirimPesan::Adiva)
                ->where('waktu', '>=', now()->startOfDay())
                ->where('meta->otomatis', 'SAPAAN')
                ->count()],
            ['label' => 'Opt-out (tidak dihubungi)', 'nilai' => (string) CrmCustomer::query()->where('status_optout', true)->count()],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kolom(Request $request, ?TahapFunnel $buka): array
    {
        $jumlah = $this->cari($request)->toBase()
            ->select('tahap_funnel')
            ->selectRaw('count(*) as n')
            ->groupBy('tahap_funnel')
            ->pluck('n', 'tahap_funnel');

        $kartu = $this->kartu($request, $buka)->groupBy('tahap');

        return array_map(fn (TahapFunnel $t) => [
            'kode' => $t->value,
            'label' => $t->label(),
            'definisi' => self::DEFINISI[$t->value],
            'n' => (int) ($jumlah[$t->value] ?? 0),
            'kartu' => $kartu->get($t->value, collect())->values(),
        ], TahapFunnel::cases());
    }

    /**
     * Up to three cards per column, active in the last 3 days, latest first; every
     * customer of the opened column.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function kartu(Request $request, ?TahapFunnel $buka): \Illuminate\Support\Collection
    {
        $sekarang = now();
        $dasar = $this->cari($request)
            ->select('crm_customer.*')
            ->selectRaw(self::AKTIVITAS.' as aktivitas_terakhir')
            ->selectRaw(self::DISAPA_NANTI.' as disapa_nanti', [$sekarang, $sekarang->copy()->endOfDay()]);

        /** @var Collection<int, CrmCustomer> $customer */
        $customer = CrmCustomer::query()
            ->fromSub(
                CrmCustomer::query()->fromSub($dasar, 'crm_customer')
                    ->select('crm_customer.*')
                    ->selectRaw('row_number() over (partition by tahap_funnel order by disapa_nanti desc nulls last, aktivitas_terakhir desc, id) as urutan'),
                'crm_customer',
            )
            ->where(fn (Builder $q) => $q
                ->where('urutan', '<=', self::KARTU)
                ->when($buka, fn (Builder $q, TahapFunnel $t) => $q->orWhere('tahap_funnel', $t)))
            ->orderBy('urutan')
            ->with('tahapManualOleh')
            ->get();

        $jumlahOrder = LogOrder::query()->toBase()
            ->whereIn('crm_customer_id', $customer->modelKeys())
            ->whereNotIn('status_order', StatusOrder::batal())
            ->select('crm_customer_id')
            ->selectRaw('count(*) as n')
            ->groupBy('crm_customer_id')
            ->pluck('n', 'crm_customer_id');

        $order = LogOrder::query()
            ->whereIn('crm_customer_id', $customer->modelKeys())
            ->whereIn('status_order', StatusOrder::berjalan())
            ->orderBy('tanggal')
            ->get()
            ->keyBy('crm_customer_id');
        $jeda = JedaBot::query()->menahan()->whereIn('chat_id', $customer->pluck('chat_id'))->orderBy('sejak')->get()->keyBy('chat_id');
        $pj = User::pjKaryawan()->name ?? null;

        return $customer->map(fn (CrmCustomer $c) => $this->isiKartu($c, $order->get($c->id), (int) ($jumlahOrder[$c->id] ?? 0), $jeda->get($c->chat_id), $pj));
    }

    /**
     * @return array<string, mixed>
     */
    private function isiKartu(CrmCustomer $c, ?LogOrder $order, int $jumlahOrder, ?JedaBot $jeda, ?string $pj): array
    {
        $prospek = in_array($c->tahap_funnel, self::TAHAP_PROSPEK, true);
        $disapa = $c->tgl_followup_berikutnya;
        $disapaNanti = $prospek && $disapa !== null && $disapa->isFuture() && $disapa->isToday();
        [$keterangan, $tanda] = $this->keterangan($c, $order, $jumlahOrder, $disapaNanti, $pj);

        $tanda = match (true) {
            $c->butuh_admin => ['teks' => 'Butuh admin', 'nada' => 'amber'],
            $jeda !== null => ['teks' => $jeda->sampai === null ? 'Dijeda tanpa batas' : 'Dijeda s/d '.$this->jam($jeda->sampai), 'nada' => 'gray'],
            $c->tahap_funnel === TahapFunnel::OrderDibuat && $order?->dobel_dengan !== null => ['teks' => 'Kemungkinan dobel', 'nada' => 'amber'],
            $c->status_optout => ['teks' => 'Opt-out', 'nada' => 'red'],
            default => $tanda,
        };

        return [
            'id' => $c->id,
            'tahap' => $c->tahap_funnel->value,
            'nama' => $c->namaTampil(),
            'tipe' => $c->tipe_customer->label(),
            'skor' => $c->skor_prospek->value,
            'minat' => $this->minat($c),
            'keterangan' => $keterangan,
            'tanda' => $tanda,
        ];
    }

    /**
     * "Molreng, Basreng · kantin sekolah", "Basreng · modal Rp800 rb", "Basreng · potong gaji".
     */
    private function minat(CrmCustomer $c): string
    {
        $pelengkap = match (true) {
            filled($c->lokasi_jualan) => Str::lcfirst((string) $c->lokasi_jualan),
            (bool) $c->estimasi_modal => 'modal '.$this->modal((int) $c->estimasi_modal),
            $c->tipe_customer === TipeCustomer::Karyawan => 'potong gaji',
            default => null,
        };

        return collect([$c->minat_produk ?? 'Belum menyebut kebutuhan', $pelengkap])->filter()->implode(' · ');
    }

    /**
     * The line under the interest, and the reminder it may carry.
     *
     * @return array{0: string, 1: array{teks: string, nada: string}|null}
     */
    private function keterangan(CrmCustomer $c, ?LogOrder $order, int $jumlahOrder, bool $disapaNanti, ?string $pj): array
    {
        $baris = fn (?string ...$bagian) => collect($bagian)->filter()->implode(' · ');
        $chat = $c->tgl_interaksi_terakhir;
        $sejak = isset($c->tahap_sejak[$c->tahap_funnel->value]) ? CarbonImmutable::parse($c->tahap_sejak[$c->tahap_funnel->value]) : null;
        $disapa = $c->tgl_followup_berikutnya;

        if ($c->tahap_manual_at !== null && ($chat === null || $c->tahap_manual_at->gte($chat))) {
            $kapan = $c->tahap_manual_at->diffInSeconds(now()) < 60 ? 'baru saja' : Waktu::lalu($c->tahap_manual_at);

            return [$baris('Dipindah manual oleh '.($c->tahapManualOleh->name ?? 'staf'), $kapan), null];
        }

        return match (true) {
            $c->tahap_funnel === TahapFunnel::OrderDibuat && $order !== null => [$baris($order->order_id, match (true) {
                $order->status_order === StatusOrder::Karyawan => $pj ? "ke {$pj}" : null,
                $order->ambil_sendiri => 'ambil sendiri',
                default => Str::lcfirst($order->status_order->label()),
            }), null],
            // "ditutup 09.15 · disapa 19.30"
            $c->tahap_funnel === TahapFunnel::CartDitinggal => [$baris(
                $c->kota,
                $sejak ? 'ditutup '.($sejak->isToday() ? $sejak->format('H.i') : $sejak->translatedFormat('j M')) : null,
                match (true) {
                    $disapa?->isFuture() === true => 'disapa '.($disapa->isToday() ? $disapa->format('H.i') : $disapa->translatedFormat('j M')),
                    $c->jumlah_followup > 0 => 'sudah disapa',
                    default => null,
                },
            ), null],
            $c->tahap_funnel === TahapFunnel::TidakMinat => [$baris($c->kota, ($sejak ?? $chat)?->translatedFormat('j M')), null],
            $disapaNanti && blank($c->kota) => [$baris($chat ? 'Chat '.$this->jam($chat) : null, 'disapa ulang '.$disapa?->format('H:i')), null],
            $disapaNanti => [
                $baris($c->kota, $chat ? 'chat terakhir '.$this->jam($chat) : null),
                ['teks' => 'Disapa ulang '.$disapa?->format('H:i'), 'nada' => 'amber'],
            ],
            in_array($c->tahap_funnel, self::TAHAP_PROSPEK, true) && $c->jumlah_followup > 0 => [$baris($c->kota, 'sudah disapa ulang'), null],
            default => [$baris($c->kota, $chat ? $this->hari($chat) : null), null],
        };
    }

    /**
     * "13:06" today, otherwise "26 Sep 09:14".
     */
    private function jam(CarbonInterface $waktu): string
    {
        return $waktu->isToday() ? $waktu->format('H:i') : $waktu->translatedFormat('j M H:i');
    }

    /**
     * "11:52", "kemarin", "3 hari lalu", then the date.
     */
    private function hari(CarbonInterface $waktu): string
    {
        $hari = (int) $waktu->copy()->startOfDay()->diffInDays(now()->startOfDay());

        return match (true) {
            $hari <= 0 => $waktu->format('H:i'),
            $hari === 1 => 'kemarin',
            $hari < 7 => "{$hari} hari lalu",
            default => $waktu->translatedFormat('j M'),
        };
    }

    /**
     * 800000 → "Rp800 rb", 1500000 → "Rp1,5 jt".
     */
    private function modal(int $nilai): string
    {
        return $nilai >= 1_000_000
            ? 'Rp'.rtrim(rtrim(number_format($nilai / 1_000_000, 1, ',', '.'), '0'), ',').' jt'
            : 'Rp'.number_format(intdiv($nilai, 1000), 0, ',', '.').' rb';
    }

    /**
     * Customers active in the last 30 days, narrowed by the search and the chips.
     *
     * @return Builder<CrmCustomer>
     */
    private function cari(Request $request): Builder
    {
        $cari = trim((string) $request->query('cari'));
        $kata = '%'.addcslashes($cari, '%_\\').'%';

        return CrmCustomer::query()
            ->where('tgl_interaksi_terakhir', '>=', now()->subDays(30))
            ->when($cari !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('nama', 'ilike', $kata)
                ->orWhere('kota', 'ilike', $kata)
                ->orWhere('minat_produk', 'ilike', $kata)))
            ->when(TipeCustomer::tryFrom((string) $request->query('tipe')), fn (Builder $q, TipeCustomer $t) => $q->where('tipe_customer', $t))
            ->when(SkorProspek::tryFrom((string) $request->query('skor')), fn (Builder $q, SkorProspek $s) => $q->where('skor_prospek', $s))
            ->when($request->boolean('admin'), fn (Builder $q) => $q->where('butuh_admin', true));
    }
}
