<?php

namespace App\Listeners;

use App\Support\KunciMasuk;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * A wrong password on Masuk: written to Log aktivitas and counted towards the account lock
 * (KunciMasuk). The password itself is never stored.
 */
class CatatGagalMasuk
{
    public function __construct(private Request $request) {}

    public function handle(Failed $event): void
    {
        KunciMasuk::gagal((string) ($event->credentials[Fortify::username()] ?? ''), $this->request);
    }
}
