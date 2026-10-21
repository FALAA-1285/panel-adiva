<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

class Demo
{
    /**
     * Whether demo mode (APP_DEMO) is on.
     */
    public static function aktif(): bool
    {
        return (bool) config('app.demo');
    }

    /**
     * The frozen moment every design/source screen shows: Senin, 28 Sep 2026 13:42 WIB.
     */
    public static function jam(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('app.demo_now'), 'Asia/Jakarta')
            ->setTimezone(config('app.timezone'));
    }

    /**
     * Freeze the application clock to the demo moment.
     */
    public static function bekukanJam(): void
    {
        Date::setTestNow(self::jam());
    }
}
