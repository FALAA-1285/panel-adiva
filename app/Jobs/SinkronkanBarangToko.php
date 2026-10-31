<?php

namespace App\Jobs;

use App\Accurate\SinkronBarangToko;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Sinkron sekarang" on Katalog → Barang toko: the scheduled run, started by hand. One at a time.
 */
class SinkronkanBarangToko implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public ?User $oleh = null) {}

    public function handle(SinkronBarangToko $sinkron): void
    {
        $sinkron->jalankan($this->oleh);
    }
}
