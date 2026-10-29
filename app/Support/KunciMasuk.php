<?php

namespace App\Support;

use App\Enums\JenisNotifikasi;
use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Wrong passwords on Masuk (BCA CSA butir 20, 21 dan 23): every one is written to Log
 * aktivitas, and five on one account within 15 minutes lock that account for 15 minutes
 * and tell the supervisor. Counted per account, so typing the email, the name or the
 * WhatsApp number does not reset the count; an unknown login is counted as typed.
 */
class KunciMasuk
{
    public const BATAS_SALAH = 5;

    public const MENIT = 15;

    /**
     * The accounts a login field may name: email, name, or WhatsApp number.
     *
     * @return Builder<User>
     */
    public static function akun(string $masukan): Builder
    {
        $masukan = Str::lower(trim($masukan));
        $hanyaAngka = preg_replace('/\D/', '', $masukan);
        $nomor = $hanyaAngka !== null && strlen($hanyaAngka) >= 8 ? NomorWa::normalisasi($masukan) : null;

        return User::query()->where(function (Builder $query) use ($masukan, $nomor) {
            $query->where('email', $masukan)->orWhere('name', $masukan);

            if ($masukan === 'tkt.food.com') {
                $query->orWhere('email', 'tkt.food@gmail.com');
            }

            if (filled($nomor)) {
                $query->orWhere('nomor_wa', $nomor);
            }
        });
    }

    public static function terkunci(string $masukan): bool
    {
        return ! Demo::aktif() && Cache::has(self::kunci($masukan, 'terkunci'));
    }

    /**
     * A wrong password: logged, counted, and the fifth one locks the account.
     */
    public static function gagal(string $masukan, Request $request): void
    {
        $user = self::akun($masukan)->first();
        $perangkat = Perangkat::dari($request->userAgent());

        ActivityLog::create([
            'waktu' => now(),
            'user_id' => $user?->id,
            'pelaku' => $user->name ?? Str::limit(trim($masukan), 60),
            'sumber' => SumberAktivitas::Panel,
            'kategori' => KategoriAktivitas::Login->value,
            'aksi' => 'Gagal masuk',
            'detail' => collect([$perangkat, 'IP '.$request->ip()])->implode(' · '),
            'subjek_type' => $user?->getMorphClass(),
            'subjek_id' => $user?->getKey(),
            'data' => ['masukan' => Str::limit(trim($masukan), 120), 'perangkat' => $perangkat, 'ip' => $request->ip()],
        ]);

        // the demo's clock is frozen: a counter would never run out there
        if (Demo::aktif()) {
            return;
        }

        $hitung = self::kunci($masukan, 'salah');
        Cache::add($hitung, 0, now()->addMinutes(self::MENIT));
        $salah = (int) Cache::increment($hitung);

        if ($salah < self::BATAS_SALAH || Cache::has(self::kunci($masukan, 'terkunci'))) {
            return;
        }

        Cache::put(self::kunci($masukan, 'terkunci'), true, now()->addMinutes(self::MENIT));

        KabarBot::notifikasi(
            JenisNotifikasi::LoginMencurigakan,
            'Akun dikunci '.self::MENIT.' menit: kata sandi salah '.self::BATAS_SALAH.' kali',
            collect([$user->name ?? trim($masukan), $perangkat, 'IP '.$request->ip()])->implode(' · '),
        );
    }

    /**
     * A right password: the count starts again.
     */
    public static function berhasil(User $user): void
    {
        Cache::forget('masuk:salah:akun:'.$user->id);
    }

    private static function kunci(string $masukan, string $jenis): string
    {
        $user = self::akun($masukan)->first();

        return "masuk:{$jenis}:".($user ? 'akun:'.$user->id : 'masukan:'.sha1(Str::lower(trim($masukan))));
    }
}
