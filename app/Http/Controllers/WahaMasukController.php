<?php

namespace App\Http\Controllers;

use App\Jobs\ProsesPesanMasuk;
use App\Models\BroadcastPenerima;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WAHA's webhook: every WhatsApp message the bot's numbers receive (n8n's "Trigger Pesan
 * WhatsApp Masuk"). It only checks the signature and queues the work, so WAHA is answered
 * at once. Only the panel runs the bot when BOT_DRIVER=lokal; otherwise WAHA belongs to n8n.
 */
class WahaMasukController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (Demo::aktif() || config('services.bot.driver') !== 'lokal') {
            return response()->json(['ok' => true, 'diabaikan' => 'BOT_DRIVER bukan lokal'], 202);
        }

        abort_unless($this->sah($request), 401, 'tanda tangan webhook tidak sah');

        $isi = $request->json()->all();

        match ($isi['event'] ?? null) {
            'message' => ProsesPesanMasuk::dispatch($isi),
            'message.ack' => $this->dibaca($isi),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /**
     * WAHA's HMAC of the raw body, in X-Webhook-Hmac; sha512 unless the algorithm header says
     * sha256. Without WAHA_WEBHOOK_SECRET nothing is accepted.
     */
    private function sah(Request $request): bool
    {
        $rahasia = (string) config('services.waha.webhook_secret');
        $algoritma = strtolower((string) ($request->header('X-Webhook-Hmac-Algorithm') ?: 'sha512'));

        if ($rahasia === '' || ! in_array($algoritma, ['sha512', 'sha256'], true)) {
            return false;
        }

        return hash_equals(hash_hmac($algoritma, $request->getContent(), $rahasia), (string) $request->header('X-Webhook-Hmac'));
    }

    /**
     * A campaign message the customer has read (WAHA message.ack READ).
     *
     * @param  array<string, mixed>  $isi
     */
    private function dibaca(array $isi): void
    {
        $pesan = is_array($isi['payload'] ?? null) ? $isi['payload'] : [];
        $baca = ($pesan['ack'] ?? null) === 3 || ($pesan['ackName'] ?? null) === 'READ';

        if ($baca && filled($pesan['id'] ?? null)) {
            BroadcastPenerima::query()->where('wa_message_id', (string) $pesan['id'])->whereNull('dibaca_at')->update(['dibaca_at' => now()]);
        }
    }
}
