<?php

namespace App\Support;

use App\Enums\Peran;
use App\Models\User;

/**
 * What each role may do in the panel (Tim.dc.html: "Hak akses per peran di panel").
 * The Tim & akses page shows this table, and the panel's gates read it.
 */
class HakAkses
{
    /**
     * @var array<string, array{label: string, peran: list<Peran>}>
     */
    public const AKSI = [
        'balas' => ['label' => 'Membalas chat, jeda & lanjutkan bot', 'peran' => [Peran::Supervisor, Peran::StafCs]],
        'acc' => ['label' => 'ACC, TOLAK, BATAL', 'peran' => [Peran::Supervisor, Peran::StafCs]],
        'status_order' => ['label' => 'LUNAS, SIAP, KIRIM, SELESAI', 'peran' => [Peran::Supervisor, Peran::StafCs]],
        'catat' => ['label' => 'Catat order manual', 'peran' => [Peran::Supervisor, Peran::StafCs]],
        'order_karyawan' => ['label' => 'Order karyawan', 'peran' => [Peran::Supervisor, Peran::PjKaryawan]],
        'broadcast' => ['label' => 'Broadcast', 'peran' => [Peran::Supervisor]],
        'katalog' => ['label' => 'Katalog, kebijakan, prompt', 'peran' => [Peran::Supervisor]],
        'pengaturan' => ['label' => 'Pengaturan toko', 'peran' => [Peran::Supervisor]],
        'tim' => ['label' => 'Tim & akses', 'peran' => [Peran::Supervisor]],
    ];

    public static function boleh(User $user, string $aksi): bool
    {
        return in_array($user->peran, self::AKSI[$aksi]['peran'] ?? [], true);
    }

    /**
     * Rows for the table: one per action, a tick per role.
     *
     * @return list<array{aksi: string, label: string, peran: array<string, bool>}>
     */
    public static function matriks(): array
    {
        return array_map(
            fn (string $aksi, array $baris) => [
                'aksi' => $aksi,
                'label' => $baris['label'],
                'peran' => collect(Peran::cases())
                    ->mapWithKeys(fn (Peran $peran) => [$peran->value => in_array($peran, $baris['peran'], true)])
                    ->all(),
            ],
            array_keys(self::AKSI),
            self::AKSI,
        );
    }
}
