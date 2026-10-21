<?php

use App\Support\JamKerja;
use Carbon\CarbonImmutable;

test('hours are added inside working hours only', function () {
    $jam = JamKerja::dari('07:00-21:00', 'Minggu');

    // Saturday 19:00: 2 hours left that day, Sunday is off, 3 more on Monday
    expect($jam->tambah(CarbonImmutable::parse('2026-09-26 19:00'), 5)->format('Y-m-d H:i'))->toBe('2026-09-28 10:00')
        // at night the count starts the next morning
        ->and($jam->tambah(CarbonImmutable::parse('2026-09-28 22:30'), 2)->format('Y-m-d H:i'))->toBe('2026-09-29 09:00')
        ->and($jam->tambah(CarbonImmutable::parse('2026-09-28 13:42'), 5)->format('Y-m-d H:i'))->toBe('2026-09-28 18:42');
});

test('the hours left skip nights and holidays', function () {
    $jam = JamKerja::dari('07:00-21:00', 'Minggu, 2026-09-29');

    expect($jam->sisa(CarbonImmutable::parse('2026-09-28 13:42'), CarbonImmutable::parse('2026-09-28 15:42')))->toBe(2)
        // Monday evening to Wednesday 09:00: Tuesday 29 Sep is a holiday
        ->and($jam->sisa(CarbonImmutable::parse('2026-09-28 20:00'), CarbonImmutable::parse('2026-09-30 09:00')))->toBe(3)
        ->and($jam->sisa(CarbonImmutable::parse('2026-09-28 13:42'), CarbonImmutable::parse('2026-09-28 12:00')))->toBe(0);
});

test('working hours are now or not', function () {
    $jam = JamKerja::dari('07:00-21:00', 'Minggu');

    expect($jam->berlaku(CarbonImmutable::parse('2026-09-28 13:42')))->toBeTrue()
        ->and($jam->berlaku(CarbonImmutable::parse('2026-09-28 21:00')))->toBeFalse()
        ->and($jam->berlaku(CarbonImmutable::parse('2026-09-27 13:42')))->toBeFalse();
});
