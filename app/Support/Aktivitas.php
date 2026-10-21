<?php

namespace App\Support;

use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a line to Log aktivitas for something a staff member did in the panel.
 */
class Aktivitas
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function catat(
        User $oleh,
        KategoriAktivitas $kategori,
        string $aksi,
        ?string $detail = null,
        ?Model $subjek = null,
        array $data = [],
        SumberAktivitas $sumber = SumberAktivitas::Panel,
    ): ActivityLog {
        return ActivityLog::create([
            'waktu' => now(),
            'user_id' => $oleh->id,
            'pelaku' => $oleh->name,
            'sumber' => $sumber,
            'kategori' => $kategori->value,
            'aksi' => $aksi,
            'detail' => $detail,
            'subjek_type' => $subjek?->getMorphClass(),
            'subjek_id' => $subjek?->getKey(),
            'data' => $data === [] ? null : $data,
        ]);
    }
}
