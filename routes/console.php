<?php

use App\Models\AksesAccurate;
use App\Support\Demo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// a campaign whose sending chain was lost (queue cleared, worker down) carries on
Schedule::command('broadcast:lanjutkan')->everyFiveMinutes()->withoutOverlapping();

// Otomasi and Monitoring follow n8n: workflows switched on or off, and every finished run.
// Not in demo mode: the frozen clock would file today's runs on 28 Sep.
Schedule::command('n8n:sinkron')->everyMinute()->withoutOverlapping()->skip(fn () => Demo::aktif());

// the supervisor's short WhatsApp for each notification n8n writes (docs/kontrak-n8n.md §9)
Schedule::command('notifikasi:kirim-wa')->everyFifteenSeconds()->withoutOverlapping()->skip(fn () => Demo::aktif());

// Toko Sawojajar's goods from Accurate, read only (Katalog → Barang toko, Adiva's Cari_Barang_Toko):
// every minute, so a price changed in Accurate reaches Adiva within a minute or two. Also in demo
// mode (the frozen clock is always "due" every minute); not before Accurate is connected.
Schedule::command('accurate:sinkron')->everyMinute()->withoutOverlapping(5)
    ->skip(fn () => blank(config('services.accurate.token')) && ! AksesAccurate::query()->exists());

// the daily reminder of who the bot is still paused for (BOT_DRIVER=lokal; n8n did this at 09:00)
// on the VPS cron container only (CADANGAN_AKTIF=true), where pg_dump is installed
Schedule::command('cadangan:buat')->dailyAt('02:00')->withoutOverlapping()->when(fn () => (bool) config('cadangan.aktif') && ! Demo::aktif());
Schedule::command('jeda:ingatkan')->dailyAt('09:00')
    ->skip(fn () => Demo::aktif() || config('services.bot.driver') !== 'lokal');
