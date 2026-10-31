<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The panel's OAuth access to Accurate (OauthAccurate). One row at most; tokens and session
 * are encrypted and never leave the server.
 *
 * @property int $id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $kedaluwarsa_at when the access token stops working (15 days)
 * @property string|null $host the Bana Food database's API host
 * @property string|null $session X-Session-ID for that host
 * @property Carbon|null $session_at
 * @property int|null $disambungkan_oleh_id
 * @property Carbon $disambungkan_at
 */
#[Table('akses_accurate')]
#[Fillable(['access_token', 'refresh_token', 'kedaluwarsa_at', 'host', 'session', 'session_at', 'disambungkan_oleh_id', 'disambungkan_at'])]
#[Hidden(['access_token', 'refresh_token', 'session'])]
class AksesAccurate extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * The access in use, or null while Accurate is not connected through OAuth.
     */
    public static function sekarang(): ?self
    {
        return self::query()->latest('id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disambungkanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disambungkan_oleh_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'session' => 'encrypted',
            'kedaluwarsa_at' => 'datetime',
            'session_at' => 'datetime',
            'disambungkan_at' => 'datetime',
        ];
    }
}
