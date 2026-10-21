<?php

namespace App\Console\Commands;

use App\Enums\StatusPrompt;
use App\Models\PromptVersi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Once, when n8n starts reading the prompt from the panel: the system prompt written in
 * the chatbot's "Agen AI Penjawab" node becomes the active prompt version, one section
 * per "1. IDENTITAS" block, so Adiva answers the same as before.
 */
#[Signature('prompt:impor-n8n {berkas? : JSON workflow chatbot (cadangan); tanpa ini dibaca dari n8n}')]
#[Description('Jadikan system prompt di node n8n sebagai versi prompt aktif')]
class ImporPromptN8n extends Command
{
    private const NODE = 'Agen AI Penjawab';

    public function handle(): int
    {
        $teks = self::systemPrompt($this->workflow());

        if ($teks === '' || str_starts_with($teks, '=')) {
            $this->error('System prompt di node "'.self::NODE.'" kosong atau sudah dibaca dari panel. Pakai berkas cadangan workflow.');

            return self::FAILURE;
        }

        $bagian = self::pecah($teks);

        if ($bagian === []) {
            $this->error('Tidak ada bagian bernomor ("1. IDENTITAS") di system prompt.');

            return self::FAILURE;
        }

        $versi = DB::transaction(function () use ($bagian) {
            PromptVersi::query()->whereIn('status', [StatusPrompt::Aktif, StatusPrompt::Draf])->update(['status' => StatusPrompt::Arsip]);

            return PromptVersi::query()->create([
                'versi' => (int) PromptVersi::query()->max('versi') + 1,
                'ringkasan' => 'Diimpor dari n8n',
                'bagian' => $bagian,
                'status' => StatusPrompt::Aktif,
                'diterbitkan_at' => now(),
            ]);
        });

        $this->info("Prompt v{$versi->versi} aktif · ".count($bagian).' bagian');

        return self::SUCCESS;
    }

    /**
     * "════\n1. IDENTITAS\n════\nisi…" blocks become sections; the title lines before the
     * first block are left out.
     *
     * @return list<array{no: int, judul: string, teks: string}>
     */
    public static function pecah(string $teks): array
    {
        $teks = str_replace("\r\n", "\n", $teks);
        preg_match_all('/^═{3,}\n(\d+)\.[ \t]*([^\n]+)\n═{3,}\n(.*?)(?=^═{3,}\n\d+\.|\z)/msu', $teks, $cocok, PREG_SET_ORDER);

        return array_map(fn (array $c) => [
            'no' => (int) $c[1],
            'judul' => trim($c[2]),
            'teks' => trim($c[3]),
        ], $cocok);
    }

    /**
     * The systemMessage of the "Agen AI Penjawab" node in a workflow export.
     */
    public static function systemPrompt(mixed $workflow): string
    {
        $nodes = is_array($workflow) && is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [];

        foreach ($nodes as $node) {
            if (is_array($node) && ($node['name'] ?? null) === self::NODE) {
                $teks = $node['parameters']['options']['systemMessage'] ?? null;

                return is_string($teks) ? $teks : '';
            }
        }

        return '';
    }

    private function workflow(): mixed
    {
        $berkas = $this->argument('berkas');

        if (is_string($berkas)) {
            return json_decode((string) file_get_contents($berkas), true);
        }

        $api = Http::baseUrl(rtrim((string) config('services.n8n.api_url'), '/').'/api/v1')
            ->withHeaders(['X-N8N-API-KEY' => (string) config('services.n8n.api_key')])
            ->acceptJson();

        $daftar = $api->get('workflows', ['limit' => 250])->throw()->json('data');

        foreach (is_array($daftar) ? $daftar : [] as $w) {
            if (is_array($w) && is_string($w['name'] ?? null) && is_string($w['id'] ?? null) && str_contains(strtolower($w['name']), 'chatbot bangbana')) {
                return $api->get("workflows/{$w['id']}")->throw()->json();
            }
        }

        return null;
    }
}
