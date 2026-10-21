<?php

namespace App\Listeners;

use App\Enums\KategoriAktivitas;
use App\Jobs\KabariKonfigN8n;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\Perangkat;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Keeps "Terakhir masuk" on Tim & akses up to date and writes "Masuk" to Log
 * aktivitas ("Chrome · Windows · Malang").
 */
class CatatMasuk
{
    public function __construct(private Request $request) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->bukaWhatsapp($event->user);

        // /_demo/login is a shortcut into the demo; leave the design's data alone
        if ($this->request->routeIs('demo.login')) {
            return;
        }

        $perangkat = Perangkat::dari($this->request->userAgent());

        $event->user->forceFill([
            'terakhir_masuk_at' => now(),
            'terakhir_masuk_perangkat' => $perangkat,
        ])->saveQuietly();

        Aktivitas::catat(
            $event->user,
            KategoriAktivitas::Login,
            'Masuk',
            collect([$perangkat, $event->user->cabang])->filter()->implode(' · '),
            $event->user,
            ['perangkat' => $perangkat],
        );
    }

    /**
     * Signing in again gives back the WhatsApp admin commands taken with "Keluarkan"
     * on Tim & akses.
     */
    private function bukaWhatsapp(User $user): void
    {
        if ($user->wa_dicabut_at === null) {
            return;
        }

        $user->forceFill(['wa_dicabut_at' => null])->saveQuietly();
        KabariKonfigN8n::dispatch('AKSES_ADMIN_WA', ['user_id' => $user->id, 'nomor_wa' => $user->nomor_wa, 'dicabut' => false]);
    }
}
