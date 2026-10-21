<?php

namespace App\Http\Controllers;

use App\Bot\SesiGagal;
use App\Bot\SesiWaha;
use App\Enums\KategoriAktivitas;
use App\Support\Aktivitas;
use App\Support\NomorBot;
use App\Support\PengaturanToko;
use App\Support\StatusIntegrasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Monitoring, "Lihat QR" and "Restart sesi": both act on the bot number whose last
 * check (Pantau Koneksi) found it disconnected, or the main number when all are fine.
 */
class WahaSesiController extends Controller
{
    /**
     * The QR code from WAHA, opened in a new tab.
     */
    public function qr(SesiWaha $waha): Response
    {
        [$sesi, $label] = $this->sasaran();

        try {
            if ($waha->status($sesi) === 'WORKING') {
                return $this->teks("{$label} sudah terhubung — tidak perlu memindai QR.");
            }

            return response($waha->qr($sesi), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
        } catch (SesiGagal $e) {
            return $this->teks("QR {$label} tidak bisa diambil: {$e->getMessage()}", 502);
        }
    }

    public function restart(Request $request, SesiWaha $waha): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        [$sesi, $label] = $this->sasaran();

        try {
            $waha->restart($sesi);
        } catch (SesiGagal $e) {
            throw ValidationException::withMessages(['waha' => "Restart {$label} gagal: {$e->getMessage()}"]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Restart sesi WAHA', "{$label} ({$sesi})");

        Inertia::flash('toast', "Sesi {$label} dimulai ulang.");

        return back();
    }

    /**
     * @return array{0: string, 1: string} the WAHA session and its label
     */
    private function sasaran(): array
    {
        $nomor = NomorBot::daftar();
        $putus = collect(StatusIntegrasi::sekarang()->koneksiTerakhir())->search(fn ($e) => $e?->status === 'PUTUS');
        $sesi = $putus !== false ? (string) $putus : (PengaturanToko::nilai('SESI_UTAMA') ?: array_key_first($nomor));

        if (blank($sesi)) {
            abort(404, 'Belum ada nomor bot di Pengaturan.');
        }

        return [(string) $sesi, $nomor[$sesi] ?? (string) $sesi];
    }

    private function teks(string $isi, int $status = 200): Response
    {
        return response($isi, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
