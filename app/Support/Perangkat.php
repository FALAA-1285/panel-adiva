<?php

namespace App\Support;

use Illuminate\Support\Str;

class Perangkat
{
    /**
     * "Chrome · Windows" from a user agent, as shown in Log aktivitas and Tim & akses.
     */
    public static function dari(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            Str::contains($ua, 'Edg/') => 'Edge',
            Str::contains($ua, ['Chrome/', 'CriOS/']) => 'Chrome',
            Str::contains($ua, ['Firefox/', 'FxiOS/']) => 'Firefox',
            Str::contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $sistem = match (true) {
            Str::contains($ua, 'Android') => 'Android',
            Str::contains($ua, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($ua, 'Windows') => 'Windows',
            Str::contains($ua, 'Mac OS') => 'macOS',
            Str::contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return collect([$browser, $sistem])->filter()->implode(' · ') ?: 'Perangkat tidak dikenal';
    }
}
