<?php

namespace App\Support;

use App\Enums\KategoriAktivitas;
use App\Models\ConfigToko;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and changes CONFIG_TOKO values. Every change is written to Log aktivitas
 * with the value before and after, so it can be put back from there.
 */
class PengaturanToko
{
    /**
     * The stored value, or the key's default while it has no row.
     */
    public static function nilai(string $kunci): ?string
    {
        return ConfigToko::query()->where('kunci', $kunci)->value('nilai') ?? KunciPengaturan::bawaan($kunci);
    }

    /**
     * Several values in one query, keyed like the request.
     *
     * @param  list<string>  $kunci
     * @return array<string, string|null>
     */
    public static function nilaiBanyak(array $kunci): array
    {
        $tersimpan = ConfigToko::query()->whereIn('kunci', $kunci)->pluck('nilai', 'kunci');

        return collect($kunci)->mapWithKeys(fn (string $k) => [$k => $tersimpan[$k] ?? KunciPengaturan::bawaan($k)])->all();
    }

    /**
     * Save a new value; does nothing when it is unchanged.
     */
    public static function ubah(string $kunci, string $nilai, User $oleh, ?string $perangkat = null): void
    {
        self::ubahBanyak([$kunci => $nilai], $oleh, $perangkat);
    }

    /**
     * Save several values at once (Settings: "Simpan perubahan"). Current values are
     * read in one query; only the keys that changed are written and logged.
     *
     * @param  array<string, string>  $baru
     */
    public static function ubahBanyak(array $baru, User $oleh, ?string $perangkat = null): void
    {
        $sekarang = self::nilaiBanyak(array_keys($baru));

        foreach ($baru as $kunci => $nilai) {
            // a key without a row and an empty field mean the same thing
            $sebelum = $sekarang[$kunci] ?? null;

            if (($sebelum ?? '') === $nilai) {
                continue;
            }

            $config = ConfigToko::query()->updateOrCreate(['kunci' => $kunci], ['nilai' => $nilai]);
            self::catat($kunci, $sebelum, $nilai, $oleh, $perangkat, $config);
        }
    }

    /**
     * The "Ubah pengaturan" line in Log aktivitas, with what is needed to put it back.
     */
    public static function catat(string $kunci, ?string $sebelum, string $sesudah, User $oleh, ?string $perangkat, Model $subjek): void
    {
        Aktivitas::catat(
            $oleh,
            KategoriAktivitas::Pengaturan,
            'Ubah pengaturan',
            KunciPengaturan::perubahan($kunci, $sebelum, $sesudah),
            $subjek,
            array_filter(['kunci' => $kunci, 'sebelum' => $sebelum, 'sesudah' => $sesudah, 'perangkat' => $perangkat], fn ($v) => $v !== null),
        );
    }
}
