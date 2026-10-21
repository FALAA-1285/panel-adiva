<?php

namespace App\Http\Controllers\Auth;

use App\Bot\KirimKeStaf;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\KodeVerifikasi;
use App\Support\NomorWa;
use App\Support\PerangkatDikenal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Masuk, "Kirim kode masuk lewat WhatsApp": sign in with a 6-digit code sent to the
 * staff member's number instead of the password.
 */
class MasukKodeController extends Controller
{
    private const MAKS_PERCOBAAN = 3;

    /**
     * Step 1: the email typed above the button picks the staff member.
     */
    public function kirimKode(Request $request, KirimKeStaf $kirimKeStaf): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email')));

        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'Isi email kerja dulu.']);
        }

        $staf = User::query()->where('email', $email)->whereNull('nonaktif_at')->whereNotNull('password')->whereNotNull('nomor_wa')->first();

        if (! $staf) {
            throw ValidationException::withMessages(['email' => 'Email ini tidak ada di daftar staf aktif.']);
        }

        $kode = KodeVerifikasi::buat();
        KodeVerifikasi::kirim($kirimKeStaf, (string) $staf->nomor_wa, "Kode masuk panel Adiva: {$kode}");

        $request->session()->put('masuk', [
            'user_id' => $staf->id,
            'kode' => KodeVerifikasi::hash($kode),
            'berlaku_sampai' => now()->addMinutes(5)->getTimestamp(),
            'percobaan' => 0,
        ]);

        return response()->json(['nomor' => NomorWa::samarkan((string) $staf->nomor_wa)]);
    }

    /**
     * Step 2: the right code signs the staff member in.
     */
    public function verifikasi(Request $request): RedirectResponse
    {
        $request->validate(['kode' => ['required', 'digits:6']], ['kode.required' => 'Isi 6 digit kode.', 'kode.digits' => 'Isi 6 digit kode.']);

        /** @var array{user_id: int, kode: string, berlaku_sampai: int, percobaan: int, remember?: bool}|null $masuk */
        $masuk = $request->session()->get('masuk');

        if (! $masuk || now()->getTimestamp() > $masuk['berlaku_sampai']) {
            $request->session()->forget('masuk');

            throw ValidationException::withMessages(['kode' => 'Kode sudah tidak berlaku. Kirim ulang kode.']);
        }

        if (! KodeVerifikasi::cocok($request->string('kode'), $masuk['kode'])) {
            $masuk['percobaan']++;

            if ($masuk['percobaan'] >= self::MAKS_PERCOBAAN) {
                $request->session()->forget('masuk');
            } else {
                $request->session()->put('masuk', $masuk);
            }

            throw ValidationException::withMessages(['kode' => 'Kode salah.']);
        }

        $staf = User::query()->whereNull('nonaktif_at')->findOrFail($masuk['user_id']);
        $request->session()->forget('masuk');
        // "Ingat perangkat ini" as ticked with the password, when the code was for a new device
        Auth::login($staf, $request->boolean('remember') || ($masuk['remember'] ?? false));
        $request->session()->regenerate();
        // the code proved this browser: no code next time ("Kode WhatsApp di perangkat baru")
        PerangkatDikenal::tandai($request, $staf);

        return redirect('/');
    }
}
