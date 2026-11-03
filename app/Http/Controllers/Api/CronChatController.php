<?php

namespace App\Http\Controllers\Api;

use App\Bot\SinkronChatN8n;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The workflow "Bang Bana — Sinkron Chat (panel adiva)" calls this every minute, since Vercel
 * runs no scheduler. Same key as the Accurate cron (ACCURATE_CRON_KEY), and at most one run
 * per 30 seconds whoever calls.
 */
class CronChatController extends Controller
{
    public function sinkron(Request $request, SinkronChatN8n $sinkron): JsonResponse
    {
        $kunci = (string) config('services.accurate.cron_key');

        if (filled($kunci) && ! hash_equals($kunci, (string) ($request->header('X-Cron-Key') ?? $request->bearerToken()))) {
            return response()->json(['sukses' => false, 'pesan' => 'Kunci cron tidak valid.'], 401);
        }

        if (! config('services.n8n.sinkron_chat')) {
            return response()->json(['sukses' => false, 'pesan' => 'Sinkron chat tidak dinyalakan (N8N_SINKRON_CHAT).'], 422);
        }

        if (! RateLimiter::attempt('cron-chat-sinkron', 1, fn () => true, 30)) {
            return response()->json(['sukses' => false, 'pesan' => 'Baru saja disinkronkan.'], 429);
        }

        try {
            $hasil = $sinkron->jalankan();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['sukses' => false, 'pesan' => 'Sinkron chat gagal.'], 502);
        }

        return response()->json(['sukses' => true, ...$hasil]);
    }
}
