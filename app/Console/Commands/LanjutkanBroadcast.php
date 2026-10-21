<?php

namespace App\Console\Commands;

use App\Enums\StatusBroadcast;
use App\Models\Broadcast;
use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Restarts the sending chain of a campaign whose next message is overdue (the queue
 * was cleared, or the worker was down when it was due). Scheduled every 5 minutes.
 */
#[Signature('broadcast:lanjutkan')]
#[Description('Lanjutkan kampanye broadcast yang pengirimannya macet')]
class LanjutkanBroadcast extends Command
{
    /** Minutes late before a chain counts as stuck. */
    private const TERLAMBAT_MENIT = 2;

    public function handle(): int
    {
        // the demo campaigns (DemoSeeder) stay exactly as the design shows them
        if (Demo::aktif()) {
            $this->line('Mode demo: tidak ada yang dilanjutkan.');

            return self::SUCCESS;
        }

        $macet = Broadcast::query()
            ->whereIn('status', [StatusBroadcast::Mengirim, StatusBroadcast::Terjadwal])
            ->where(fn ($q) => $q->whereNull('berikutnya_at')->orWhere('berikutnya_at', '<', now()->subMinutes(self::TERLAMBAT_MENIT)))
            ->get();

        foreach ($macet as $broadcast) {
            $broadcast->mulaiKirim();
            $this->line("Dilanjutkan: {$broadcast->nama}");
        }

        return self::SUCCESS;
    }
}
