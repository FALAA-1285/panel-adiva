<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Peran;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $nomor_wa
 * @property string|null $cabang
 * @property Peran $peran
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property Carbon|null $diundang_at
 * @property Carbon|null $nonaktif_at
 * @property Carbon|null $terakhir_masuk_at
 * @property string|null $terakhir_masuk_perangkat
 * @property Carbon|null $wa_dicabut_at admin commands over WhatsApp refused until the next panel sign-in
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'nomor_wa', 'cabang', 'peran', 'password',
    'diundang_at', 'nonaktif_at', 'terakhir_masuk_at', 'terakhir_masuk_perangkat', 'wa_dicabut_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, ZonaWaktuAplikasi;

    /**
     * The PJ karyawan (SARAH_WA), who receives the order karyawan. The first one
     * counts when several staff hold the role.
     */
    public static function pjKaryawan(): ?self
    {
        return static::query()->where('peran', Peran::PjKaryawan)->orderBy('id')->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'peran' => Peran::class,
            'password' => 'hashed',
            'diundang_at' => 'datetime',
            'nonaktif_at' => 'datetime',
            'wa_dicabut_at' => 'datetime',
            'terakhir_masuk_at' => 'datetime',
        ];
    }
}
