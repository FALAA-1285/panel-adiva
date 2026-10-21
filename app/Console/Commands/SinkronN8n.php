<?php

namespace App\Console\Commands;

use App\Enums\StatusWorkflow;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads the n8n public API (N8N_API_URL / N8N_API_KEY) so Otomasi and Monitoring follow
 * n8n: which workflow is which, whether it is switched on, and every finished run
 * (workflow_event EKSEKUSI). A run the workflow already wrote itself (with its jalur,
 * model, …) is only completed, never doubled. See docs/kontrak-n8n.md §8.
 */
#[Signature('n8n:sinkron')]
#[Description('Sinkronkan workflow dan eksekusi dari n8n')]
class SinkronN8n extends Command
{
    /**
     * Title of a run nobody wrote a row for, per workflow (as the design writes them).
     *
     * @var array<string, string>
     */
    private const JUDUL = [
        'chatbot' => 'Pesan dijawab',
        'batas_bayar' => 'Cek batas bayar',
        'prospek_ulang' => 'Sapaan prospek',
        'pantau_koneksi' => 'Cek koneksi',
        'laporan_harian' => 'Laporan harian',
        'arsip_data' => 'Arsip data',
        'notifikasi_error' => 'Kabar error',
        'chatbot_sosmed' => 'DM dijawab',
    ];

    /**
     * Workflows that came after the panel's list, added once n8n has them:
     * kode => [name in n8n, jadwal, keterangan, deskripsi].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const TAMBAHAN = [
        'chatbot_sosmed' => ['Chatbot Sosmed', 'Setiap DM masuk', 'Instagram & Facebook', 'Menjawab DM Instagram & Facebook; kontak dan isi chatnya ada di panel → Instagram & Facebook'],
    ];

    public function handle(): int
    {
        $url = rtrim((string) config('services.n8n.api_url'), '/');
        $kunci = (string) config('services.n8n.api_key');

        if ($url === '' || $kunci === '') {
            $this->error('N8N_API_URL dan N8N_API_KEY belum diisi');

            return self::FAILURE;
        }

        $api = Http::baseUrl($url.'/api/v1')->withHeaders(['X-N8N-API-KEY' => $kunci])->timeout(20)->acceptJson();

        try {
            /** @var list<array{id: string, name: string, active: bool, nodes?: list<array{type: string}>}> $daftar */
            $daftar = $api->get('workflows', ['limit' => 250])->throw()->json('data') ?? [];
        } catch (ConnectionException $e) {
            $this->error("n8n tidak bisa dihubungi ({$e->getMessage()})");

            return self::FAILURE;
        }

        $baru = 0;

        foreach (self::TAMBAHAN as $kode => [$nama, $jadwal, $keterangan, $deskripsi]) {
            if (collect($daftar)->contains(fn (array $w) => Str::contains(Str::lower($w['name']), Str::lower($nama)))) {
                Workflow::query()->firstOrCreate(['kode' => $kode], [
                    'nama' => $nama, 'jadwal' => $jadwal, 'jadwal_keterangan' => $keterangan, 'deskripsi' => $deskripsi,
                    'status' => StatusWorkflow::Nonaktif->value, 'jumlah_node' => 0,
                ]);
            }
        }

        foreach (Workflow::query()->orderBy('id')->get() as $workflow) {
            $n8n = collect($daftar)->firstWhere('id', $workflow->n8n_workflow_id)
                ?? collect($daftar)->first(fn (array $w) => Str::contains(Str::lower($w['name']), Str::lower($workflow->nama)));

            if ($n8n === null) {
                continue;
            }

            $workflow->update([
                'n8n_workflow_id' => $n8n['id'],
                'jumlah_node' => collect($n8n['nodes'] ?? [])->reject(fn (array $node) => $node['type'] === 'n8n-nodes-base.stickyNote')->count(),
                'status' => match (true) {
                    ! $n8n['active'] => StatusWorkflow::Nonaktif,
                    $workflow->kode === 'notifikasi_error' => StatusWorkflow::Siaga,
                    default => StatusWorkflow::Aktif,
                },
            ]);

            $baru += $this->eksekusi($api, $workflow);
        }

        $this->info("Sinkron n8n selesai · {$baru} eksekusi baru");

        return self::SUCCESS;
    }

    /**
     * The workflow's latest finished runs. A chatbot run that ended without error but
     * wrote no row was a message folded into a later run (Gabung Pesan), so it is skipped.
     */
    private function eksekusi(PendingRequest $api, Workflow $workflow): int
    {
        /** @var list<array{id: string|int, status: string, startedAt: string|null, stoppedAt: string|null}> $daftar */
        $daftar = $api->get('executions', ['workflowId' => $workflow->n8n_workflow_id, 'limit' => 100])->json('data') ?? [];
        $baru = 0;

        foreach ($daftar as $run) {
            if (! in_array($run['status'], ['success', 'error', 'crashed'], true) || $run['startedAt'] === null) {
                continue;
            }

            $gagal = $run['status'] !== 'success';
            $mulai = Carbon::parse($run['startedAt']);
            $durasi = $run['stoppedAt'] !== null ? (int) $mulai->diffInMilliseconds(Carbon::parse($run['stoppedAt'])) : null;
            $ada = WorkflowEvent::query()->where('jenis', 'EKSEKUSI')->where('n8n_execution_id', (string) $run['id'])->first();

            if ($ada !== null) {
                $ada->update([
                    'durasi_ms' => $ada->durasi_ms ?? $durasi,
                    'status' => $gagal ? 'GAGAL' : $ada->status,
                ]);

                continue;
            }

            if ($workflow->kode === 'chatbot' && ! $gagal) {
                continue;
            }

            WorkflowEvent::query()->create([
                'workflow_id' => $workflow->id,
                'waktu' => $mulai,
                'jenis' => 'EKSEKUSI',
                'status' => $gagal ? 'GAGAL' : 'BERHASIL',
                'judul' => $gagal ? 'Eksekusi gagal' : (self::JUDUL[$workflow->kode] ?? $workflow->nama),
                'n8n_execution_id' => (string) $run['id'],
                'durasi_ms' => $durasi,
            ]);
            $baru++;
        }

        return $baru;
    }
}
