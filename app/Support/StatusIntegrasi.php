<?php

namespace App\Support;

use App\Enums\StatusWorkflow;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * How Adiva's parts are doing: the chatbot's runs and models, the WhatsApp numbers
 * and the workflows (Monitoring.dc.html "Integrasi", Main.dc.html "Status Adiva").
 */
class StatusIntegrasi
{
    public function __construct(public readonly ?Workflow $chatbot) {}

    public static function sekarang(): self
    {
        return new self(Workflow::query()->where('kode', 'chatbot')->first());
    }

    /**
     * The chatbot's runs since a moment: one per batch of messages it answered.
     */
    public function chatbot(CarbonInterface $dari, ?CarbonInterface $sampai = null): Builder
    {
        return WorkflowEvent::query()->toBase()
            ->where('jenis', 'EKSEKUSI')
            ->where('workflow_id', $this->chatbot->id ?? 0)
            ->where('waktu', '>=', $dari)
            ->when($sampai, fn (Builder $q, CarbonInterface $s) => $q->where('waktu', '<=', $s));
    }

    /**
     * Today's answers: the main model's median time, the backup model's uses and the
     * shipping-cost checks.
     *
     * @return array{p50: float, cadangan: int, ongkir: int}
     */
    public function modelHariIni(): array
    {
        $model = $this->chatbot(now()->startOfDay())
            ->where('status', 'BERHASIL')
            ->selectRaw("percentile_cont(0.5) within group (order by (data->>'model_ms')::int) as p50")
            ->selectRaw("count(*) filter (where data->>'model' = ?) as cadangan", [$this->modelCadangan()])
            ->selectRaw("count(*) filter (where (data->>'ongkir')::boolean) as ongkir")
            ->first();

        return [
            'p50' => (float) ($model->p50 ?? 0) / 1000,
            'cadangan' => (int) ($model->cadangan ?? 0),
            'ongkir' => (int) ($model->ongkir ?? 0),
        ];
    }

    public function modelCadangan(): string
    {
        return (string) ($this->chatbot->pengaturan['model_cadangan'] ?? '');
    }

    /**
     * "gemini-3.6-flash" → "Gemini 3.6 Flash".
     */
    public function namaModel(string $kunci): string
    {
        return Str::of((string) ($this->chatbot->pengaturan[$kunci] ?? ''))->replace('-', ' ')->title()->value();
    }

    /**
     * "7 workflow aktif" (the standby one counts).
     */
    public function workflowAktif(): int
    {
        return Workflow::query()->whereIn('status', StatusWorkflow::berjalan())->count();
    }

    /**
     * Workflows that failed today (and set off Notifikasi Error).
     */
    public function errorHariIni(): int
    {
        return WorkflowEvent::query()->where('jenis', 'EKSEKUSI')->where('status', 'GAGAL')->where('waktu', '>=', now()->startOfDay())->count();
    }

    /**
     * Every connection check, oldest first.
     *
     * @return Collection<int, WorkflowEvent>
     */
    public function koneksi(): Collection
    {
        return WorkflowEvent::query()->where('jenis', 'KONEKSI')->orderBy('waktu')->get()->toBase();
    }

    /**
     * The latest check of each WAHA session.
     *
     * @return array<string, WorkflowEvent|null>
     */
    public function koneksiTerakhir(): array
    {
        $koneksi = $this->koneksi();

        return collect(NomorBot::daftar())
            ->map(fn (string $label, string $sesi) => $koneksi->last(fn (WorkflowEvent $k) => ($k->data['sesi'] ?? null) === $sesi))
            ->all();
    }

    /**
     * When Pantau Koneksi WhatsApp last ran.
     */
    public function terakhirDicek(): ?CarbonInterface
    {
        $pantau = Workflow::query()->where('kode', 'pantau_koneksi')->value('id');

        return WorkflowEvent::query()->where('workflow_id', $pantau ?? 0)->latest('waktu')->first()?->waktu;
    }
}
