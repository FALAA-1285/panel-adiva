<?php

namespace App\Support;

use App\Enums\KategoriAktivitas;
use App\Models\ActivityLog;
use App\Models\User;

/**
 * The periodic reviews the BCA CSA asks for, recorded as lines in Log aktivitas so there is
 * proof they happened: the access review on Tim & akses every 3 months (butir 24), and the
 * log review on Log aktivitas every week (butir 21).
 */
class Tinjauan
{
    public const AKSES = 'Tinjau akses';

    public const LOG = 'Tinjau log';

    /** @var array<string, int> days until the next review is due */
    public const JARAK_HARI = [self::AKSES => 90, self::LOG => 7];

    /**
     * The last review of this kind, and whether the next one is due.
     *
     * @return array{terakhir: string|null, oleh: string|null, jatuhTempo: bool, setiapHari: int}
     */
    public static function ringkas(string $aksi): array
    {
        $baris = ActivityLog::query()->where('aksi', $aksi)->latest('waktu')->latest('id')->first();
        $jarak = self::JARAK_HARI[$aksi];

        return [
            'terakhir' => $baris?->waktu->translatedFormat('j M Y, H:i'),
            'oleh' => $baris?->pelaku,
            'jatuhTempo' => $baris === null || $baris->waktu->lte(now()->subDays($jarak)),
            'setiapHari' => $jarak,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function catat(User $oleh, string $aksi, string $detail, array $data = []): ActivityLog
    {
        return Aktivitas::catat($oleh, KategoriAktivitas::Pengaturan, $aksi, $detail, null, $data);
    }
}
