<?php

namespace App\Support;

use App\Enums\PengirimPesan;
use App\Models\BroadcastPenerima;
use App\Models\CrmCustomer;
use App\Models\Pesan;

/**
 * A line from a customer goes into the chat (Percakapan), and when it is the first answer to
 * a campaign sent in the last 3 days that campaign recipient is marked answered; "STOP"
 * switches the customer's messages off. It replaces n8n's `n8n_catat_pesan()` for customers.
 */
class CatatPesanMasuk
{
    /** @var string */
    private const STOP = '/^\s*(stop|berhenti|unsubscribe)\s*[.!]*\s*$/i';

    /**
     * @param  string  $jalur  B (while paused), C (attachment) or D (answered by Adiva)
     * @return Pesan|null null when the id is already stored (WAHA may send the same event twice)
     */
    public static function dariCustomer(
        string $chatId,
        ?string $sesi,
        ?string $teks,
        ?string $idWa,
        string $jalur,
        ?string $lampiranUrl = null,
        ?string $keterangan = null,
    ): ?Pesan {
        $chatId = trim($chatId);

        if ($chatId === '') {
            return null;
        }

        if (filled($idWa) && Pesan::query()->where('wa_message_id', $idWa)->exists()) {
            return null;
        }

        $customer = CrmCustomer::query()->where('chat_id', $chatId)->first();

        $pesan = Pesan::create([
            'crm_customer_id' => $customer?->id,
            'chat_id' => $chatId,
            'sesi_wa' => filled($sesi) ? $sesi : null,
            'waktu' => now(),
            'pengirim' => PengirimPesan::Customer,
            'teks' => filled($teks) ? $teks : null,
            'lampiran_url' => filled($lampiranUrl) ? $lampiranUrl : null,
            'lampiran_keterangan' => $keterangan,
            'jalur' => $jalur,
            'status_kirim' => null,
            'wa_message_id' => filled($idWa) ? trim((string) $idWa) : null,
        ]);

        $penerima = BroadcastPenerima::query()
            ->where('chat_id', $chatId)
            ->where('status', BroadcastPenerima::TERKIRIM)
            ->where('terkirim_at', '>', now()->subDays(3))
            ->whereNull('dibalas_at')
            ->orderByDesc('terkirim_at')
            ->first();

        if ($penerima !== null) {
            $stop = (bool) preg_match(self::STOP, (string) $teks);

            $penerima->update([
                'dibalas_at' => now(),
                'balasan' => mb_substr((string) $teks, 0, 500),
                'minta_stop' => $penerima->minta_stop || $stop,
            ]);

            if ($stop && $customer !== null) {
                $customer->update(['status_optout' => true]);
            }
        }

        return $pesan;
    }
}
