<?php

namespace App\Models;

use App\Enums\PengirimPesan;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Instagram / Facebook message: written by n8n (n8n_catat_sosmed) or by the panel
 * for an admin's reply.
 *
 * @property int $id
 * @property int $sosmed_kontak_id
 * @property Carbon $waktu
 * @property PengirimPesan $pengirim
 * @property int|null $user_id
 * @property string|null $teks
 * @property string|null $mid Meta's message id
 * @property string|null $status_kirim MENUNGGU, TERKIRIM or GAGAL
 * @property array<string, mixed>|null $meta
 */
#[Table('sosmed_pesan')]
#[Fillable(['sosmed_kontak_id', 'waktu', 'pengirim', 'user_id', 'teks', 'mid', 'status_kirim', 'meta'])]
class SosmedPesan extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<SosmedKontak, $this>
     */
    public function kontak(): BelongsTo
    {
        return $this->belongsTo(SosmedKontak::class, 'sosmed_kontak_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'pengirim' => PengirimPesan::class,
            'meta' => 'array',
        ];
    }
}
