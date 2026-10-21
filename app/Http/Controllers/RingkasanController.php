<?php

namespace App\Http\Controllers;

use App\Enums\JenisNotifikasi;
use App\Enums\PengirimPesan;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\LogOrder;
use App\Models\Notifikasi;
use App\Models\Pesan;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Support\CsvSanitizer;
use App\Support\JamKerja;
use App\Support\PengaturanToko;
use App\Support\Rupiah;
use App\Support\StatusIntegrasi;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ringkasan (Main.dc.html): the day at a glance, what needs a decision, how Adiva is
 * doing, and the customers and orders in numbers.
 */
class RingkasanController extends Controller
{
    /**
     * The period picker: label and days back (today included).
     *
     * @var array<string, array{label: string, hari: int, banding: string}>
     */
    private const RENTANG = [
        'hari-ini' => ['label' => 'Hari ini', 'hari' => 1, 'banding' => 'dari kemarin'],
        '7-hari' => ['label' => '7 hari terakhir', 'hari' => 7, 'banding' => 'dari 7 hari sebelumnya'],
        '30-hari' => ['label' => '30 hari terakhir', 'hari' => 30, 'banding' => 'dari 30 hari sebelumnya'],
    ];

    /**
     * "Perlu tindakan": the supervisor notifications that wait for someone, most urgent
     * kind first, with their tone.
     *
     * @var array<string, string>
     */
    private const TINDAKAN = [
        'ORDER_BARU' => 'amber',
        'BUKTI_MASUK' => 'blue',
        'BUTUH_ADMIN' => 'red',
        'BALASAN_GAGAL' => 'red',
        'JEDA_CHAT' => 'gray',
        'ORDER_KARYAWAN' => 'gray',
    ];

    /**
     * Tipe customer colours (Main.dc.html).
     *
     * @var array<string, string>
     */
    private const WARNA_TIPE = [
        'RETAIL' => '#1D4ED8',
        'RESELLER' => '#60A5FA',
        'BELUM_JELAS' => '#D1D5DB',
        'KARYAWAN' => '#1E3A8A',
    ];

    public function index(Request $request): Response
    {
        $rentang = $this->rentang($request);
        $status = StatusIntegrasi::sekarang();

        return Inertia::render('ringkasan', [
            'tanggal' => now()->translatedFormat('l, j F').' · data sampai '.now()->format('H:i').' WIB',
            'rentang' => $rentang,
            'pilihanRentang' => array_map(fn (string $nilai, array $r) => ['nilai' => $nilai, 'label' => $r['label']], array_keys(self::RENTANG), self::RENTANG),
            'angka' => $this->angka($rentang, $status),
            'tindakan' => $this->tindakan($request->user()),
            'statusAdiva' => $this->statusAdiva($status),
            'otomatis' => $this->otomatis(),
            'volume' => $this->volume($status),
            'tahap' => $this->tahap(),
            'tipe' => $this->tipe(),
            'pesanan' => $this->pesanan(),
        ]);
    }

    /**
     * "Ekspor": one line per day of the period.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $hari = self::RENTANG[$this->rentang($request)]['hari'];
        $status = StatusIntegrasi::sekarang();

        return response()->streamDownload(function () use ($hari, $status) {
            $keluar = fopen('php://output', 'w');

            if ($keluar === false) {
                return;
            }

            fwrite($keluar, "\xEF\xBB\xBF");
            fputcsv($keluar, ['Tanggal', 'Chat masuk', 'Diteruskan ke admin', 'Order baru', 'Pembayaran lunas', 'Order lunas'], escape: '');

            foreach (range($hari - 1, 0) as $mundur) {
                $awal = CarbonImmutable::now()->subDays($mundur)->startOfDay();
                $akhir = $awal->endOfDay();
                $chat = $this->chat($status, $awal, $akhir);
                $lunas = LogOrder::query()->whereBetween('lunas_at', [$awal, $akhir])->get(['total_produk', 'ongkir']);

                fputcsv($keluar, CsvSanitizer::baris([
                    $awal->format('Y-m-d'),
                    $chat['chat'],
                    $chat['diteruskan'],
                    LogOrder::query()->whereBetween('tanggal', [$awal, $akhir])->count(),
                    $lunas->sum(fn (LogOrder $o) => $o->total_produk + $o->ongkir),
                    $lunas->count(),
                ]), escape: '');
            }

            fclose($keluar);
        }, 'ringkasan-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rentang(Request $request): string
    {
        return array_key_exists((string) $request->query('rentang'), self::RENTANG) ? (string) $request->query('rentang') : 'hari-ini';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function angka(string $rentang, StatusIntegrasi $status): array
    {
        $r = self::RENTANG[$rentang];
        $awal = CarbonImmutable::now()->subDays($r['hari'] - 1)->startOfDay();
        $akhir = CarbonImmutable::now();
        // the period before, up to the same moment ("dari kemarin" = yesterday until now)
        $awalLalu = $awal->subDays($r['hari']);
        $akhirLalu = $akhir->subDays($r['hari']);

        $kini = $this->chat($status, $awal, $akhir);
        $lalu = $this->chat($status, $awalLalu, $akhirLalu);
        $tanpaAdmin = fn (array $c) => $c['chat'] > 0 ? ($c['chat'] - $c['diteruskan']) / $c['chat'] * 100 : null;
        $persenKini = $tanpaAdmin($kini);
        $persenLalu = $tanpaAdmin($lalu);

        $cadangan = $status->chatbot($awal, $akhir)->where('status', 'BERHASIL')->where('data->model', $status->modelCadangan())->count();
        $maaf = WorkflowEvent::query()->where('jenis', 'INSIDEN')->whereBetween('waktu', [$awal, $akhir])->where('data->jenis', 'AI_GAGAL_TOTAL')->count();

        $order = LogOrder::query()->whereBetween('tanggal', [$awal, $akhir])->get(['status_order', 'tipe_customer']);
        $hitungLunas = fn (CarbonInterface $dari, CarbonInterface $sampai) => [
            'total' => (int) LogOrder::query()->whereBetween('lunas_at', [$dari, $sampai])->sum(DB::raw('total_produk + ongkir')),
            'count' => LogOrder::query()->whereBetween('lunas_at', [$dari, $sampai])->count(),
        ];
        $lunasKini = $hitungLunas($awal, $akhir);
        $lunasLalu = $hitungLunas($awalLalu, $akhirLalu);
        $nilaiKini = $lunasKini['total'];
        $nilaiLalu = $lunasLalu['total'];

        return [
            [
                'label' => 'Chat masuk '.Str::lower($r['label']),
                'nilai' => number_format($kini['chat'], 0, ',', '.'),
                ...$this->naikTurun($kini['chat'], $lalu['chat'], fn (float $p) => $this->persen($p, 0).' '.$r['banding']),
                'meta' => "{$kini['customer']} customer · pesan beruntun digabung",
            ],
            [
                'label' => 'Dijawab Adiva tanpa admin',
                'nilai' => $persenKini === null ? '—' : number_format($persenKini, 1, ',', '.').'%',
                ...($persenKini !== null && $persenLalu !== null
                    ? ['delta' => $this->bertanda(round($persenKini - $persenLalu, 1), 1).' poin', 'deltaNada' => $persenKini >= $persenLalu ? 'green' : 'red']
                    : []),
                'meta' => "{$cadangan} lewat model cadangan · {$maaf} pesan maaf",
            ],
            [
                'label' => 'Order baru',
                'nilai' => (string) $order->count(),
                'meta' => $order->where('status_order', StatusOrder::MenungguKonfirmasi)->count().' menunggu ACC · '
                    .$order->filter(fn (LogOrder $o) => $o->status_order === StatusOrder::Karyawan || $o->tipe_customer === TipeCustomer::Karyawan)->count().' order karyawan',
            ],
            [
                'label' => 'Pembayaran lunas',
                'nilai' => $this->rupiahSingkat($nilaiKini),
                ...$this->naikTurun($nilaiKini, $nilaiLalu, fn (float $p) => $this->persen($p, 1)),
                'meta' => $lunasKini['count'].' order · '.LogOrder::query()->where('status_order', StatusOrder::Diacc)->whereNotNull('bukti_masuk_at')->count().' bukti transfer belum dicek',
            ],
        ];
    }

    /**
     * Chats (chatbot runs), how many went to an admin, and from how many customers.
     *
     * @return array{chat: int, diteruskan: int, customer: int}
     */
    private function chat(StatusIntegrasi $status, CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $hasil = $status->chatbot($dari, $sampai)
            ->whereIn('status', ['BERHASIL', 'ERROR'])
            ->selectRaw('count(*) as chat')
            ->selectRaw("count(*) filter (where (data->>'diteruskan')::boolean) as diteruskan")
            ->selectRaw('count(distinct crm_customer_id) as customer')
            ->first();

        return ['chat' => (int) ($hasil->chat ?? 0), 'diteruskan' => (int) ($hasil->diteruskan ?? 0), 'customer' => (int) ($hasil->customer ?? 0)];
    }

    /**
     * The change on the period before, green up and red down; nothing without a base.
     *
     * @param  callable(float): string  $teks
     * @return array{delta?: string, deltaNada?: string}
     */
    private function naikTurun(int $kini, int $lalu, callable $teks): array
    {
        if ($lalu <= 0) {
            return [];
        }

        $persen = ($kini - $lalu) / $lalu * 100;

        return ['delta' => $teks($persen), 'deltaNada' => $persen >= 0 ? 'green' : 'red'];
    }

    /**
     * "+12%", "-3,5%".
     */
    private function persen(float $nilai, int $desimal): string
    {
        return $this->bertanda(round($nilai, $desimal), $desimal).'%';
    }

    private function bertanda(float $nilai, int $desimal): string
    {
        return ($nilai >= 0 ? '+' : '-').number_format(abs($nilai), $desimal, ',', '.');
    }

    /**
     * 5513000 → "Rp5,51 jt".
     */
    private function rupiahSingkat(int $nilai): string
    {
        return $nilai >= 1_000_000 ? 'Rp'.number_format($nilai / 1_000_000, 2, ',', '.').' jt' : Rupiah::teks($nilai);
    }

    /**
     * "Perlu tindakan": today's supervisor notifications whose matter is still open.
     *
     * @return list<array<string, mixed>>
     */
    private function tindakan(?User $user): array
    {
        $jenis = array_keys(self::TINDAKAN);
        $pj = User::pjKaryawan()->name ?? null;

        return array_values(Notifikasi::query()
            ->whereIn('jenis', $jenis)
            ->where('waktu', '>=', now()->startOfDay())
            ->with(['customer', 'order.items'])
            ->orderByDesc('waktu')
            ->get()
            ->sortBy(fn (Notifikasi $n) => array_search($n->jenis, $jenis, true))
            ->map(fn (Notifikasi $n) => $this->itemTindakan($n, $pj, $user))
            ->filter()
            ->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemTindakan(Notifikasi $n, ?string $pj, ?User $user): ?array
    {
        $c = $n->customer;
        $o = $n->order;
        $dasar = ['id' => $n->id, 'judul' => $n->judul, 'nada' => self::TINDAKAN[$n->jenis], 'waktu' => $n->waktu->format('H:i')];

        return match (JenisNotifikasi::from($n->jenis)) {
            JenisNotifikasi::OrderBaru => $o?->status_order === StatusOrder::MenungguKonfirmasi ? [
                ...$dasar,
                'waktu' => Waktu::lalu($o->tanggal),
                'nama' => $o->namaCustomer(),
                'info' => collect([$c?->tipe_customer->label(), $c?->kota])->filter()->implode(' · '),
                'kode' => $o->order_id,
                'isi' => collect([
                    $o->items->count().' item',
                    Rupiah::teks($o->total_produk + $o->ongkir).($o->ongkir > 0 && $o->kurir() ? ' termasuk ongkir '.$o->kurir() : ''),
                ])->implode(' · '),
                'aksi' => [
                    ['label' => 'Tinjau', 'href' => "/pesanan/{$o->order_id}"],
                    ...($user?->can('acc') ? [['label' => 'ACC & kirim invoice', 'aksi' => 'ACC', 'order' => $o->order_id, 'utama' => true]] : []),
                ],
            ] : null,
            JenisNotifikasi::BuktiMasuk => $o?->status_order === StatusOrder::Diacc && $o->bukti_masuk_at ? [
                ...$dasar,
                'waktu' => $o->bukti_masuk_at->format('H:i'),
                'nama' => $o->namaCustomer(),
                'info' => $c?->tipe_customer->label(),
                'kode' => $o->order_id,
                'isi' => 'tagihan '.Rupiah::teks($o->total_produk + $o->ongkir).' · tidak akan batal otomatis selama belum dicek',
                'aksi' => [
                    ['label' => 'Lihat bukti', 'href' => "/pesanan/{$o->order_id}"],
                    ...($user?->can('status_order') ? [['label' => 'Tandai lunas', 'aksi' => 'LUNAS', 'order' => $o->order_id, 'garis' => true]] : []),
                ],
            ] : null,
            JenisNotifikasi::ButuhAdmin => $c?->butuh_admin ? [
                ...$dasar,
                'nama' => $c->namaTampil(),
                'info' => collect([$c->tipe_customer->label(), $c->kota, $c->skor_prospek->value])->filter()->implode(' · '),
                'isi' => $c->pesan_terakhir_customer ? "“{$c->pesan_terakhir_customer}”" : null,
                'aksi' => [['label' => 'Buka chat', 'href' => route('percakapan', ['pilih' => $c->id], false)], ['label' => 'Ambil alih', 'href' => route('jeda', absolute: false), 'garis' => true]],
            ] : null,
            JenisNotifikasi::BalasanGagal => $c && WorkflowEvent::query()->where('jenis', 'INSIDEN')->where('status', 'PERLU_TINDAKAN')->where('crm_customer_id', $c->id)->exists() ? [
                ...$dasar,
                'nama' => $c->namaTampil(),
                'info' => $c->tipe_customer->label(),
                'isi' => Str::after((string) $n->detail, ' · ').'. Isi balasan tersimpan; CRM dan order tetap tercatat.',
                'aksi' => [
                    ['label' => 'Detail', 'href' => route('monitoring', absolute: false)],
                    // the latest line WhatsApp did not take; none stored means nothing to resend
                    ['label' => 'Kirim ulang', 'garis' => true, 'kirimUlang' => Pesan::query()->where('crm_customer_id', $c->id)->where('status_kirim', 'GAGAL')->latest('waktu')->value('id')],
                ],
            ] : null,
            JenisNotifikasi::JedaChat => ($jeda = $c ? JedaBot::query()->aktif()->where('chat_id', $c->chat_id)->latest('sejak')->first() : null) ? [
                ...$dasar,
                'nama' => $c->namaTampil(),
                'info' => $c->tipe_customer->label(),
                'isi' => "Dijeda {$jeda->oleh} sejak {$jeda->sejak->format('H:i')}. Bot diam; customer ".($jeda->sudah_diberi_tahu ? 'sudah dikabari sekali.' : 'belum dikabari.'),
                'aksi' => [['label' => 'Balas', 'href' => route('percakapan', ['pilih' => $c->id], false)], ['label' => 'Lanjutkan bot', 'href' => route('jeda', absolute: false), 'garis' => true]],
            ] : null,
            JenisNotifikasi::OrderKaryawan => $o?->status_order === StatusOrder::Karyawan ? [
                ...$dasar,
                'nama' => $o->namaCustomer(),
                'info' => 'Karyawan terverifikasi',
                'kode' => $o->order_id,
                'isi' => 'potong gaji '.Rupiah::teks($o->total_produk + $o->ongkir).($pj ? " · sudah diteruskan ke {$pj}" : ''),
                'aksi' => [['label' => 'Lihat order', 'href' => route('katalog.karyawan', absolute: false), 'garis' => true]],
            ] : null,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusAdiva(StatusIntegrasi $status): array
    {
        $model = $status->modelHariIni();
        $koneksi = $status->koneksiTerakhir();
        $terhubung = collect($koneksi)->filter(fn (?WorkflowEvent $e) => $e?->status === 'TERHUBUNG')->count();
        $dicek = $status->terakhirDicek();

        return [
            ['label' => 'Workflow n8n', 'nilai' => "{$status->workflowAktif()} aktif", 'nada' => 'green'],
            [
                'label' => 'WhatsApp (WAHA)',
                'nilai' => $terhubung === count($koneksi)
                    ? count($koneksi).' nomor'.($dicek ? ' · dicek '.$dicek->format('H:i') : '')
                    : (count($koneksi) - $terhubung).' dari '.count($koneksi).' nomor putus',
                'nada' => $terhubung === count($koneksi) ? 'green' : 'red',
            ],
            ['label' => 'Model utama', 'nilai' => $status->namaModel('model_utama').' · '.number_format($model['p50'], 1, ',', '.').' dtk'],
            $model['cadangan'] > 0
                ? ['label' => 'Model cadangan', 'nilai' => "Dipakai {$model['cadangan']}× hari ini", 'nada' => 'amber']
                : ['label' => 'Model cadangan', 'nilai' => 'Tidak dipakai hari ini'],
            ['label' => 'Ongkir Mengantar', 'nilai' => "{$model['ongkir']} cek hari ini"],
            ['label' => 'Sedang dijeda', 'nilai' => JedaBot::query()->aktif()->count().' customer', 'href' => route('jeda', absolute: false)],
        ];
    }

    /**
     * "Pesan otomatis": what Adiva sends on its own, with today's counts.
     *
     * @return array{sub: string, baris: list<array{waktu: string, judul: string, isi: string}>}
     */
    private function otomatis(): array
    {
        $hariIni = now()->startOfDay();
        $jamKerja = JamKerja::dariPengaturan();
        $config = PengaturanToko::nilaiBanyak(['JEDA_FOLLOWUP_JAM', 'JEDA_KESAN_HARI', 'BATAS_BAYAR_JAM']);
        $workflow = Workflow::query()->get()->keyBy('kode');
        $jam = fn (?string $teks) => preg_match('/\d{2}[.:]\d{2}/', (string) $teks, $m) ? str_replace(':', '.', $m[0]) : '';

        $terkirim = Pesan::query()->toBase()
            ->where('pengirim', PengirimPesan::Adiva->value)
            ->where('waktu', '>=', $hariIni)
            ->selectRaw("meta->>'otomatis' as jenis, count(*) as jumlah")
            ->groupBy('jenis')
            ->pluck('jumlah', 'jenis');
        $ditutup = WorkflowEvent::query()
            ->where('workflow_id', $workflow->get('batas_bayar')->id ?? 0)
            ->where('waktu', '>=', $hariIni)
            ->get()
            ->sum(fn (WorkflowEvent $e) => (int) ($e->data['ditutup'] ?? 0));
        $pengingat = Notifikasi::query()->where('jenis', JenisNotifikasi::PengingatJeda->value)->where('waktu', '>=', $hariIni)->exists();

        return [
            'sub' => 'Jam kerja '.str_replace(':', '.', $jamKerja->mulai).'–'.str_replace(':', '.', $jamKerja->selesai)
                .($jamKerja->libur !== [] ? ' · '.implode(', ', $jamKerja->libur).' tidak dihitung' : ''),
            'baris' => [
                ['waktu' => $jam($workflow->get('chatbot')?->jadwal_keterangan), 'judul' => 'Pengingat daftar jeda', 'isi' => $pengingat ? 'Terkirim ke supervisor' : 'Belum terkirim hari ini'],
                ['waktu' => "{$config['JEDA_FOLLOWUP_JAM']} jam", 'judul' => 'Sapaan ulang prospek', 'isi' => ((int) ($terkirim['SAPAAN'] ?? 0)).' customer hari ini · sekali saja'],
                ['waktu' => "{$config['JEDA_KESAN_HARI']} hari", 'judul' => 'Tanya kesan pesanan', 'isi' => ((int) ($terkirim['KESAN'] ?? 0)).' customer setelah SELESAI'],
                ['waktu' => "{$config['BATAS_BAYAR_JAM']} jam", 'judul' => 'Batas bayar (jam kerja)', 'isi' => ((int) ($terkirim['PENGINGAT_BAYAR'] ?? 0))." diingatkan · {$ditutup} order belum di-ACC ditutup"],
                ['waktu' => $jam($workflow->get('laporan_harian')?->jadwal), 'judul' => 'Laporan harian', 'isi' => 'Rekap 24 jam ke supervisor'],
            ],
        ];
    }

    /**
     * "Volume chat · 30 hari": chats and hand-overs per day.
     *
     * @return array{sub: string, hari: list<array{label: string, chat: int, diteruskan: int}>}
     */
    private function volume(StatusIntegrasi $status): array
    {
        $awal = CarbonImmutable::now()->subDays(29)->startOfDay();
        $perHari = $status->chatbot($awal)
            ->whereIn('status', ['BERHASIL', 'ERROR'])
            ->selectRaw('(waktu at time zone ?)::date as hari', [(string) config('app.timezone')])
            ->selectRaw('count(*) as chat')
            ->selectRaw("count(*) filter (where (data->>'diteruskan')::boolean) as diteruskan")
            ->groupBy('hari')
            ->get()
            ->keyBy('hari');

        $hari = array_map(function (int $i) use ($awal, $perHari) {
            $tanggal = $awal->addDays($i);
            $baris = $perHari[$tanggal->toDateString()] ?? null;

            return ['label' => Waktu::tanggalSingkat($tanggal), 'chat' => (int) ($baris->chat ?? 0), 'diteruskan' => (int) ($baris->diteruskan ?? 0)];
        }, range(0, 29));

        $chat = array_sum(array_column($hari, 'chat'));
        $diteruskan = array_sum(array_column($hari, 'diteruskan'));

        return [
            'sub' => number_format($chat, 0, ',', '.').' chat masuk, '.number_format($diteruskan, 0, ',', '.').' diteruskan ke admin ('
                .number_format($chat > 0 ? $diteruskan / $chat * 100 : 0, 1, ',', '.').'%)',
            'hari' => $hari,
        ];
    }

    /**
     * Customers active in the last 30 days per stage (as on Pipeline).
     *
     * @return array{jumlah: int, baris: list<array{label: string, n: int, keluar: bool}>}
     */
    private function tahap(): array
    {
        $per = CrmCustomer::query()->toBase()
            ->where('tgl_interaksi_terakhir', '>=', now()->subDays(30))
            ->selectRaw('tahap_funnel, count(*) as n')
            ->groupBy('tahap_funnel')
            ->pluck('n', 'tahap_funnel');

        return [
            'jumlah' => (int) $per->sum(),
            'baris' => array_map(fn (TahapFunnel $t) => ['label' => $t->label(), 'n' => (int) ($per[$t->value] ?? 0), 'keluar' => $t === TahapFunnel::TidakMinat], TahapFunnel::cases()),
        ];
    }

    /**
     * Customers active in the last 30 days per type, the largest first.
     *
     * @return array{jumlah: int, baris: list<array{label: string, n: int, warna: string}>}
     */
    private function tipe(): array
    {
        $per = CrmCustomer::query()->toBase()
            ->where('tgl_interaksi_terakhir', '>=', now()->subDays(30))
            ->selectRaw('tipe_customer, count(*) as n')
            ->groupBy('tipe_customer')
            ->pluck('n', 'tipe_customer');

        $baris = collect(TipeCustomer::cases())
            ->map(fn (TipeCustomer $t) => ['label' => $t->label(), 'n' => (int) ($per[$t->value] ?? 0), 'warna' => self::WARNA_TIPE[$t->value]])
            ->sortByDesc('n')
            ->all();

        $baris = array_values($baris);

        return ['jumlah' => (int) $per->sum(), 'baris' => $baris];
    }

    /**
     * Orders still running, grouped like the tabs on Pesanan.
     *
     * @return array{jumlah: int, baris: list<array{label: string, n: int, nada: string, tab: string}>}
     */
    private function pesanan(): array
    {
        $order = LogOrder::query()->whereIn('status_order', StatusOrder::berjalan())->get(['status_order', 'bukti_masuk_at']);
        $n = fn (StatusOrder $s) => $order->where('status_order', $s)->count();
        $bukti = $order->where('status_order', StatusOrder::Diacc)->whereNotNull('bukti_masuk_at')->count();

        $baris = [
            ['label' => 'Menunggu ACC', 'n' => $n(StatusOrder::MenungguKonfirmasi), 'nada' => 'amber', 'tab' => 'menunggu-acc'],
            ['label' => 'Menunggu bayar', 'n' => $n(StatusOrder::Diacc) - $bukti, 'nada' => 'blue', 'tab' => 'menunggu-bayar'],
            ['label' => 'Bukti masuk, belum dicek', 'n' => $bukti, 'nada' => 'amber', 'tab' => 'bukti-masuk'],
            ['label' => 'Lunas, siap kirim / ambil', 'n' => $n(StatusOrder::Lunas), 'nada' => 'green', 'tab' => 'lunas'],
            ['label' => 'Dikirim', 'n' => $n(StatusOrder::Dikirim), 'nada' => 'violet', 'tab' => 'dikirim'],
            ['label' => 'Order karyawan', 'n' => $n(StatusOrder::Karyawan), 'nada' => 'gray', 'tab' => 'karyawan'],
        ];

        return ['jumlah' => $order->count(), 'baris' => $baris];
    }
}
