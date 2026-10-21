<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "Kode WhatsApp di perangkat baru" (Tim & akses): a browser where a staff member once
 * entered a WhatsApp code is remembered for a year in an (encrypted) cookie.
 */
class PerangkatDikenal
{
    public const COOKIE = 'perangkat_adiva';

    private const MENIT = 60 * 24 * 365;

    public static function kenal(Request $request, User $user): bool
    {
        return in_array($user->id, self::daftar($request), true);
    }

    public static function tandai(Request $request, User $user): void
    {
        $daftar = array_values(array_unique([...self::daftar($request), $user->id]));

        Cookie::queue(self::COOKIE, (string) json_encode($daftar), self::MENIT);
    }

    /**
     * @return list<int>
     */
    private static function daftar(Request $request): array
    {
        $cookie = $request->cookie(self::COOKIE);
        $isi = is_string($cookie) ? json_decode($cookie, true) : null;

        return is_array($isi) ? array_values(array_filter($isi, 'is_int')) : [];
    }
}
