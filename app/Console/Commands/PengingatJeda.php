<?php

namespace App\Console\Commands;

use App\Enums\JenisNotifikasi;
use App\Models\JedaBot;
use App\Support\KabarBot;
use App\Support\NomorBot;
use Illuminate\Console\Command;

/**
 * Every morning the supervisor is reminded who the bot is still paused for, so nobody is left
 * waiting (n8n's 🟪 B2). Nothing is written when no one is paused.
 */
class PengingatJeda extends Command
{
    protected $signature = 'jeda:ingatkan';

    protected $description = 'Ingatkan supervisor siapa saja yang botnya masih dijeda (panel → Jeda)';

    public function handle(): int
    {
        $jeda = JedaBot::query()->aktif()->orderBy('id')->get();

        if ($jeda->isEmpty()) {
            $this->info('Tidak ada bot yang dijeda.');

            return self::SUCCESS;
        }

        $nomorBot = NomorBot::daftar();
        $banyakNomor = count($nomorBot) > 1;
        $hariIni = now()->timezone('Asia/Jakarta')->format('Y-m-d');

        $baris = $jeda->values()->map(function (JedaBot $j, int $i) use ($nomorBot, $banyakNomor) {
            $kunci = $j->nama ?: ($j->chat_id ?: $j->nomor);
            $hari = (int) floor($j->sejak->diffInDays(now()));
            $lewat = $j->sampai !== null && $j->sampai->isPast();
            $label = $banyakNomor && filled($j->sesi_wa) ? "\n   📱 nomor bot: ".($nomorBot[$j->sesi_wa] ?? $j->sesi_wa) : '';

            return ($i + 1).". *{$kunci}*"
                .'  ('.($hari <= 0 ? 'hari ini' : "{$hari} hari").')'.$label
                .($j->sampai !== null
                    ? ($lewat ? "\n   ⏱️ batas waktu sudah lewat — bot aktif lagi otomatis saat customer chat" : "\n   ⏱️ otomatis aktif: ".$j->sampai->timezone('Asia/Jakarta')->format('Y-m-d H:i'))
                    : '')
                .($j->alasan ? "\n   alasan: {$j->alasan}" : '')
                ."\n   selesaikan dari panel → Jeda (Lanjutkan bot), tulis catatan hasil obrolan";
        })->implode("\n\n");

        $pesan = "⏸️ *PENGINGAT: BOT MASIH DIJEDA*\n{$hariIni}\n\n"
            .$jeda->count()." customer belum dilayani bot dan tidak ikut follow-up otomatis:\n\n"
            .$baris
            ."\n\n— — —\nKalau sudah selesai ditangani, lanjutkan botnya dari panel → Jeda supaya bot menyambung konteksnya.";

        KabarBot::notifikasi(JenisNotifikasi::PengingatJeda, 'Pengingat daftar jeda', mb_substr($pesan, 0, 300));

        $this->info($jeda->count().' customer dijeda, pengingat ditulis ke Notifikasi.');

        return self::SUCCESS;
    }
}
