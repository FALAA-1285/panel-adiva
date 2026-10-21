<?php

namespace App\Support;

use App\Jobs\KabariKonfigN8n;
use App\Models\User;
use App\Models\Workflow;

/**
 * Settings.dc.html, "AI & perilaku bot": model, temperature, chat memory and the
 * merge wait live in the chatbot workflow, not in CONFIG_TOKO. The panel keeps them
 * on workflow.pengaturan, which n8n reads at the start of every chat.
 */
class PengaturanAi
{
    /**
     * Panel key => key in workflow.pengaturan.
     *
     * @var array<string, string>
     */
    public const KUNCI = [
        'AI_MODEL_UTAMA' => 'model_utama',
        'AI_MODEL_CADANGAN' => 'model_cadangan',
        'AI_TEMPERATURE' => 'temperature',
        'AI_INGATAN_CHAT' => 'ingatan_pesan',
        'AI_TUNGGU_GABUNG' => 'tunggu_gabung_detik',
    ];

    /**
     * The model choices in the design.
     *
     * @var list<string>
     */
    public const MODEL = ['gemini-3.6-flash', 'gemini-flash-latest'];

    /**
     * @return array<string, string|null> panel key => value
     */
    public static function nilai(): array
    {
        $pengaturan = self::chatbot()->pengaturan ?? [];

        return collect(self::KUNCI)
            ->map(fn (string $kolom) => isset($pengaturan[$kolom]) ? (string) $pengaturan[$kolom] : null)
            ->all();
    }

    /**
     * Save the changed values and log each one like a CONFIG_TOKO change.
     *
     * @param  array<string, string>  $baru  panel key => value
     */
    public static function ubahBanyak(array $baru, User $oleh, ?string $perangkat = null): void
    {
        $chatbot = self::chatbot();
        $pengaturan = $chatbot->pengaturan ?? [];
        $sekarang = self::nilai();
        $berubah = array_filter($baru, fn (string $nilai, string $kunci) => ($sekarang[$kunci] ?? '') !== $nilai, ARRAY_FILTER_USE_BOTH);

        if ($berubah === []) {
            return;
        }

        foreach ($berubah as $kunci => $nilai) {
            // numbers stay numbers for n8n
            $pengaturan[self::KUNCI[$kunci]] = is_numeric($nilai) ? $nilai + 0 : $nilai;
        }

        $chatbot->update(['pengaturan' => $pengaturan]);

        foreach ($berubah as $kunci => $nilai) {
            PengaturanToko::catat($kunci, $sekarang[$kunci] ?? null, $nilai, $oleh, $perangkat, $chatbot);
        }

        // n8n reads workflow.pengaturan itself; this is only a notice
        KabariKonfigN8n::dispatch('PENGATURAN_AI', ['pengaturan' => $pengaturan]);
    }

    private static function chatbot(): Workflow
    {
        return Workflow::query()->firstOrCreate(['kode' => 'chatbot'], ['nama' => 'chatbot bangbana (main)']);
    }
}
