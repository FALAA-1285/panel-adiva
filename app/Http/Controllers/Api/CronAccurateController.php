<?php

namespace App\Http\Controllers\Api;

use App\Accurate\SinkronBarangToko;
use App\Http\Controllers\Controller;
use App\Models\AksesAccurate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook/Cron endpoint to trigger Accurate sync from schedulers (n8n, Vercel cron, etc).
 * Protected by ACCURATE_CRON_KEY header or query param.
 */
class CronAccurateController extends Controller
{
    public function sinkron(Request $request, SinkronBarangToko $sinkron): JsonResponse
    {
        $kunci = (string) config('services.accurate.cron_key');

        if (filled($kunci)) {
            $diberikan = $request->header('X-Cron-Key')
                ?? $request->query('kunci')
                ?? $request->bearerToken();

            if (! hash_equals($kunci, (string) $diberikan)) {
                return response()->json([
                    'sukses' => false,
                    'pesan' => 'Kunci cron tidak valid.',
                ], 401);
            }
        }

        if (blank(config('services.accurate.token')) && ! AksesAccurate::query()->exists()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Accurate belum tersambung.',
            ], 422);
        }

        $hasil = $sinkron->jalankan();

        return response()->json([
            'sukses' => $hasil->status === 'BERHASIL',
            'status' => $hasil->status,
            'jumlah' => $hasil->jumlah,
            'galat' => $hasil->galat,
            'mulai' => $hasil->mulai?->toISOString(),
            'selesai' => $hasil->selesai?->toISOString(),
        ]);
    }
}
