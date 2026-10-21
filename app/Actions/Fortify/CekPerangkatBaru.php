<?php

namespace App\Actions\Fortify;

use App\Bot\KirimKeStaf;
use App\Models\User;
use App\Support\KodeVerifikasi;
use App\Support\NomorWa;
use App\Support\PengaturanKeamanan;
use App\Support\PerangkatDikenal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * "Kode WhatsApp di perangkat baru": the right password on an unknown browser does not
 * sign in yet. A 6-digit code goes to the staff member's WhatsApp, and Masuk asks for
 * it (MasukKodeController::verifikasi finishes the sign-in).
 */
class CekPerangkatBaru
{
    public function __construct(private KirimKeStaf $kirimKeStaf) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! PengaturanKeamanan::kodePerangkatBaru() || ! Fortify::$authenticateUsingCallback) {
            return $next($request);
        }

        $staf = call_user_func(Fortify::$authenticateUsingCallback, $request);

        // a wrong password is reported by AttemptToAuthenticate as before
        if (! $staf instanceof User || PerangkatDikenal::kenal($request, $staf)) {
            return $next($request);
        }

        if (blank($staf->nomor_wa)) {
            throw ValidationException::withMessages(['email' => 'Nomor WhatsApp Anda belum terdaftar. Minta supervisor mengisinya di Tim & akses.']);
        }

        $kode = KodeVerifikasi::buat();

        try {
            KodeVerifikasi::kirim($this->kirimKeStaf, (string) $staf->nomor_wa, "Kode masuk panel Adiva: {$kode}");
        } catch (ValidationException) {
            throw ValidationException::withMessages(['email' => 'Kode untuk perangkat baru gagal dikirim ke WhatsApp. Coba lagi sebentar.']);
        }

        $request->session()->put('masuk', [
            'user_id' => $staf->id,
            'kode' => KodeVerifikasi::hash($kode),
            'berlaku_sampai' => now()->addMinutes(5)->getTimestamp(),
            'percobaan' => 0,
            'remember' => $request->boolean('remember'),
            'alasan' => 'perangkat',
            'nomor' => NomorWa::samarkan((string) $staf->nomor_wa),
        ]);

        return redirect()->route('login');
    }

    /**
     * Masuk shows the code step straight away while such a sign-in is waiting.
     *
     * @return array{nomor: string}|null
     */
    public static function menunggu(Request $request): ?array
    {
        $masuk = $request->session()->get('masuk');

        return is_array($masuk) && ($masuk['alasan'] ?? null) === 'perangkat' && now()->getTimestamp() <= ($masuk['berlaku_sampai'] ?? 0)
            ? ['nomor' => (string) ($masuk['nomor'] ?? '')]
            : null;
    }
}
