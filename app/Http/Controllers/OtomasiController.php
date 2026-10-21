<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\StatusWorkflow;
use App\Enums\TahapFunnel;
use App\Models\Pesan;
use App\Models\TemplateFollowup;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Support\Aktivitas;
use App\Support\JamKerja;
use App\Support\PengaturanToko;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Otomasi (Otomasi.dc.html): the n8n workflows behind Adiva, the rules they follow
 * (numbers from CONFIG_TOKO), the follow-up templates and what they send.
 */
class OtomasiController extends Controller
{
    /**
     * The supervisor messages shown under "Pesan sistem ke supervisor", in the design's
     * order: title, tone of the dot, and what the card explains.
     *
     * @var array<string, array{string, string, string}>
     */
    private const PESAN_SISTEM = [
        'NOMOR_PUTUS' => ['Nomor bot terputus', 'red', 'Dikirim lewat nomor lain yang masih tersambung. Saat tersambung lagi, supervisor dikabari lama putusnya.'],
        'WORKFLOW_ERROR' => ['Chatbot bermasalah', 'red', 'Berlaku untuk semua workflow Bang Bana. Kalau workflow ini sendiri gagal, tidak ada yang mengabari.'],
        'ORDER_DITUTUP' => ['Order lama ditutup otomatis', 'amber', 'Satu rekap per putaran, bukan satu pesan per order.'],
    ];

    /**
     * Automated messages counted in "Pesan otomatis hari ini" (pesan.meta.otomatis).
     *
     * @var array<string, string>
     */
    private const PESAN_OTOMATIS = [
        'SAPAAN' => 'sapaan prospek',
        'KESAN' => 'tanya kesan',
        'PENGINGAT_BAYAR' => 'pengingat bayar',
    ];

    public function index(): Response
    {
        $config = PengaturanToko::nilaiBanyak([
            'BATAS_BAYAR_JAM', 'BATAS_KONFIRMASI_JAM', 'BATAS_BAYAR_PICKUP_JAM', 'JEDA_FOLLOWUP_JAM', 'JEDA_KESAN_HARI',
            'JAM_PESAN_OTOMATIS', 'HARI_LIBUR_OTOMATIS', 'ARSIP_ORDER_HARI', 'ARSIP_CUSTOMER_HARI',
        ]);
        $jamKerja = JamKerja::dari($config['JAM_PESAN_OTOMATIS'], $config['HARI_LIBUR_OTOMATIS']);
        $workflow = Workflow::query()->orderBy('id')->get();
        $terakhir = $this->eksekusiTerakhir();

        return Inertia::render('otomasi', [
            // "Buka n8n": the editor address from N8N_EDITOR_URL
            'n8nUrl' => filled(config('services.n8n.editor_url')) ? (string) config('services.n8n.editor_url') : null,
            'ringkasan' => $this->ringkasan($workflow, $config, $jamKerja),
            'workflow' => $workflow->map(function (Workflow $w) use ($terakhir) {
                $status = StatusWorkflow::tryFrom($w->status);
                $jalan = $terakhir->get($w->id);

                return [
                    'id' => $w->id,
                    'nama' => $w->nama,
                    'node' => $w->jumlah_node ? "{$w->jumlah_node} node" : null,
                    'jadwal' => $w->jadwal,
                    'jadwalKeterangan' => $w->jadwal_keterangan,
                    'deskripsi' => $w->deskripsi,
                    'terakhir' => $jalan ? ['waktu' => Waktu::relatif($jalan->waktu), 'catatan' => $jalan->detail] : null,
                    'status' => ['label' => $status?->label() ?? $w->status, 'nada' => $status?->nada() ?? 'gray'],
                ];
            }),
            // numbers the rule texts are built from
            'aturan' => [
                'batasBayar' => (int) $config['BATAS_BAYAR_JAM'],
                'batasKonfirmasi' => (int) $config['BATAS_KONFIRMASI_JAM'],
                'batasPickup' => (int) $config['BATAS_BAYAR_PICKUP_JAM'],
                'jedaFollowup' => (int) $config['JEDA_FOLLOWUP_JAM'],
                // "Malam (21.00–07.00)"
                'malam' => $this->jamTitik($jamKerja->selesai).'–'.$this->jamTitik($jamKerja->mulai),
                'arsipOrder' => (int) $config['ARSIP_ORDER_HARI'],
                'arsipCustomer' => (int) $config['ARSIP_CUSTOMER_HARI'],
            ],
            'template' => $this->template((int) $config['JEDA_FOLLOWUP_JAM'], (int) $config['JEDA_KESAN_HARI']),
            'laporan' => $this->laporan(),
            'pesanSistem' => $this->pesanSistem(),
        ]);
    }

    /**
     * A template's switch: saved at once and logged.
     */
    public function ubahTemplate(Request $request, TemplateFollowup $template): RedirectResponse
    {
        $aktif = $request->validate(['aktif' => ['required', 'boolean']])['aktif'];

        if ((bool) $aktif !== $template->status_aktif) {
            $template->update(['status_aktif' => (bool) $aktif]);

            Aktivitas::catat(
                $request->user() ?? abort(403),
                KategoriAktivitas::Otomasi,
                'Ubah template sapaan',
                $template->kode.' · '.($aktif ? 'nonaktif → aktif' : 'aktif → nonaktif'),
                $template,
            );
        }

        Inertia::flash('toast', $aktif ? "Template {$template->kode} diaktifkan." : "Template {$template->kode} dinonaktifkan.");

        return back();
    }

    /**
     * The four numbers at the top.
     *
     * @param  Collection<int, Workflow>  $workflow
     * @param  array<string, string|null>  $config
     * @return list<array{label: string, nilai: string, meta: string|null}>
     */
    private function ringkasan(Collection $workflow, array $config, JamKerja $jamKerja): array
    {
        $hariIni = now()->startOfDay();

        // Workflow aktif · "Notifikasi Error siaga · 1 error hari ini"
        $berjalan = $workflow->filter(fn (Workflow $w) => in_array($w->status, StatusWorkflow::berjalan(), true));
        $siaga = $workflow->where('status', StatusWorkflow::Siaga->value)->pluck('nama');
        $error = WorkflowEvent::query()->where('jenis', 'EKSEKUSI')->where('status', 'GAGAL')->where('waktu', '>=', $hariIni)->count();

        // Pesan otomatis hari ini · "12 sapaan prospek · 3 tanya kesan · 1 pengingat bayar"
        $otomatis = Pesan::query()
            ->where('pengirim', PengirimPesan::Adiva)
            ->where('waktu', '>=', $hariIni)
            ->whereIn('meta->otomatis', array_keys(self::PESAN_OTOMATIS))
            ->toBase()
            ->selectRaw("meta->>'otomatis' as jenis, count(*) as jumlah")
            ->groupBy('jenis')
            ->pluck('jumlah', 'jenis');

        // Order ditutup otomatis · "belum di-ACC lewat 24 jam kerja · 09.15"
        // today's Batas Bayar runs are few (one an hour), so the count is read in PHP
        $tutup = WorkflowEvent::query()
            ->whereHas('workflow', fn ($q) => $q->where('kode', 'batas_bayar'))
            ->where('jenis', 'EKSEKUSI')
            ->where('waktu', '>=', $hariIni)
            ->orderBy('waktu')
            ->get()
            ->filter(fn (WorkflowEvent $e) => (int) ($e->data['ditutup'] ?? 0) > 0);

        // Jam kerja otomatis · "Minggu libur · sekarang di jam kerja"

        return [
            [
                'label' => 'Workflow aktif',
                'nilai' => (string) $berjalan->count(),
                'meta' => collect([$siaga->isNotEmpty() ? $siaga->implode(', ').' siaga' : null, "{$error} error hari ini"])->filter()->implode(' · '),
            ],
            [
                'label' => 'Pesan otomatis hari ini',
                'nilai' => (string) $otomatis->sum(),
                'meta' => collect(self::PESAN_OTOMATIS)
                    ->filter(fn (string $label, string $jenis) => ($otomatis[$jenis] ?? 0) > 0)
                    ->map(fn (string $label, string $jenis) => $otomatis[$jenis].' '.$label)
                    ->implode(' · ') ?: null,
            ],
            [
                'label' => 'Order ditutup otomatis',
                'nilai' => (string) $tutup->sum(fn (WorkflowEvent $e) => (int) ($e->data['ditutup'] ?? 0)),
                'meta' => collect([
                    "belum di-ACC lewat {$config['BATAS_KONFIRMASI_JAM']} jam kerja",
                    $tutup->last()?->waktu->format('H.i'),
                ])->filter()->implode(' · '),
            ],
            [
                'label' => 'Jam kerja otomatis',
                'nilai' => $this->jamTitik($jamKerja->mulai).'–'.$this->jamTitik($jamKerja->selesai),
                'meta' => collect([
                    $jamKerja->libur !== [] ? implode(', ', $jamKerja->libur).' libur' : null,
                    $jamKerja->berlaku(now()) ? 'sekarang di jam kerja' : 'sekarang di luar jam kerja',
                ])->filter()->implode(' · '),
            ],
        ];
    }

    /**
     * Each workflow's latest run (one row per workflow, however many runs there are).
     *
     * @return Collection<int, WorkflowEvent>
     */
    private function eksekusiTerakhir(): Collection
    {
        return WorkflowEvent::query()
            ->selectRaw('distinct on (workflow_id) *')
            ->where('jenis', 'EKSEKUSI')
            ->whereNotNull('workflow_id')
            ->orderBy('workflow_id')
            ->orderByDesc('waktu')
            ->get()
            ->keyBy('workflow_id')
            ->toBase();
    }

    /**
     * The first message of each follow-up (FU-01 … FU-05), in funnel order.
     *
     * @return list<array{id: int, kode: string, judul: string|null, aktif: bool, jeda: string, tahap: string, pesan: string}>
     */
    private function template(int $jedaJam, int $kesanHari): array
    {
        $urutan = array_map(fn (TahapFunnel $t) => $t->value, TahapFunnel::cases());

        $template = TemplateFollowup::query()
            ->where('kode', '~', '^FU-[0-9]+$')
            ->get()
            ->sortBy(function (TemplateFollowup $t) use ($urutan) {
                $posisi = array_search($t->daftarTahap()[0] ?? '', $urutan, true);

                // SELESAI (an order status, not a funnel stage) comes last
                return [$posisi === false ? PHP_INT_MAX : $posisi, $t->kode];
            })
            ->map(fn (TemplateFollowup $t) => [
                'id' => $t->id,
                'kode' => $t->kode,
                'judul' => $t->judul,
                'aktif' => $t->status_aktif,
                'jeda' => match ($t->acuan_waktu) {
                    'SELESAI' => "{$kesanHari} hari setelah SELESAI",
                    'ORDER_DITUTUP' => "{$jedaJam} jam setelah order ditutup",
                    default => "{$jedaJam} jam setelah chat terakhir",
                },
                'tahap' => collect([implode(' · ', $t->daftarTahap()), $t->kondisi_pemicu])->filter()->implode(' · '),
                'pesan' => $t->pesan,
            ]);

        return array_values($template->all());
    }

    /**
     * The latest daily report, as the supervisor received it on WhatsApp.
     *
     * @return array{teks: string, jam: string, hari: string}|null
     */
    private function laporan(): ?array
    {
        $laporan = WorkflowEvent::query()->where('jenis', 'LAPORAN')->latest('waktu')->first();

        if (! $laporan || ! is_string($teks = $laporan->data['pesan'] ?? null)) {
            return null;
        }

        return [
            'teks' => $teks,
            'jam' => $laporan->waktu->format('H.i'),
            'hari' => match (true) {
                $laporan->waktu->isToday() => 'hari ini',
                $laporan->waktu->isYesterday() => 'kemarin',
                default => $laporan->waktu->translatedFormat('j M'),
            },
        ];
    }

    /**
     * The latest supervisor message of each kind a workflow sends.
     *
     * @return list<array{kode: string, judul: string, nada: string, waktu: string, workflow: string|null, teks: string, catatan: string}>
     */
    private function pesanSistem(): array
    {
        $terbaru = WorkflowEvent::query()
            ->selectRaw("distinct on (data->>'jenis_pesan') *")
            ->whereIn('data->jenis_pesan', array_keys(self::PESAN_SISTEM))
            ->whereNotNull('data->pesan_supervisor')
            ->orderByRaw("data->>'jenis_pesan'")
            ->orderByDesc('waktu')
            ->with('workflow:id,nama')
            ->get()
            ->keyBy(fn (WorkflowEvent $e) => (string) ($e->data['jenis_pesan'] ?? ''));

        $pesan = collect(self::PESAN_SISTEM)
            ->filter(fn (array $jenis, string $kode) => $terbaru->has($kode))
            ->map(function (array $jenis, string $kode) use ($terbaru) {
                $event = $terbaru[$kode];

                return [
                    'kode' => $kode,
                    'judul' => $jenis[0],
                    'nada' => $jenis[1],
                    'waktu' => Waktu::relatif($event->waktu, sebutHariIni: true),
                    'workflow' => $event->workflow?->nama,
                    'teks' => Str::limit((string) ($event->data['pesan_supervisor'] ?? ''), 150, '…'),
                    'catatan' => $jenis[2],
                ];
            });

        return array_values($pesan->all());
    }

    /**
     * "07:00" → "07.00", as the design writes times in rule texts.
     */
    private function jamTitik(string $jam): string
    {
        return str_replace(':', '.', trim($jam));
    }
}
