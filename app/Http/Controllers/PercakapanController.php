<?php

namespace App\Http\Controllers;

use App\Bot\JawabAdiva;
use App\Bot\PerintahGagal;
use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Http\Requests\JedaRequest;
use App\Jobs\KirimPesanWa;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Pesan;
use App\Models\TemplateBalasan;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\Nama;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanToko;
use App\Support\Rupiah;
use App\Support\Waktu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Percakapan (Inbox.dc.html): the chats, the selected thread with Adiva's and the
 * admins' replies, and what Adiva knows about the customer.
 */
class PercakapanController extends Controller
{
    /**
     * Stages where a quiet prospect is greeted again (Otomasi: FU-01…05).
     *
     * @var list<TahapFunnel>
     */
    private const TAHAP_PROSPEK = [TahapFunnel::Baru, TahapFunnel::TanyaProduk, TahapFunnel::TanyaHarga, TahapFunnel::Nego, TahapFunnel::SiapOrder];

    /**
     * "Membalas manual akan menjeda Adiva untuk customer ini selama 2 jam."
     */
    private const JEDA_BALAS = '2 jam';

    /**
     * @var list<string>
     */
    private const TAB = ['semua', 'belum-dibalas', 'butuh-admin', 'dijeda'];

    /**
     * How many chats the list loads at once. Everything older is reached by searching;
     * without a ceiling the page builds a row for every customer who ever wrote.
     */
    private const BATAS = 200;

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TAB, true) ? (string) $request->query('tab') : 'semua';
        $tipe = TipeCustomer::tryFrom((string) $request->query('tipe'));
        $jeda = JedaBot::query()->menahan()->orderBy('sejak')->get()->keyBy('chat_id');
        $menunggu = $this->belumDibalas();
        $daftar = fn () => $this->daftar($request, $tab, $tipe, $jeda, $menunggu);

        return Inertia::render('percakapan', [
            'hanyaLihat' => self::hanyaLihat(),
            'filter' => ['cari' => (string) $request->query('cari'), 'tab' => $tab, 'tipe' => $tipe?->value],
            'jumlah' => fn () => $this->jumlah($tab, $tipe, $jeda, $menunggu),
            'muat' => function () use ($request, $tab, $tipe, $jeda, $menunggu) {
                $total = $this->kueri($request, $tab, $tipe, $jeda, $menunggu)->count();

                return ['batas' => self::BATAS, 'tampil' => min(self::BATAS, $total), 'total' => $total];
            },
            'daftar' => fn () => $this->barisDaftar($daftar(), $jeda, $menunggu),
            // the "Template" and "Lampirkan gambar produk" menus by the reply box
            'template' => fn () => TemplateBalasan::query()->latest('id')->get(['id', 'teks']),
            'gambar' => fn () => GambarProduk::query()->adaFile()->orderBy('title')->get(['id', 'title'])
                ->map(fn (GambarProduk $g) => ['id' => $g->id, 'judul' => $g->title]),
            'percakapan' => function () use ($request, $daftar, $jeda) {
                // nothing picked: the thread pane shows its empty state, as in the design
                $customer = $request->filled('pilih')
                    ? CrmCustomer::query()->find($request->integer('pilih'))
                    : $daftar()->first();

                return $customer ? $this->percakapan($customer, $jeda->get($customer->chat_id)) : null;
            },
        ]);
    }

    /**
     * The chats come from the chatbot that runs on its own in n8n (N8N_SINKRON_CHAT): it reads
     * neither the panel's pauses nor its memory, so the page only shows them.
     */
    public static function hanyaLihat(): bool
    {
        return (bool) config('services.n8n.sinkron_chat');
    }

    private static function tolakBilaHanyaLihat(): void
    {
        if (self::hanyaLihat()) {
            throw ValidationException::withMessages(['teks' => 'Chat ini dijawab chatbot utama di n8n. Panel hanya menampilkan.']);
        }
    }

    /**
     * Kirim: an admin's reply, sent through WAHA from the customer's bot number by a
     * queued job. A chat Adiva still answers is paused for 2 hours so the bot does not
     * cut in.
     */
    public function balas(Request $request, CrmCustomer $customer): RedirectResponse
    {
        self::tolakBilaHanyaLihat();
        $user = $request->user() ?? abort(403);
        $data = $request->validate([
            'teks' => ['required_without:gambar', 'nullable', 'string', 'max:4096'],
            // "Lampirkan gambar produk": a photo with a file; the text becomes its caption
            'gambar' => ['nullable', 'integer', Rule::exists('gambar_produk', 'id')->where(fn ($q) => $q->whereNotNull('filename')->orWhereNotNull('image_url'))],
        ], ['teks.required_without' => 'Tulis balasannya dulu.', 'gambar.exists' => 'Foto ini belum punya file.']);
        $teks = (string) ($data['teks'] ?? '');
        $gambar = isset($data['gambar']) ? GambarProduk::query()->whereKey($data['gambar'])->first() : null;

        $pesan = Pesan::create([
            'crm_customer_id' => $customer->id,
            'chat_id' => $customer->chat_id,
            'sesi_wa' => $customer->sesi_wa,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Admin,
            'user_id' => $user->id,
            'teks' => $teks === '' ? null : $teks,
            'gambar_produk_id' => $gambar?->id,
            'lampiran_keterangan' => $gambar ? "Foto produk · {$gambar->title}" : null,
            'status_kirim' => 'MENUNGGU',
        ]);

        if (! JedaBot::query()->menahan()->where('chat_id', $customer->chat_id)->exists()) {
            // a pause that ran out may still be open: close it, or there would be two
            JedaBot::akhiriKedaluwarsa((string) ($customer->nomor_wa ?: $customer->chat_id), $customer->chat_id);

            JedaBot::create([
                'crm_customer_id' => $customer->id,
                'nomor' => $customer->nomor_wa,
                'chat_id' => $customer->chat_id,
                'nama' => $customer->nama,
                'sesi_wa' => $customer->sesi_wa,
                'sejak' => now(),
                'sampai' => now()->addMinutes((int) JedaRequest::DURASI[self::JEDA_BALAS]),
                'oleh' => $user->name,
                'alasan' => 'Balas manual dari panel',
            ]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Balas manual', collect([
            $customer->namaTampil(),
            $teks !== '' ? Str::limit($teks, 60) : null,
            $gambar ? "foto {$gambar->title}" : null,
        ])->filter()->implode(' · '), $customer);
        KirimPesanWa::dispatch($pesan);

        Inertia::flash('toast', 'Balasan dikirim ke '.$customer->namaTampil().'.');

        return back();
    }

    /**
     * "Template" → "Simpan teks ini sebagai template". The same text is kept once.
     */
    public function simpanTemplate(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $teks = trim((string) $request->validate(['teks' => ['required', 'string', 'max:1000']], ['teks.required' => 'Tulis balasannya dulu.'])['teks']);

        $template = TemplateBalasan::query()->firstOrCreate(['teks' => $teks], ['user_id' => $user->id]);

        if ($template->wasRecentlyCreated) {
            Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Simpan template balasan', Str::limit($teks, 60));
        }

        Inertia::flash('toast', 'Template balasan disimpan.');

        return back();
    }

    public function hapusTemplate(Request $request, TemplateBalasan $template): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $template->delete();

        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Hapus template balasan', Str::limit($template->teks, 60));

        // the page says it itself, with "Urungkan"
        Inertia::flash('toast', false);

        return back();
    }

    /**
     * "Kirim ulang": a line WhatsApp did not take goes back in the queue.
     */
    public function kirimUlang(Request $request, Pesan $pesan): RedirectResponse
    {
        self::tolakBilaHanyaLihat();
        $user = $request->user() ?? abort(403);

        if ($pesan->status_kirim !== 'GAGAL') {
            throw ValidationException::withMessages(['pesan' => 'Pesan ini tidak gagal terkirim.']);
        }

        $pesan->update(['status_kirim' => 'MENUNGGU']);
        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Kirim ulang pesan', ($pesan->customer?->namaTampil() ?? $pesan->chat_id).' · '.Str::limit((string) $pesan->teks, 60), $pesan->customer);
        KirimPesanWa::dispatch($pesan);

        Inertia::flash('toast', 'Pesan dikirim ulang.');

        return back();
    }

    /**
     * "Reset memori chat": Adiva starts a new memory session (mem_gen + 1).
     */
    public function resetMemori(Request $request, CrmCustomer $customer): RedirectResponse
    {
        self::tolakBilaHanyaLihat();
        $user = $request->user() ?? abort(403);
        $customer->increment('mem_gen');

        Pesan::create([
            'crm_customer_id' => $customer->id,
            'chat_id' => $customer->chat_id,
            'sesi_wa' => $customer->sesi_wa,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Sistem,
            'teks' => "Memori chat direset oleh {$user->name} · sesi ingatan ke-{$customer->mem_gen}",
        ]);
        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Reset memori chat', $customer->namaTampil()." · sesi ke-{$customer->mem_gen}", $customer);

        Inertia::flash('toast', 'Memori chat '.$customer->namaTampil().' direset.');

        return back();
    }

    /**
     * The chats, latest message first. "Semua" is everyone who has written to the
     * bot; the other tabs list every customer waiting for an admin or paused.
     *
     * @param  SupportCollection<string, JedaBot>  $jeda
     * @return Collection<int, CrmCustomer>
     */
    /**
     * How many customer messages at the end of each chat nobody has answered yet. It is
     * the closest thing the panel has to "unread": Adiva marks messages read in WhatsApp,
     * so an unanswered tail is what actually waits for someone.
     *
     * @return SupportCollection<int, int>
     */
    private function belumDibalas(): SupportCollection
    {
        return Pesan::query()->toBase()
            ->where('pengirim', PengirimPesan::Customer->value)
            // only a real reply ends the wait: a SISTEM note ("Dijeda…") answers nobody
            ->whereRaw('waktu > coalesce((select max(q.waktu) from pesan q where q.crm_customer_id = pesan.crm_customer_id and q.pengirim in (?, ?)), to_timestamp(0))', [PengirimPesan::Adiva->value, PengirimPesan::Admin->value])
            ->whereNotNull('crm_customer_id')
            ->groupBy('crm_customer_id')
            ->selectRaw('crm_customer_id, count(*) as n')
            ->pluck('n', 'crm_customer_id')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * The chats the filters leave, newest message first.
     *
     * @param  SupportCollection<string, JedaBot>  $jeda
     * @param  SupportCollection<int, int>  $menunggu
     * @return Builder<CrmCustomer>
     */
    private function kueri(Request $request, string $tab, ?TipeCustomer $tipe, SupportCollection $jeda, SupportCollection $menunggu): Builder
    {
        $cari = trim((string) $request->query('cari'));
        $kata = '%'.addcslashes($cari, '%_\\').'%';
        $angka = preg_replace('/\D/', '', $cari) ?? '';

        return CrmCustomer::query()
            // "Semua" is the chats and anyone flagged or paused
            ->when($tab === 'semua', fn (Builder $q) => $q->where(fn (Builder $sq) => $sq
                ->whereHas('pesan', fn ($q) => $q->where('pengirim', PengirimPesan::Customer))
                ->orWhere('butuh_admin', true)
                ->orWhereIn('chat_id', $jeda->keys())))
            ->when($tab === 'belum-dibalas', fn (Builder $q) => $q->whereIn('id', $menunggu->keys()))
            ->when($tab === 'butuh-admin', fn (Builder $q) => $q->where('butuh_admin', true))
            ->when($tab === 'dijeda', fn (Builder $q) => $q->whereIn('chat_id', $jeda->keys()))
            ->when($tipe !== null, fn (Builder $q) => $q->where('tipe_customer', $tipe))
            ->when($cari !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('nama', 'ilike', $kata)
                ->when(strlen($angka) >= 3, fn (Builder $q) => $q->orWhere('nomor_wa', 'like', '%'.ltrim($angka, '0').'%'))));
    }

    /**
     * @param  SupportCollection<string, JedaBot>  $jeda
     * @param  SupportCollection<int, int>  $menunggu
     * @return Collection<int, CrmCustomer>
     */
    private function daftar(Request $request, string $tab, ?TipeCustomer $tipe, SupportCollection $jeda, SupportCollection $menunggu): Collection
    {
        return $this->kueri($request, $tab, $tipe, $jeda, $menunggu)
            ->withMax('pesan as pesan_terakhir_at', 'waktu')
            ->orderByRaw('coalesce((select max(p.waktu) from pesan p where p.crm_customer_id = crm_customer.id), tgl_interaksi_terakhir) desc nulls last')
            ->orderBy('id')
            ->limit(self::BATAS)
            ->get();
    }

    /**
     * The two filter groups beside the list. Each group counts with the other group's
     * choice applied, so the numbers always say what picking that item would give.
     *
     * @param  SupportCollection<string, JedaBot>  $jeda
     * @param  SupportCollection<int, int>  $menunggu
     * @return array{status: array<string, int>, tipe: array<string, int>}
     */
    private function jumlah(string $tab, ?TipeCustomer $tipe, SupportCollection $jeda, SupportCollection $menunggu): array
    {
        $dasar = fn () => CrmCustomer::query()->when($tipe !== null, fn (Builder $q) => $q->where('tipe_customer', $tipe));
        $punyaChat = fn (Builder $q) => $q->where(fn (Builder $sq) => $sq
            ->whereHas('pesan', fn ($q) => $q->where('pengirim', PengirimPesan::Customer))
            ->orWhere('butuh_admin', true)
            ->orWhereIn('chat_id', $jeda->keys()));

        $perTipe = CrmCustomer::query()->toBase()
            ->when($tab === 'semua', fn ($q) => $q->where(fn ($sq) => $sq
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('pesan')->whereColumn('pesan.crm_customer_id', 'crm_customer.id')->where('pengirim', PengirimPesan::Customer->value))
                ->orWhere('butuh_admin', true)
                ->orWhereIn('chat_id', $jeda->keys())))
            ->when($tab === 'belum-dibalas', fn ($q) => $q->whereIn('id', $menunggu->keys()))
            ->when($tab === 'butuh-admin', fn ($q) => $q->where('butuh_admin', true))
            ->when($tab === 'dijeda', fn ($q) => $q->whereIn('chat_id', $jeda->keys()))
            ->groupBy('tipe_customer')
            ->selectRaw('tipe_customer, count(*) as n')
            ->pluck('n', 'tipe_customer');

        return [
            'status' => [
                'semua' => $punyaChat($dasar())->count(),
                'belum-dibalas' => $dasar()->whereIn('id', $menunggu->keys())->count(),
                'butuh-admin' => $dasar()->where('butuh_admin', true)->count(),
                'dijeda' => $dasar()->whereIn('chat_id', $jeda->keys())->count(),
            ],
            'tipe' => array_combine(
                array_map(fn (TipeCustomer $t) => $t->value, TipeCustomer::cases()),
                array_map(fn (TipeCustomer $t) => (int) ($perTipe[$t->value] ?? 0), TipeCustomer::cases()),
            ),
        ];
    }

    /**
     * @param  Collection<int, CrmCustomer>  $customer
     * @param  SupportCollection<string, JedaBot>  $jeda
     * @param  SupportCollection<int, int>  $menunggu
     * @return list<array<string, mixed>>
     */
    private function barisDaftar(Collection $customer, SupportCollection $jeda, SupportCollection $menunggu): array
    {
        $id = $customer->modelKeys();
        $pratinjau = Pesan::query()
            ->whereIn('crm_customer_id', $id)
            ->where('pengirim', PengirimPesan::Customer)
            ->orderBy('waktu')
            ->get(['crm_customer_id', 'teks'])
            ->keyBy('crm_customer_id');
        $order = LogOrder::query()->whereIn('crm_customer_id', $id)->whereIn('status_order', StatusOrder::berjalan())->orderBy('tanggal')->get()->keyBy('crm_customer_id');
        $pj = User::pjKaryawan()->name ?? null;

        return array_values($customer->map(function (CrmCustomer $c) use ($pratinjau, $order, $jeda, $pj, $menunggu) {
            $terakhir = $c->getAttribute('pesan_terakhir_at');
            $waktu = $terakhir ? Carbon::parse($terakhir)->timezone((string) config('app.timezone')) : $c->tgl_interaksi_terakhir;

            return [
                'id' => $c->id,
                'inisial' => $c->nama ? Nama::inisialCustomer($c->nama) : '?',
                'nama' => $this->nama($c),
                'waktu' => $waktu ? ($waktu->isToday() ? $waktu->format('H:i') : Waktu::tanggalSingkat($waktu)) : '',
                'pratinjau' => $pratinjau->get($c->id)->teks ?? $c->pesan_terakhir_customer,
                'skor' => $c->skor_prospek->value,
                'tahap' => $c->tahap_funnel->labelSingkat(),
                'menunggu' => $menunggu[$c->id] ?? 0,
                'tanda' => $this->tanda($c, $order->get($c->id), $jeda->get($c->chat_id), $pj),
            ];
        })->all());
    }

    /**
     * The line under a chat: paused, waiting for an admin, the order, or the bot at work.
     *
     * @return array{teks: string, nada: string}
     */
    private function tanda(CrmCustomer $c, ?LogOrder $order, ?JedaBot $jeda, ?string $pj): array
    {
        return match (true) {
            $jeda !== null => ['teks' => $jeda->sampai ? 'Dijeda s/d '.$jeda->sampai->format('H:i') : 'Dijeda tanpa batas', 'nada' => 'gray'],
            $c->butuh_admin => ['teks' => 'Butuh admin', 'nada' => 'red'],
            $order?->status_order === StatusOrder::Karyawan => ['teks' => $pj ? "Ke {$pj}" : 'Order karyawan', 'nada' => 'gray'],
            $order !== null => ['teks' => $order->status_order->label(), 'nada' => $order->status_order->nada()],
            default => ['teks' => 'Bot aktif', 'nada' => 'blue'],
        };
    }

    /**
     * "Yoga (Karyawan)": employees are marked in the chat list and the thread.
     */
    private function nama(CrmCustomer $c): string
    {
        return $c->namaTampil().($c->tipe_customer === TipeCustomer::Karyawan ? ' (Karyawan)' : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function percakapan(CrmCustomer $c, ?JedaBot $jeda): array
    {
        $pesan = $c->pesan()->with('user')->orderBy('waktu')->orderBy('id')->get();
        $config = PengaturanToko::nilaiBanyak(['JEDA_FOLLOWUP_JAM']);

        return [
            'id' => $c->id,
            'inisial' => $c->nama ? Nama::inisialCustomer($c->nama) : '?',
            'nama' => $this->nama($c),
            // what {nama} becomes in a reply template
            'panggilan' => $c->nama ? Nama::panggilan($c->nama) : 'kak',
            'nomor' => match (true) {
                Str::endsWith($c->chat_id, '@lid') && $c->nomor_wa => NomorWa::samarkanUjung((string) $c->nomor_wa).' (Terverifikasi)',
                (bool) $c->nomor_wa => NomorWa::samarkanUjung((string) $c->nomor_wa),
                Str::endsWith($c->chat_id, '@lid') => 'Nomor disembunyikan WhatsApp',
                default => $c->chat_id,
            },
            'status' => match (true) {
                $jeda !== null => ['label' => 'Dijeda admin', 'nada' => 'gray'],
                $c->butuh_admin => ['label' => 'Butuh admin', 'nada' => 'amber'],
                default => ['label' => 'Dijawab Adiva', 'nada' => 'blue'],
            },
            'jeda' => $jeda?->id,
            'pesan' => $pesan->map(fn (Pesan $p) => [
                'id' => $p->id,
                'jenis' => match ($p->pengirim) {
                    PengirimPesan::Customer => 'customer',
                    PengirimPesan::Adiva => 'adiva',
                    PengirimPesan::Admin => 'admin',
                    PengirimPesan::Sistem => 'sistem',
                },
                'oleh' => match ($p->pengirim) {
                    PengirimPesan::Adiva => 'Adiva · AI',
                    PengirimPesan::Admin => 'Admin · '.($p->user->name ?? 'staf'),
                    default => null,
                },
                'teks' => $p->teks,
                'jam' => $p->waktu->format('H:i'),
                'foto' => $p->lampiran_keterangan,
                // a customer's photo or file (proof of payment), fetched from WAHA on first view
                'lampiran' => $p->lampiran_url ? route('percakapan.lampiran', $p) : null,
                'tombol' => $p->tombol ?? [],
                // only lines the panel sends say how it went; the design's are all sent
                'kirim' => in_array($p->status_kirim, ['MENUNGGU', 'GAGAL'], true) ? $p->status_kirim : null,
            ])->values(),
            'petunjuk' => $jeda !== null
                ? 'Bot sedang dijeda — balasan Anda dikirim langsung lewat WAHA.'
                : 'Membalas manual akan menjeda Adiva untuk customer ini selama '.self::JEDA_BALAS.'.',
            'ringkasan' => $c->ringkasan_terakhir,
            'alasanAdmin' => $c->butuh_admin ? ($c->alasan_butuh_admin ?? '') : null,
            'eskalasi' => $this->eskalasi($c),
            'isian' => $this->isian($c, $jeda, $pesan, (int) $config['JEDA_FOLLOWUP_JAM']),
            'catatan' => $c->catatan_tetap,
            'pesanan' => $this->pesananCustomer($c),
        ];
    }

    /**
     * The question Adiva passed on to the supervisor and is still waiting (72 hours, no
     * newer answer): "Jawab lewat Adiva" shows it. n8n writes both as JSON with a WIB time.
     *
     * @return array{pertanyaan: string, sejak: string}|null
     */
    private function eskalasi(CrmCustomer $c): ?array
    {
        $baca = function (string $kolom) use ($c): ?array {
            $isi = $c->getRawOriginal($kolom);
            $isi = is_string($isi) ? json_decode($isi, true) : $isi;
            $isi = is_string($isi) ? json_decode($isi, true) : $isi;

            return is_array($isi) ? $isi : null;
        };
        $waktu = function (mixed $teks): ?Carbon {
            return is_string($teks) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $teks)
                ? Carbon::createFromFormat('Y-m-d H:i', substr(str_replace('T', ' ', $teks), 0, 16), 'Asia/Jakarta')
                : null;
        };

        $esk = $baca('eskalasi_terbuka');
        $sejak = $waktu($esk['waktu'] ?? null);
        $dijawab = $waktu(($baca('jawaban_supervisor') ?? [])['waktu'] ?? null);

        if (blank($esk['alasan'] ?? null) || $sejak === null || $sejak->lt(now()->subHours(72)) || ($dijawab && $dijawab->gte($sejak))) {
            return null;
        }

        return ['pertanyaan' => (string) $esk['alasan'], 'sejak' => $sejak->timezone(config('app.timezone'))->format('H:i')];
    }

    /**
     * "Jawab lewat Adiva": the supervisor's answer, told to the customer in Adiva's words.
     */
    public function jawab(Request $request, CrmCustomer $customer, JawabAdiva $adiva): RedirectResponse
    {
        self::tolakBilaHanyaLihat();
        $user = $request->user() ?? abort(403);
        $jawaban = trim((string) $request->validate(['jawaban' => ['required', 'string', 'max:1000']], ['jawaban.required' => 'Tulis jawabannya dulu.'])['jawaban']);

        try {
            $adiva->sampaikan($customer, $jawaban, $user);
        } catch (PerintahGagal $e) {
            throw ValidationException::withMessages(['jawaban' => $e->getMessage()]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Percakapan, 'Jawab lewat Adiva', $customer->namaTampil().' · '.Str::limit($jawaban, 60), $customer);
        Inertia::flash('toast', 'Jawaban disampaikan Adiva ke '.$customer->namaTampil().'.');

        return back();
    }

    /**
     * A customer's photo or file. WAHA keeps it at /api/files/…; the panel asks its own
     * WAHA_URL for that path (never another host) and keeps a copy.
     */
    public function lampiran(Pesan $pesan): SymfonyResponse
    {
        $jalur = (string) parse_url((string) $pesan->lampiran_url, PHP_URL_PATH);
        $simpanan = "lampiran/{$pesan->id}";
        $disk = Storage::disk('local');

        abort_unless(str_starts_with($jalur, '/api/files/'), 404);

        if (! $disk->exists($simpanan)) {
            $waha = rtrim((string) config('services.waha.url'), '/');
            abort_if($waha === '', 404, 'Lampiran belum bisa diambil: WAHA belum tersambung.');

            try {
                $jawaban = Http::timeout(20)->withHeaders(['X-Api-Key' => (string) config('services.waha.key')])->get($waha.$jalur);
            } catch (ConnectionException) {
                abort(502, 'WAHA tidak bisa dihubungi.');
            }

            abort_unless($jawaban->successful(), 404, 'Lampiran sudah tidak ada di WAHA.');
            $disk->put($simpanan, $jawaban->body());
            $disk->put($simpanan.'.mime', (string) ($jawaban->header('Content-Type') ?: 'application/octet-stream'));
        }

        return $disk->response($simpanan, basename($jalur), [
            'Content-Type' => (string) $disk->get($simpanan.'.mime'),
            'Content-Disposition' => 'inline; filename="'.basename($jalur).'"',
        ]);
    }

    /**
     * "Penilaian Adiva terakhir": eight fields, depending on the customer.
     *
     * @param  Collection<int, Pesan>  $pesan
     * @return list<array{0: string, 1: string}>
     */
    private function isian(CrmCustomer $c, ?JedaBot $jeda, Collection $pesan, int $jedaFollowup): array
    {
        $isian = [
            ['Tipe', $c->tipe_customer->label()],
            ['Tahap', $c->tahap_funnel->labelSingkat()],
            ['Skor', $c->skor_prospek->value],
            ['Kota', $c->kota ?? '—'],
            ['Minat produk', $c->minat_produk ?? '—'],
        ];

        if ($c->tipe_customer === TipeCustomer::Karyawan) {
            $isian[] = ['Pembayaran', 'Potong gaji'];
            $isian[] = ['PJ program', User::pjKaryawan()->name ?? '—'];
        } else {
            if (in_array($c->tipe_customer, [TipeCustomer::Reseller, TipeCustomer::BelumJelas], true)) {
                $isian[] = ['Lokasi jualan', $c->lokasi_jualan ?? '—'];
            }

            if ($c->estimasi_modal) {
                $isian[] = ['Estimasi modal', Rupiah::teks($c->estimasi_modal)];
            }
        }

        $isian[] = ['Nomor bot', NomorBot::daftar()[$c->sesi_wa ?? ''] ?? $c->sesi_wa ?? '—'];

        if ($jeda !== null) {
            $isian[] = ['Dijeda oleh', $jeda->oleh ?? '—'];
            $isian[] = ['Sampai', $jeda->sampai?->format('H:i') ?? 'Tanpa batas'];
        } elseif (in_array($c->tahap_funnel, self::TAHAP_PROSPEK, true) && ! $c->status_optout) {
            // the scheduled greeting, or the last message plus JEDA_FOLLOWUP_JAM
            $sapa = $c->tgl_followup_berikutnya ?? $pesan->last()?->waktu->copy()->addHours($jedaFollowup) ?? $c->tgl_interaksi_terakhir?->copy()->addHours($jedaFollowup);
            $isian[] = ['Sapaan ulang', $sapa ? $sapa->format('H:i').' bila diam' : '—'];
        }

        return array_slice($isian, 0, 8);
    }

    /**
     * The orders still running, otherwise the latest one.
     *
     * @return list<array{id: string, total: string, status: array{label: string, nada: string}}>
     */
    private function pesananCustomer(CrmCustomer $c): array
    {
        $berjalan = $c->orders()->whereIn('status_order', StatusOrder::berjalan())->latest('tanggal')->get();
        $order = $berjalan->isNotEmpty() ? $berjalan : $c->orders()->latest('tanggal')->limit(1)->get();

        return array_values($order->map(fn (LogOrder $o) => [
            'id' => $o->order_id,
            'total' => Rupiah::teks($o->tagihan()),
            'status' => $o->status_order === StatusOrder::Karyawan
                ? ['label' => 'Order karyawan · potong gaji', 'nada' => 'gray']
                : ['label' => $o->status_order->label(), 'nada' => $o->status_order->nada()],
        ])->all());
    }
}
