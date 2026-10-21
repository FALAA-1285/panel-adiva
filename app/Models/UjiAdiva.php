<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A test question asked to Adiva from the panel (bagian KATALOG, KEBIJAKAN, PROMPT).
 *
 * @property string $pertanyaan
 * @property string|null $jawaban
 * @property int|null $prompt_versi_id the prompt version tested (PROMPT)
 * @property array{butuh_admin?: bool, tahap?: string, alasan?: string}|null $hasil what Adiva decided besides the answer (PROMPT)
 */
#[Table('uji_adiva')]
#[Fillable(['bagian', 'pertanyaan', 'jawaban', 'catatan', 'hasil', 'produk_id', 'kebijakan_id', 'prompt_versi_id', 'user_id'])]
class UjiAdiva extends Model
{
    use ZonaWaktuAplikasi;

    /**
     * Product the answer was based on (highlighted in Katalog).
     *
     * @return BelongsTo<Produk, $this>
     */
    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
    }

    /**
     * @return BelongsTo<PromptVersi, $this>
     */
    public function promptVersi(): BelongsTo
    {
        return $this->belongsTo(PromptVersi::class);
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
            'hasil' => 'array',
        ];
    }
}
