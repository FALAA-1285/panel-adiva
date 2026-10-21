<?php

namespace Tests\Support;

use Tests\TestCase;

/**
 * The Daftar form (Daftar.dc.html, step 1) as Dina fills it in.
 */
final class AlurDaftar
{
    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    public static function isian(array $ubah = []): array
    {
        return [
            'nama' => 'Dina',
            'email' => 'dina@bangbana.id',
            'nomor_wa' => '857 1234 0036',
            'password' => 'rahasia123',
            'setuju' => true,
            ...$ubah,
        ];
    }

    /**
     * Request a code and return it, as it reached Dina's WhatsApp.
     */
    public static function mintaKode(TestCase $test): string
    {
        $rekam = PengirimRekam::pasang();

        $test->postJson('/daftar/kode', self::isian())->assertOk();

        return $rekam->kodeTerakhir();
    }
}
