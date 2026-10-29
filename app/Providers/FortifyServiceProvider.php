<?php

namespace App\Providers;

use App\Actions\Fortify\CekPerangkatBaru;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\Demo;
use App\Support\KunciMasuk;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn (Request $request) => Inertia::render('auth/masuk', [
            // "Kode WhatsApp di perangkat baru": the password was right, the code is awaited
            'kodePerangkat' => CekPerangkatBaru::menunggu($request),
        ]));

        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            CekPerangkatBaru::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        // Deactivated staff (Tim & akses, "Nonaktifkan") and staff without an account yet cannot sign in;
        // an account locked after five wrong passwords waits 15 minutes, even with the right one
        Fortify::authenticateUsing(function (Request $request): ?User {
            $masukan = (string) $request->input('email');

            if (KunciMasuk::terkunci($masukan)) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Akun ini dikunci '.KunciMasuk::MENIT.' menit karena kata sandi salah berulang kali. Supervisor sudah dikabari.',
                ]);
            }

            $user = KunciMasuk::akun($masukan)
                ->whereNull('nonaktif_at')
                ->whereNotNull('password')
                ->first();

            return $user && Hash::check((string) $request->input('password'), (string) $user->password) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return $this->batas(Limit::perMinute(5)->by($throttleKey));
        });

        // Separate keys per step: `throttle:x,y` would share one bucket across routes.
        RateLimiter::for('daftar-kode', fn (Request $request) => $this->batas(Limit::perMinute(5)->by('daftar-kode|'.$request->ip())));
        RateLimiter::for('daftar-verifikasi', fn (Request $request) => $this->batas(Limit::perMinute(10)->by('daftar-verifikasi|'.$request->ip())));
        RateLimiter::for('reset-kode', fn (Request $request) => $this->batas(Limit::perMinute(5)->by('reset-kode|'.$request->ip())));
        RateLimiter::for('reset-cek', fn (Request $request) => $this->batas(Limit::perMinute(10)->by('reset-cek|'.$request->ip())));
        RateLimiter::for('masuk-kode', fn (Request $request) => $this->batas(Limit::perMinute(5)->by('masuk-kode|'.$request->ip())));
        RateLimiter::for('masuk-verifikasi', fn (Request $request) => $this->batas(Limit::perMinute(10)->by('masuk-verifikasi|'.$request->ip())));
    }

    /**
     * Demo mode freezes the clock for every request, so a rate limit would never
     * decay and would lock the demo for good. Only limit outside demo mode.
     */
    private function batas(Limit $limit): Limit
    {
        return Demo::aktif() ? Limit::none() : $limit;
    }
}
