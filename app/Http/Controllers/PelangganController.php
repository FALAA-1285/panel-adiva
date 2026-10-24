<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\Riwayat;
use App\Support\Aktivitas;
use App\Support\CsvSanitizer;
use App\Support\Nama;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanToko;
use App\Support\Rupiah;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pelanggan (Customer.dc.html): the CRM list and one customer's profile, orders,
 * automated greetings and history.
 */
class PelangganController extends Controller
{
    /**
     * The list shows the customers with the latest chats; search and the filters
     * reach the rest.
     */
    private const DAFTAR = 10;

    /**
     * Stages where a prospect that stops replying is greeted again (Otomasi: FU-01…05).
     *
     * @var list<TahapFunnel>
     */
    private const TAHAP_PROSPEK = [TahapFunnel::Baru, TahapFunnel::TanyaProduk, TahapFunnel::TanyaHarga, TahapFunnel::Nego, TahapFunnel::SiapOrder];

    public function index(Request $request): Response
    {
        $daftar = fn () => $this->cari($request)->limit(self::DAFTAR)->get();

        return Inertia::render('pelanggan', [
            'filter' => [
                'cari' => (string) $request->query('cari'),
                'tipe' => (string) $request->query('tipe'),
                'tahap' => (string) $request->query('tahap'),
            ],
            'pilihan' => [
                'tipe' => array_map(fn (TipeCustomer $t) => ['nilai' => $t->value, 'label' => $t->label()], TipeCustomer::cases()),
                'tahap' => array_map(fn (TahapFunnel $t) => ['nilai' => $t->value, 'label' => $t->label()], [...TahapFunnel::alur(), TahapFunnel::TidakMinat]),
            ],
            'jumlah' => fn () => $this->jumlah(),
            'daftar' => fn () => $daftar()->map(fn (CrmCustomer $c) => [
                'id' => $c->id,
                'inisial' => Nama::inisialCustomer($c->nama ?? ''),
                'nama' => $c->namaTampil(),
                'skor' => $c->skor_prospek->value,
                'keterangan' => $c->tipe_customer->label().' · '.$c->tahap_funnel->labelSingkat(),
            ]),
            'profil' => function () use ($request, $daftar) {
                $customer = $request->filled('pilih')
                    ? CrmCustomer::query()->find($request->integer('pilih'))
                    : $daftar()->first();

                return $customer ? $this->profil($customer) : null;
            },
        ]);
    }

    /**
     * "Ekspor CSV": the customers matching the search and filters.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $query = $this->cari($request);

        return response()->streamDownload(function () use ($query) {
            $keluar = fopen('php://output', 'w');

            if ($keluar === false) {
                return;
            }

            fwrite($keluar, "\xEF\xBB\xBF");
            fputcsv($keluar, ['Nama', 'Nomor WA', 'Tipe', 'Tahap', 'Skor', 'Kota', 'Interaksi terakhir'], escape: '');

            $query->lazy()->each(function (CrmCustomer $c) use ($keluar) {
                fputcsv($keluar, CsvSanitizer::baris([
                    $c->nama,
                    $c->nomor_wa,
                    $c->tipe_customer->label(),
                    $c->tahap_funnel->label(),
                    $c->skor_prospek->value,
                    $c->kota,
                    $c->tgl_interaksi_terakhir?->format('Y-m-d H:i'),
                ]), escape: '');
            });

            fclose($keluar);
        }, 'pelanggan-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * "Status opt-out": the customer asked not to be contacted by the bot.
     */
    public function optout(Request $request, CrmCustomer $customer): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        Gate::authorize('balas');

        $optout = (bool) $request->validate(['optout' => ['required', 'boolean']])['optout'];

        if ($optout !== $customer->status_optout) {
            $customer->update(['status_optout' => $optout]);
            Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Ubah opt-out', ($customer->nama ?? $customer->chat_id).' · '.($optout ? 'tidak mau dihubungi' : 'boleh dihubungi'), $customer);
        }

        Inertia::flash('toast', $optout ? 'Ditandai tidak mau dihubungi.' : 'Ditandai boleh dihubungi.');

        return back();
    }

    /**
     * "Penilaian Adiva", Ubah: correct what Adiva concluded. A stage change counts as a
     * manual move, like dragging the card on Pipeline.
     */
    public function penilaian(Request $request, CrmCustomer $customer): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $request->merge(['estimasi_modal' => ($angka = preg_replace('/\D/', '', (string) $request->input('estimasi_modal'))) === '' ? null : (int) $angka]);
        $data = $request->validate([
            'tipe_customer' => ['required', Rule::enum(TipeCustomer::class)],
            'tahap_funnel' => ['required', Rule::enum(TahapFunnel::class)],
            'skor_prospek' => ['required', Rule::enum(SkorProspek::class)],
            'kota' => ['nullable', 'string', 'max:100'],
            'minat_produk' => ['nullable', 'string', 'max:255'],
            'lokasi_jualan' => ['nullable', 'string', 'max:255'],
            'estimasi_modal' => ['nullable', 'integer', 'min:0'],
            'butuh_admin' => ['boolean'],
            'alasan_butuh_admin' => ['nullable', 'string', 'max:500'],
        ]);

        $sebelum = $customer->only(array_keys($data));
        $tahapLama = $customer->tahap_funnel;
        $tahapBaru = TahapFunnel::from($data['tahap_funnel']);

        DB::transaction(function () use ($customer, $data, $user, $tahapLama, $tahapBaru) {
            $customer->fill([...$data, 'alasan_butuh_admin' => ($data['butuh_admin'] ?? false) ? ($data['alasan_butuh_admin'] ?? null) : null]);

            if ($tahapBaru !== $tahapLama) {
                $customer->fill([
                    'tahap_sejak' => [...$customer->tahap_sejak ?? [], $tahapBaru->value => now()->toIso8601String()],
                    'tahap_manual_oleh_id' => $user->id,
                    'tahap_manual_at' => now(),
                ]);
                $customer->riwayat()->create(['waktu' => now(), 'teks' => "Tahap → {$tahapBaru->labelSingkat()} · diubah manual oleh {$user->name}", 'nada' => 'blue']);
            }

            $customer->save();
        });

        $berubah = collect($customer->only(array_keys($data)))
            ->filter(fn ($nilai, string $kunci) => $this->teksNilai($nilai) !== $this->teksNilai($sebelum[$kunci] ?? null))
            ->keys()
            ->implode(', ');

        if ($berubah !== '') {
            Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Ubah penilaian', ($customer->nama ?? $customer->chat_id)." · {$berubah}", $customer);
        }

        Inertia::flash('toast', 'Penilaian disimpan.');

        return back();
    }

    /**
     * "Aksi lain" → "Tandai sudah ditangani": the customer no longer needs an admin.
     */
    public function tangani(Request $request, CrmCustomer $customer): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $customer->update(['butuh_admin' => false, 'alasan_butuh_admin' => null]);

        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Tandai sudah ditangani', $customer->nama ?? $customer->chat_id, $customer);

        Inertia::flash('toast', 'Ditandai sudah ditangani.');

        return back();
    }

    /**
     * "Catatan tetap", Ubah (Percakapan): written by admins, never overwritten by the
     * system, read by Adiva on every reply.
     */
    public function catatan(Request $request, CrmCustomer $customer): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $catatan = $request->validate(['catatan_tetap' => ['nullable', 'string', 'max:2000']])['catatan_tetap'] ?? null;

        $customer->update(['catatan_tetap' => filled($catatan) ? trim((string) $catatan) : null]);
        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Ubah catatan tetap', $customer->nama ?? $customer->chat_id, $customer);

        Inertia::flash('toast', 'Catatan tetap disimpan.');

        return back();
    }

    private function teksNilai(mixed $nilai): string
    {
        return $nilai instanceof \BackedEnum ? (string) $nilai->value : (string) json_encode($nilai);
    }

    /**
     * @return Builder<CrmCustomer>
     */
    private function cari(Request $request): Builder
    {
        $cari = trim((string) $request->query('cari'));
        $kata = '%'.addcslashes($cari, '%_\\').'%';
        $angka = preg_replace('/\D/', '', $cari) ?? '';

        return CrmCustomer::query()
            ->when($cari !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('nama', 'ilike', $kata)
                ->orWhere('kota', 'ilike', $kata)
                ->when(strlen($angka) >= 3, fn (Builder $q) => $q->orWhere('nomor_wa', 'like', '%'.ltrim($angka, '0').'%'))))
            ->when(TipeCustomer::tryFrom((string) $request->query('tipe')), fn (Builder $q, TipeCustomer $t) => $q->where('tipe_customer', $t))
            ->when(TahapFunnel::tryFrom((string) $request->query('tahap')), fn (Builder $q, TahapFunnel $t) => $q->where('tahap_funnel', $t))
            ->orderByDesc('tgl_interaksi_terakhir')
            ->orderBy('id');
    }

    /**
     * "312 pelanggan · 205 aktif 30 hari".
     */
    private function jumlah(): string
    {
        $angka = CrmCustomer::query()->toBase()
            ->selectRaw('count(*) as semua, count(*) filter (where tgl_interaksi_terakhir >= ?) as aktif', [now()->subDays(30)])
            ->first();

        return number_format((int) ($angka->semua ?? 0), 0, ',', '.').' pelanggan · '.number_format((int) ($angka->aktif ?? 0), 0, ',', '.').' aktif 30 hari';
    }

    /**
     * @return array<string, mixed>
     */
    private function profil(CrmCustomer $c): array
    {
        $dijeda = JedaBot::query()->menahan()->where('chat_id', $c->chat_id)->exists();
        $config = PengaturanToko::nilaiBanyak(['JEDA_FOLLOWUP_JAM', 'JEDA_KESAN_HARI']);
        $sejak = isset($c->tahap_sejak[TahapFunnel::Baru->value]) ? CarbonImmutable::parse($c->tahap_sejak[TahapFunnel::Baru->value]) : $c->created_at;

        return [
            'id' => $c->id,
            'inisial' => Nama::inisialCustomer($c->nama ?? ''),
            'nama' => $c->namaTampil(),
            'tipe' => $c->tipe_customer->label(),
            'skor' => $c->skor_prospek->value,
            'nomor' => $c->nomor_wa ? NomorWa::samarkanUjung($c->nomor_wa) : null,
            'kota' => $c->kota,
            'sejak' => $sejak ? 'Pelanggan sejak '.$sejak->translatedFormat('j M Y') : null,
            // "Dijeda" is not in the design, which only draws a customer Adiva answers
            'status' => $dijeda ? ['label' => 'Dijeda', 'nada' => 'amber'] : ['label' => 'Dijawab Adiva', 'nada' => 'green'],
            'langkah' => $this->langkah($c),
            'penilaian' => [
                ['Tipe', $c->tipe_customer->label()],
                ['Tahap', $c->tahap_funnel->label()],
                ['Skor prospek', $c->skor_prospek->value],
                ['Minat produk', $c->minat_produk ?? '—'],
                ['Lokasi jualan', $c->lokasi_jualan ?? '—'],
                ['Estimasi modal', $c->estimasi_modal ? Rupiah::teks($c->estimasi_modal) : '—'],
                ['Kota', $c->kota ?? '—'],
                ['Butuh admin', $c->butuh_admin ? 'Ya' : 'Tidak'],
                ['Interaksi terakhir', $c->tgl_interaksi_terakhir?->translatedFormat('j M H:i') ?? '—'],
                ['Pesan terakhir', $c->pesan_terakhir_customer ?? '—'],
                ['ID chat', $this->samarkanChat($c->chat_id)],
                ['Sesi ingatan', 'ke-'.$c->mem_gen],
                ['Nomor bot', NomorBot::daftar()[$c->sesi_wa ?? ''] ?? $c->sesi_wa ?? '—'],
                ['Jumlah sapaan ulang', (string) $c->jumlah_followup],
            ],
            // the "Penilaian Adiva" edit form and the "Aksi lain" menu
            'isian' => [
                'tipe_customer' => $c->tipe_customer->value,
                'tahap_funnel' => $c->tahap_funnel->value,
                'skor_prospek' => $c->skor_prospek->value,
                'kota' => (string) $c->kota,
                'minat_produk' => (string) $c->minat_produk,
                'lokasi_jualan' => (string) $c->lokasi_jualan,
                'estimasi_modal' => $c->estimasi_modal ? (string) $c->estimasi_modal : '',
                'butuh_admin' => (bool) $c->butuh_admin,
                'alasan_butuh_admin' => (string) $c->alasan_butuh_admin,
            ],
            'jedaId' => JedaBot::query()->menahan()->where('chat_id', $c->chat_id)->value('id'),
            'nomorWa' => $c->nomor_wa,
            'ringkasan' => $c->ringkasan_terakhir,
            'catatanTetap' => $c->catatan_tetap,
            'catatanSistem' => $c->catatan,
            'pesanan' => $this->pesanan($c),
            'sapaan' => [
                'prospek' => in_array($c->tahap_funnel, self::TAHAP_PROSPEK, true) && ! $c->status_optout && ! $dijeda
                    ? "{$config['JEDA_FOLLOWUP_JAM']} jam setelah chat terakhir"
                    : 'Tidak berlaku',
                'kesan' => "{$config['JEDA_KESAN_HARI']} hari usai SELESAI",
                'disapa' => "{$c->jumlah_followup} kali",
                'jedaJam' => $config['JEDA_FOLLOWUP_JAM'],
                'optout' => $c->status_optout,
            ],
            'aktivitas' => $c->riwayat()->orderByDesc('waktu')->orderByDesc('id')->get()->map(fn (Riwayat $r) => [
                'waktu' => $r->waktuSingkat(),
                'teks' => $r->teks,
                'nada' => $r->nada,
            ]),
        ];
    }

    /**
     * The seven bars: reached with its time, skipped ("dilewati"), or not yet ("—").
     *
     * @return list<array{label: string, waktu: string, selesai: bool, dilewati: bool, kini: bool}>
     */
    private function langkah(CrmCustomer $c): array
    {
        $alur = TahapFunnel::alur();
        $kini = array_search($c->tahap_funnel, $alur, true);
        $sebelumnya = null;
        $langkah = [];

        foreach ($alur as $i => $tahap) {
            $waktu = isset($c->tahap_sejak[$tahap->value]) ? CarbonImmutable::parse($c->tahap_sejak[$tahap->value]) : null;
            $sudah = $kini !== false && $i <= $kini;

            $langkah[] = [
                'label' => $tahap->labelSingkat(),
                // the first time with its date, later ones on the same day only the hour
                'waktu' => match (true) {
                    $waktu !== null => $sebelumnya?->isSameDay($waktu) ? $waktu->format('H:i') : $waktu->translatedFormat('j M H:i'),
                    $sudah => 'dilewati',
                    default => '—',
                },
                'selesai' => $sudah && $waktu !== null,
                'dilewati' => $sudah && $waktu === null,
                'kini' => $i === $kini,
            ];
            $sebelumnya = $waktu ?? $sebelumnya;
        }

        return $langkah;
    }

    /**
     * @return array<string, mixed>
     */
    private function pesanan(CrmCustomer $c): array
    {
        $order = LogOrder::query()->where('crm_customer_id', $c->id)->with('items')->latest('tanggal')->get();
        $nilai = $order->sum(fn (LogOrder $o) => $o->total_produk + $o->ongkir);
        $terakhir = $order->first();

        return [
            'jumlah' => $order->count(),
            'nilai' => $nilai >= 1_000_000 ? 'Rp'.number_format($nilai / 1_000_000, 2, ',', '.').' jt' : Rupiah::teks($nilai),
            'lunas' => Rupiah::teks($order->filter(fn (LogOrder $o) => $o->lunas_at !== null && ! in_array($o->status_order, StatusOrder::batal(), true))
                ->sum(fn (LogOrder $o) => $o->total_produk + $o->ongkir)),
            'terakhir' => $terakhir ? [
                'id' => $terakhir->order_id,
                // "Molreng ×40, Basreng ×30 · JNE REG"
                'ringkas' => collect([
                    $terakhir->items->map(fn (OrderItem $i) => strtok($i->nama_produk, ' ')." ×{$i->qty}")->implode(', '),
                    $terakhir->kurir(),
                ])->filter()->implode(' · '),
                'total' => Rupiah::teks($terakhir->total_produk + $terakhir->ongkir),
                'status' => ['label' => $terakhir->status_order->label(), 'nada' => $terakhir->status_order->nada()],
            ] : null,
        ];
    }

    /**
     * "6281233450081@c.us" → "62812334••81@c.us".
     */
    private function samarkanChat(string $chatId): string
    {
        $parts = explode('@', $chatId, 2);
        $nomor = $parts[0];
        $domain = $parts[1] ?? null;

        if (strlen($nomor) < 11) {
            return $chatId;
        }

        return substr($nomor, 0, 8).'••'.substr($nomor, -2).($domain ? "@{$domain}" : '');
    }
}
