<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Someone who wrote to the shop on Instagram or Facebook (Chatbot Sosmed). n8n keeps the
 * bot's state here as text: the times are ISO strings, the flags "YA" / "TIDAK".
 *
 * @property int $id
 * @property string $id_pengirim Meta's id for this person on this page (IGSID / PSID)
 * @property string|null $platform IG or FB
 * @property string|null $status_bot AKTIF or JEDA
 * @property string|null $pesan_terakhir
 * @property string|null $balasan_bot_terakhir
 * @property string|null $tag_terakhir ORDER or ADMIN
 * @property string|null $minat_order
 * @property string|null $butuh_admin
 * @property int $jumlah_pesan
 * @property string|null $waktu_pertama
 * @property string|null $waktu_terakhir
 * @property string|null $jeda_sampai
 * @property string|null $nama
 * @property string|null $username
 * @property string|null $foto_url
 * @property string|null $akun_id
 * @property string|null $jeda_oleh
 */
#[Table('sosmed_kontak')]
#[Fillable([
    'id_pengirim', 'platform', 'status_bot', 'pesan_terakhir', 'pesan_tertunda', 'mid_terakhir', 'balasan_bot_terakhir',
    'tag_terakhir', 'minat_order', 'butuh_admin', 'jumlah_pesan', 'waktu_pertama', 'waktu_terakhir', 'waktu_notif',
    'jeda_sampai', 'nama', 'username', 'foto_url', 'akun_id', 'jeda_oleh',
])]
class SosmedKontak extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return HasMany<SosmedPesan, $this>
     */
    public function pesan(): HasMany
    {
        return $this->hasMany(SosmedPesan::class);
    }

    /**
     * "Rina Maharani", "@rina.snack", or "Pengguna Instagram ·4821" before n8n read the profile.
     */
    public function namaTampil(): string
    {
        return $this->nama ?: ($this->username ? '@'.$this->username : 'Pengguna '.$this->platformLabel().' ·'.substr($this->id_pengirim, -4));
    }

    public function platformLabel(): string
    {
        return $this->platform === 'FB' ? 'Facebook' : 'Instagram';
    }

    /**
     * Paused by an admin's reply or from the panel, until jeda_sampai.
     */
    public function dijeda(): bool
    {
        return $this->status_bot === 'JEDA' && ($this->jedaSampai()?->isFuture() ?? false);
    }

    public function butuhAdmin(): bool
    {
        return $this->butuh_admin === 'YA';
    }

    public function jedaSampai(): ?CarbonInterface
    {
        return self::waktu($this->jeda_sampai);
    }

    public function terakhir(): ?CarbonInterface
    {
        return self::waktu($this->waktu_terakhir) ?? $this->updated_at;
    }

    public function pertama(): ?CarbonInterface
    {
        return self::waktu($this->waktu_pertama) ?? $this->created_at;
    }

    private static function waktu(?string $teks): ?CarbonInterface
    {
        if (blank($teks)) {
            return null;
        }

        try {
            return Carbon::parse($teks)->timezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
