<?php

namespace App\Http\Controllers\Auth;

use App\Enums\KategoriAktivitas;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Aktivitas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Ganti kata sandi": asked for after 90 days (WajibGantiSandi), and open to anyone who
 * wants a new password sooner.
 */
class GantiSandiController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user() ?? abort(403);

        return Inertia::render('auth/ganti-sandi', [
            'wajib' => $user->sandiKedaluwarsa(),
            'hari' => User::MASA_SANDI_HARI,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        $request->validate([
            'sandi_lama' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [
            'sandi_lama.required' => 'Isi kata sandi Anda yang sekarang.',
            'sandi_lama.current_password' => 'Kata sandi yang sekarang tidak cocok.',
            'password.required' => 'Isi kata sandi baru.',
        ]);

        if (Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi baru tidak boleh sama dengan yang lama.']);
        }

        $user->forceFill(['password' => (string) $request->input('password')])->save();
        $request->session()->regenerate();

        Aktivitas::catat($user, KategoriAktivitas::Login, 'Ganti kata sandi', $user->name, $user);

        Inertia::flash('toast', 'Kata sandi diganti. Berlaku '.User::MASA_SANDI_HARI.' hari.');

        return redirect()->route('home');
    }
}
