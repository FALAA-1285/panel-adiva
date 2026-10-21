<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product photo Adiva may send. A row without a file is a slot waiting for an
 * upload (Gambar.dc.html: "Usus Crispy · Belum ada foto").
 *
 * @property int $id
 * @property int|null $produk_id
 * @property string $title
 * @property string|null $tags
 * @property string|null $caption
 * @property string|null $filename
 * @property string|null $path
 * @property string|null $image_url
 * @property string|null $mime_type
 * @property int|null $lebar
 * @property int|null $tinggi
 * @property int|null $ukuran_byte
 * @property bool $aktif
 * @property-read int|null $dikirim_count
 */
#[Table('gambar_produk')]
#[Fillable([
    'produk_id', 'title', 'description_for_ai', 'tags', 'caption', 'filename', 'path',
    'image_url', 'gdrive_file_id', 'mime_type', 'lebar', 'tinggi', 'ukuran_byte', 'aktif',
])]
class GambarProduk extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
    }

    /**
     * Chat lines where Adiva sent this photo.
     *
     * @return HasMany<Pesan, $this>
     */
    public function pesan(): HasMany
    {
        return $this->hasMany(Pesan::class);
    }

    /**
     * Whether a photo file exists (uploaded here or hosted at image_url).
     */
    public function punyaFile(): bool
    {
        return $this->filename !== null || $this->image_url !== null;
    }

    /**
     * Rows that have a photo file.
     *
     * @param  Builder<GambarProduk>  $query
     */
    #[Scope]
    protected function adaFile(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('filename')->orWhereNotNull('image_url'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'lebar' => 'integer',
            'tinggi' => 'integer',
            'ukuran_byte' => 'integer',
        ];
    }
}
