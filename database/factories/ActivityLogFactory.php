<?php

namespace Database\Factories;

use App\Enums\KategoriAktivitas;
use App\Enums\SumberAktivitas;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\KunciPengaturan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * A line written by the system (a workflow run).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waktu' => now(),
            'pelaku' => 'Sistem',
            'sumber' => SumberAktivitas::Sistem,
            'kategori' => KategoriAktivitas::Otomasi->value,
            'aksi' => fake()->sentence(2),
        ];
    }

    public function pada(string $waktu): static
    {
        return $this->state(['waktu' => $waktu]);
    }

    public function kategori(KategoriAktivitas $kategori): static
    {
        return $this->state(['kategori' => $kategori->value]);
    }

    public function oleh(User $user, SumberAktivitas $sumber = SumberAktivitas::Panel): static
    {
        return $this->state(['user_id' => $user->id, 'pelaku' => $user->name, 'sumber' => $sumber]);
    }

    public function adiva(): static
    {
        return $this->state(['user_id' => null, 'pelaku' => 'Adiva', 'sumber' => SumberAktivitas::Adiva]);
    }

    /**
     * A setting changed in the panel, written like PengaturanToko::ubah() writes it.
     */
    public function ubahPengaturan(string $kunci, string $sebelum, string $sesudah): static
    {
        return $this->kategori(KategoriAktivitas::Pengaturan)->state([
            'aksi' => 'Ubah pengaturan',
            'detail' => KunciPengaturan::perubahan($kunci, $sebelum, $sesudah),
            'data' => ['kunci' => $kunci, 'sebelum' => $sebelum, 'sesudah' => $sesudah, 'perangkat' => 'Chrome · Android'],
        ]);
    }
}
