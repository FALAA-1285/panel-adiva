<?php

namespace App\Http\Controllers\Katalog;

use App\Accurate\AccurateGagal;
use App\Accurate\OauthAccurate;
use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Controller;
use App\Jobs\SinkronkanBarangToko;
use App\Models\AksesAccurate;
use App\Support\Aktivitas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sambungkan Accurate" on Katalog → Barang toko: OAuth with the app's Client ID and Secret.
 * Accurate sends the supervisor back to /accurate/kembali, which must be the app's OAuth
 * Callback URL in Accurate's Developer Area.
 */
class AccurateController extends Controller
{
    /** how long a started connection waits for Accurate to send the supervisor back */
    private const TUNGGU_MENIT = 15;

    public function sambungkan(Request $request, OauthAccurate $oauth): Response
    {
        if (! OauthAccurate::siap()) {
            return to_route('katalog.toko')->withErrors(['accurate' => 'ACCURATE_CLIENT_ID dan ACCURATE_CLIENT_SECRET belum diisi di server.']);
        }

        $state = Str::random(40);
        $request->session()->put('accurate_oauth', ['state' => $state, 'sejak' => time()]);

        // a full page visit to Accurate, also from an Inertia request
        return Inertia::location($oauth->urlMasuk($state));
    }

    public function kembali(Request $request, OauthAccurate $oauth): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $mulai = $request->session()->pull('accurate_oauth');

        // only the connection this supervisor started a moment ago, with its state when Accurate sends it back
        $sah = is_array($mulai)
            && time() - (int) ($mulai['sejak'] ?? 0) <= self::TUNGGU_MENIT * 60
            && (! $request->filled('state') || hash_equals((string) $mulai['state'], (string) $request->query('state')));

        if (! $sah) {
            return to_route('katalog.toko')->withErrors(['accurate' => 'Sambungan tidak dikenali atau sudah lewat. Tekan Sambungkan Accurate sekali lagi.']);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return to_route('katalog.toko')->withErrors(['accurate' => 'Akses tidak diberikan di Accurate'.($request->filled('error') ? ' ('.Str::limit((string) $request->query('error'), 60).').' : '.')]);
        }

        try {
            $oauth->sambungkan((string) $request->query('code'), $user);
        } catch (AccurateGagal $e) {
            return to_route('katalog.toko')->withErrors(['accurate' => 'Accurate belum tersambung: '.$e->getMessage()]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Sambungkan Accurate', 'Database Bana Food · akses lihat barang');
        SinkronkanBarangToko::dispatchSync($user);
        Inertia::flash('toast', 'Accurate tersambung. Barang toko sedang diambil.');

        return to_route('katalog.toko');
    }

    public function putuskan(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        AksesAccurate::query()->delete();

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Putuskan Accurate', 'Barang toko tidak diperbarui lagi sampai disambungkan ulang');
        Inertia::flash('toast', 'Sambungan Accurate diputus. Data barang toko yang ada tetap disimpan.');

        return to_route('katalog.toko');
    }
}
