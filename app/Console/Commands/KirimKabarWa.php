<?php

namespace App\Console\Commands;

use App\Bot\KirimKeStaf;
use App\Bot\PengirimanGagal;
use App\Models\Notifikasi;
use App\Support\Demo;
use App\Support\KabarWa;
use App\Support\PengaturanToko;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sends the supervisor (or Bu Sarah) a short WhatsApp for each new notification n8n wrote
 * (docs/kontrak-n8n.md §9). Scheduled every 15 seconds; each notification goes out once.
 */
#[Signature('notifikasi:kirim-wa')]
#[Description('Kirim kabar WhatsApp singkat untuk notifikasi baru')]
class KirimKabarWa extends Command
{
    public function handle(KirimKeStaf $wa): int
    {
        // the demo notifications (DemoSeeder) are the design's, not news
        if (Demo::aktif()) {
            $this->line('Mode demo: tidak ada kabar yang dikirim.');

            return self::SUCCESS;
        }

        $mati = KabarWa::mati();
        $nomor = PengaturanToko::nilaiBanyak(['ADMIN_WA', 'SARAH_WA']);

        Notifikasi::query()
            ->whereNull('wa_status')
            ->where('waktu', '>=', now()->subMinutes(KabarWa::UMUR_MENIT))
            ->with('order')
            // by id: the rows change while they are read
            ->lazyById()
            ->each(function (Notifikasi $n) use ($wa, $mati, $nomor) {
                $baris = KabarWa::baris($n);
                $tujuan = $nomor[$n->penerima === 'SARAH_WA' ? 'SARAH_WA' : 'ADMIN_WA'] ?: $nomor['ADMIN_WA'];

                $status = match (true) {
                    $baris === null => 'PANEL',
                    in_array($baris, $mati, true) => 'MATI',
                    blank($tujuan) => 'GAGAL',
                    default => null,
                };

                if ($status === null) {
                    try {
                        $wa->kirim((string) $tujuan, KabarWa::teks($n));
                        $status = 'TERKIRIM';
                    } catch (PengirimanGagal $e) {
                        $status = 'GAGAL';
                        $this->warn("Notifikasi {$n->id}: {$e->getMessage()}");
                    }
                }

                $n->forceFill(['wa_status' => $status, 'wa_dikirim_at' => $status === 'TERKIRIM' ? now() : null])->save();
            });

        return self::SUCCESS;
    }
}
