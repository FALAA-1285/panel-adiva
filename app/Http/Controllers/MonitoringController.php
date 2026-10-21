<?php

namespace App\Http\Controllers;

use App\Enums\JenisNotifikasi;
use App\Enums\StatusWorkflow;
use App\Models\Notifikasi;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Support\NomorBot;
use App\Support\StatusIntegrasi;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitoring sistem (Monitoring.dc.html): how the n8n workflows ran, the failures they
 * caught, and the integrations they depend on.
 */
class MonitoringController extends Controller
{
    /**
     * "Rentang waktu": label and hours back.
     *
     * @var array<string, array{label: string, jam: int}>
     */
    private const RENTANG = [
        '24j' => ['label' => '24 jam terakhir', 'jam' => 24],
        '7h' => ['label' => '7 hari', 'jam' => 24 * 7],
        '30h' => ['label' => '30 hari', 'jam' => 24 * 30],
    ];

    /**
     * "Pesan masuk per jalur": how the chatbot routes each incoming message.
     *
     * @var array<string, string>
     */
    private const JALUR = [
        'D' => 'D · Chat dijawab AI',
        'A' => 'A · Perintah admin',
        'C' => 'C · Lampiran / bukti',
        'B' => 'B · Ambil alih (jeda)',
        'DIABAIKAN' => 'Diabaikan (sistem/grup)',
    ];

    /**
     * Eksekusi that hit an error: handled inside the chatbot (ERROR) or a failed
     * workflow that set off Notifikasi Error (GAGAL).
     *
     * @var list<string>
     */
    private const BERMASALAH = ['ERROR', 'GAGAL'];

    /**
     * "Jadwal otomatis": label, workflow and the schedule as the design writes it.
     *
     * @var list<array{0: string, 1: string, 2: string|null}>
     */
    private const JADWAL = [
        ['Pantau koneksi WhatsApp', 'pantau_koneksi', 'tiap 15 menit'],
        ['Batas bayar & tutup order', 'batas_bayar', 'tiap jam :15'],
        ['Sapaan prospek & tanya kesan', 'prospek_ulang', 'tiap jam :30'],
        ['Pengingat daftar jeda', 'chatbot', '09.00'],
        ['Laporan harian', 'laporan_harian', '20.00'],
        ['Arsip data', 'arsip_data', 'Minggu 01.00'],
        ['Notifikasi error', 'notifikasi_error', null],
        ['Pengirim broadcast', 'pengirim_broadcast', null],
    ];

    public function __invoke(Request $request): Response
    {
        $rentang = array_key_exists((string) $request->query('rentang'), self::RENTANG) ? (string) $request->query('rentang') : '24j';
        $dari = now()->subHours(self::RENTANG[$rentang]['jam']);
        $workflow = Workflow::query()->get()->keyBy('kode');
        $chatbot = $workflow->get('chatbot');
        $status = new StatusIntegrasi($chatbot);

        return Inertia::render('monitoring', [
            'rentang' => $rentang,
            'pilihanRentang' => array_map(fn (string $nilai, array $r) => ['nilai' => $nilai, 'label' => $r['label']], array_keys(self::RENTANG), self::RENTANG),
            'angka' => $this->angka($dari, $status),
            'perJam' => $this->perJam($dari, $chatbot),
            'jalur' => $this->jalur($dari, $status),
            'insiden' => $this->insiden(),
            'integrasi' => $this->integrasi($workflow, $status),
            'jadwal' => $this->jadwal($workflow),
        ]);
    }

    /**
     * @return list<array{label: string, nilai: string, meta: string}>
     */
    private function angka(CarbonInterface $dari, StatusIntegrasi $status): array
    {
        $eksekusi = WorkflowEvent::query()->toBase()
            ->where('jenis', 'EKSEKUSI')
            ->where('waktu', '>=', $dari)
            ->selectRaw('count(*) as semua')
            ->selectRaw('count(*) filter (where status in (?, ?)) as error', self::BERMASALAH)
            ->first();
        $semua = (int) ($eksekusi->semua ?? 0);
        $error = (int) ($eksekusi->error ?? 0);

        $balas = $status->chatbot($dari)
            ->where('status', 'BERHASIL')
            ->selectRaw('count(*) as jawaban, percentile_cont(0.5) within group (order by durasi_ms) as median')
            ->selectRaw("count(*) filter (where data->>'model' = ?) as cadangan", [$status->modelCadangan()])
            ->first();
        $jawaban = (int) ($balas->jawaban ?? 0);
        $cadangan = (int) ($balas->cadangan ?? 0);
        $gagalTotal = WorkflowEvent::query()->where('jenis', 'INSIDEN')->where('waktu', '>=', $dari)->where('data->jenis', 'AI_GAGAL_TOTAL')->count();
        $persen = fn (float $n) => number_format($n, 1, ',', '.').'%';

        return [
            [
                'label' => 'Eksekusi workflow',
                'nilai' => number_format($semua, 0, ',', '.'),
                'meta' => ($semua > 0 ? $persen(($semua - $error) / $semua * 100) : '0%')." sukses · {$error} error",
            ],
            [
                'label' => 'Waktu balas (median)',
                'nilai' => round((float) ($balas->median ?? 0) / 1000).' dtk',
                'meta' => 'termasuk tunggu gabung '.(int) ($status->chatbot->pengaturan['tunggu_gabung_detik'] ?? 0).' dtk',
            ],
            [
                'label' => 'Model cadangan dipakai',
                'nilai' => "{$cadangan}×",
                'meta' => ($jawaban > 0 ? $persen($cadangan / $jawaban * 100) : '0%')." jawaban · {$gagalTotal} gagal total",
            ],
            [
                'label' => 'Perlu tindakan',
                'nilai' => (string) WorkflowEvent::query()->where('jenis', 'INSIDEN')->where('status', 'PERLU_TINDAKAN')->count(),
                'meta' => 'balasan belum terkirim ke customer',
            ],
        ];
    }

    /**
     * "Eksekusi per jam": the chatbot's answered chats and every execution that hit an
     * error, by hour of the day.
     *
     * @return list<array{jam: string, sukses: int, error: int}>
     */
    private function perJam(CarbonInterface $dari, ?Workflow $chatbot): array
    {
        $baris = WorkflowEvent::query()->toBase()
            ->where('jenis', 'EKSEKUSI')
            ->where('waktu', '>=', $dari)
            ->selectRaw('extract(hour from waktu at time zone ?)::int as jam', [(string) config('app.timezone')])
            ->selectRaw("count(*) filter (where workflow_id = ? and status = 'BERHASIL') as sukses", [$chatbot->id ?? 0])
            ->selectRaw('count(*) filter (where status in (?, ?)) as error', self::BERMASALAH)
            ->groupBy('jam')
            ->get()
            ->keyBy('jam');

        return array_map(fn (int $jam) => [
            'jam' => sprintf('%02d', $jam),
            'sukses' => (int) ($baris[$jam]->sukses ?? 0),
            'error' => (int) ($baris[$jam]->error ?? 0),
        ], range(0, 23));
    }

    /**
     * @return list<array{label: string, n: int, diabaikan: bool}>
     */
    private function jalur(CarbonInterface $dari, StatusIntegrasi $status): array
    {
        // each chatbot run records the route of the messages it took in, and how many
        $jumlah = $status->chatbot($dari)
            ->whereNotNull('data->jalur')
            ->selectRaw("data->>'jalur' as jalur, sum((data->>'pesan')::int) as n")
            ->groupBy('jalur')
            ->pluck('n', 'jalur');

        return array_map(fn (string $jalur, string $label) => [
            'label' => $label,
            'n' => (int) ($jumlah[$jalur] ?? 0),
            'diabaikan' => $jalur === 'DIABAIKAN',
        ], array_keys(self::JALUR), self::JALUR);
    }

    /**
     * "Jaring pengaman": what the workflows caught today.
     *
     * @return list<array{jam: string, judul: string, detail: string|null, perluTindakan: bool, aksi: string|null}>
     */
    private function insiden(): array
    {
        return array_values(WorkflowEvent::query()
            ->where('jenis', 'INSIDEN')
            ->where('waktu', '>=', now()->startOfDay())
            ->orderByDesc('waktu')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WorkflowEvent $e) => [
                'jam' => $e->waktu->format('H:i'),
                'judul' => $e->judul,
                'detail' => $e->detail,
                'perluTindakan' => $e->status === 'PERLU_TINDAKAN',
                'aksi' => $e->status === 'PERLU_TINDAKAN' ? $e->aksi : null,
            ])
            ->all());
    }

    /**
     * @param  Collection<string, Workflow>  $workflow
     * @return array<string, mixed>
     */
    private function integrasi(Collection $workflow, StatusIntegrasi $status): array
    {
        $hariIni = now()->startOfDay();
        $pantau = $workflow->get('pantau_koneksi');
        $koneksi = $status->koneksi();
        $nomor = NomorBot::daftar();

        // "Nomor Lawang sempat putus 11:30–11:52, supervisor sudah dikabari."
        $putus = $koneksi->filter(fn (WorkflowEvent $e) => $e->status === 'PUTUS' && $e->waktu->gte($hariIni))
            ->map(function (WorkflowEvent $e) use ($koneksi, $nomor) {
                $sesi = (string) ($e->data['sesi'] ?? '');
                $label = $nomor[$sesi] ?? $sesi;
                $lagi = $koneksi->first(fn (WorkflowEvent $k) => ($k->data['sesi'] ?? null) === $sesi && $k->status === 'TERHUBUNG' && $k->waktu->gt($e->waktu));
                $dikabari = isset($e->data['pesan_supervisor']) ? ', supervisor sudah dikabari' : '';

                return $lagi
                    ? "{$label} sempat putus {$e->waktu->format('H:i')}–{$lagi->waktu->format('H:i')}{$dikabari}."
                    : "{$label} putus sejak {$e->waktu->format('H:i')}{$dikabari}.";
            });
        $terakhirDicek = $status->terakhirDicek();
        $model = $status->modelHariIni();
        $terakhir = $status->koneksiTerakhir();

        return [
            'nomor' => collect($nomor)->map(fn (string $label, string $sesi) => [
                'label' => "WAHA · {$label}",
                'sesi' => $sesi,
                'terhubung' => ($terakhir[$sesi] ?? null)?->status === 'TERHUBUNG',
                'status' => match (($terakhir[$sesi] ?? null)?->status) {
                    'TERHUBUNG' => 'Terhubung',
                    'PUTUS' => 'Putus',
                    default => 'Belum dicek',
                },
            ])->values(),
            'catatan' => collect([
                $pantau && $pantau->jadwal ? 'Dicek '.Str::lcfirst($pantau->jadwal).($terakhirDicek ? ' · terakhir '.$terakhirDicek->format('H:i') : '').'.' : null,
                ...$putus->all(),
            ])->filter()->implode(' '),
            'utama' => ['label' => $status->namaModel('model_utama').' (utama)', 'nilai' => 'p50 '.number_format($model['p50'], 1, ',', '.').' dtk'],
            'cadangan' => ['label' => $status->namaModel('model_cadangan').' (cadangan)', 'nilai' => "dipakai {$model['cadangan']}× hari ini", 'dipakai' => $model['cadangan'] > 0],
            'ongkir' => "{$model['ongkir']} cek hari ini",
            'workflow' => "{$status->workflowAktif()} aktif · {$status->errorHariIni()} error hari ini",
        ];
    }

    /**
     * @param  Collection<string, Workflow>  $workflow
     * @return list<array{label: string, nilai: string}>
     */
    private function jadwal(Collection $workflow): array
    {
        $hariIni = now()->startOfDay();
        $terakhir = fn (?Workflow $w, string $jenis = 'EKSEKUSI') => $w
            ? WorkflowEvent::query()->where('workflow_id', $w->id)->where('jenis', $jenis)->latest('waktu')->first()
            : null;

        return array_map(function (array $baris) use ($workflow, $terakhir, $hariIni) {
            [$label, $kode, $jadwal] = $baris;
            $w = $workflow->get($kode);

            $nilai = match (true) {
                $w === null => 'belum dibuat di n8n',
                $kode === 'chatbot' => $jadwal.' · '.(Notifikasi::query()->where('jenis', JenisNotifikasi::PengingatJeda->value)->where('waktu', '>=', $hariIni)->exists() ? 'terkirim' : 'belum'),
                $kode === 'laporan_harian' => $jadwal.' · '.$this->kapanTerkirim($terakhir($w, 'LAPORAN')?->waktu),
                $kode === 'arsip_data' => $jadwal.' · '.((int) ($terakhir($w)?->data['order'] ?? 0)).' order',
                $kode === 'notifikasi_error' => Str::lower(StatusWorkflow::from($w->status)->label()).' · '
                    .WorkflowEvent::query()->where('workflow_id', $w->id)->where('jenis', 'EKSEKUSI')->where('waktu', '>=', $hariIni)->count().' kali hari ini',
                default => collect([$jadwal, $terakhir($w)?->waktu->format('H:i')])->filter()->implode(' · '),
            };

            return ['label' => $label, 'nilai' => $nilai];
        }, self::JADWAL);
    }

    /**
     * "terkirim" today, "kemarin terkirim", or the date.
     */
    private function kapanTerkirim(?CarbonInterface $waktu): string
    {
        return match (true) {
            $waktu === null => 'belum',
            $waktu->isToday() => 'terkirim',
            $waktu->isYesterday() => 'kemarin terkirim',
            default => $waktu->translatedFormat('j M').' terkirim',
        };
    }
}
