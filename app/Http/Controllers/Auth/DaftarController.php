<?php

namespace App\Http\Controllers\Auth;

use App\Bot\KirimKeStaf;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\KirimKodeDaftarRequest;
use App\Http\Requests\Auth\VerifikasiDaftarRequest;
use App\Mail\KodeVerifikasiMail;
use App\Models\User;
use App\Support\KodeVerifikasi;
use App\Support\NomorWa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Buat akun staf": a staff member already on the staff list (added by the
 * supervisor, no panel password yet) sets a password after a WhatsApp code.
 */
class DaftarController extends Controller
{
    private const MAKS_PERCOBAAN = 3;

    /**
     * Step 1: check the staff list and send a 6-digit code.
     */
    public function kirimKode(KirimKodeDaftarRequest $request, KirimKeStaf $kirimKeStaf): JsonResponse
    {
        $staf = User::query()
            ->where('nomor_wa', NomorWa::normalisasi($request->string('nomor_wa')))
            ->whereRaw('lower(email) = ?', [Str::lower($request->string('email'))])
            ->whereNull('password')
            ->whereNull('nonaktif_at')
            ->first();

        if (! $staf) {
            throw ValidationException::withMessages([
                'nomor_wa' => 'Nomor dan email ini tidak ada di daftar staf, atau akunnya sudah dibuat.',
            ]);
        }

        $kode = KodeVerifikasi::buat();
        KodeVerifikasi::kirim($kirimKeStaf, (string) $staf->nomor_wa, "Kode verifikasi panel Adiva: {$kode}");

        $request->session()->put('daftar', [
            'user_id' => $staf->id,
            'password' => Hash::make($request->string('password')),
            'kode' => KodeVerifikasi::hash($kode),
            'berlaku_sampai' => now()->addMinutes(5)->getTimestamp(),
            'percobaan' => 0,
        ]);

        return response()->json([
            'nomor' => NomorWa::samarkan((string) $staf->nomor_wa),
            'staf' => [
                'nama' => $staf->name,
                'inisial' => Str::upper(Str::substr($staf->name, 0, 1)),
                'email' => $staf->email,
                'peran' => $staf->peran->label(),
            ],
        ]);
    }

    /**
     * "Kirim lewat email": a new code to the staff member's email instead. The code
     * sent on WhatsApp stops working ("Kode lama otomatis hangus").
     */
    public function kirimKodeEmail(Request $request): JsonResponse
    {
        /** @var array{user_id: int, password: string, kode: string, berlaku_sampai: int, percobaan: int}|null $daftar */
        $daftar = $request->session()->get('daftar');
        $staf = $daftar ? User::query()->whereNull('password')->find($daftar['user_id']) : null;

        if (! $staf) {
            throw ValidationException::withMessages(['kode' => 'Kirim kode lewat WhatsApp dulu.']);
        }

        $kode = KodeVerifikasi::buat();
        Mail::to($staf->email)->send(new KodeVerifikasiMail($kode));

        $request->session()->put('daftar', [
            ...$daftar,
            'kode' => KodeVerifikasi::hash($kode),
            'berlaku_sampai' => now()->addMinutes(5)->getTimestamp(),
            'percobaan' => 0,
        ]);

        return response()->json(['tujuan' => self::samarkanEmail($staf->email)]);
    }

    /**
     * "dina@bangbana.id" → "d•••@bangbana.id".
     */
    private static function samarkanEmail(string $email): string
    {
        return Str::substr($email, 0, 1).'•••@'.Str::after($email, '@');
    }

    /**
     * Step 2: check the code, save the password and log the staff member in.
     */
    public function verifikasi(VerifikasiDaftarRequest $request): RedirectResponse
    {
        /** @var array{user_id: int, password: string, kode: string, berlaku_sampai: int, percobaan: int}|null $daftar */
        $daftar = $request->session()->get('daftar');

        if (! $daftar || now()->getTimestamp() > $daftar['berlaku_sampai']) {
            $request->session()->forget('daftar');

            throw ValidationException::withMessages(['kode' => 'Kode sudah tidak berlaku. Kirim ulang kode.']);
        }

        if (! KodeVerifikasi::cocok($request->string('kode'), $daftar['kode'])) {
            $daftar['percobaan']++;

            if ($daftar['percobaan'] >= self::MAKS_PERCOBAAN) {
                $request->session()->forget('daftar');
            } else {
                $request->session()->put('daftar', $daftar);
            }

            throw ValidationException::withMessages(['kode' => 'Kode salah.']);
        }

        $staf = User::query()->findOrFail($daftar['user_id']);
        $staf->forceFill(['password' => $daftar['password']])->save();

        $request->session()->forget('daftar');
        Auth::login($staf);
        $request->session()->regenerate();

        return redirect('/');
    }
}
