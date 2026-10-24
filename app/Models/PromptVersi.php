<?php

namespace App\Models;

use App\Console\Commands\ImporPromptN8n;
use App\Enums\StatusPrompt;
use App\Models\Concerns\ZonaWaktuAplikasi;
use Database\Factories\PromptVersiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A version of Adiva's system prompt (DRAF, AKTIF or ARSIP).
 *
 * @property int $id
 * @property int $versi
 * @property string $ringkasan
 * @property list<array{no: int, judul: string, teks: string, keterangan?: string}> $bagian keterangan: what the section is for ("kapan Adiva memanggil admin")
 * @property StatusPrompt $status
 * @property int|null $user_id who drafted it, then who published it
 * @property Carbon|null $diterbitkan_at
 * @property string|null $teks_lengkap every section joined (PromptVersi::susunTeks), what n8n reads
 */
#[Table('prompt_versi')]
#[Fillable(['versi', 'ringkasan', 'bagian', 'status', 'user_id', 'diterbitkan_at'])]
class PromptVersi extends Model
{
    /** @use HasFactory<PromptVersiFactory> */
    use HasFactory, ZonaWaktuAplikasi;

    /**
     * Setting the sections also sets teks_lengkap, the text n8n reads for the active
     * version. A mutator rather than a model event, so seeders without events fill it too.
     *
     * @return Attribute<list<array{no: int, judul: string, teks: string, keterangan?: string}>, list<array{no: int, judul: string, teks: string, keterangan?: string}>>
     */
    protected function bagian(): Attribute
    {
        return Attribute::make(set: fn (array $bagian) => [
            'bagian' => json_encode($bagian, JSON_UNESCAPED_UNICODE),
            'teks_lengkap' => self::susun($bagian),
        ]);
    }

    /**
     * The whole prompt as Adiva reads it: every section in order, under its heading.
     *
     * @param  array<int|string, array<string, mixed>>  $bagian
     */
    public static function susun(array $bagian): string
    {
        return collect($bagian)
            ->sortBy('no')
            ->map(fn (array $b) => '## '.((int) ($b['no'] ?? 0)).'. '.((string) ($b['judul'] ?? ''))."\n".trim((string) ($b['teks'] ?? '')))
            ->implode("\n\n");
    }

    public function susunTeks(): string
    {
        return self::susun($this->bagian);
    }

    /**
     * Inisialisasi prompt standar dari prompt-adiva.txt jika database kosong.
     */
    public static function inisialisasiStandar(?User $user = null): ?self
    {
        if (self::query()->exists()) {
            return self::query()->aktif()->latest('versi')->first() ?? self::query()->latest('versi')->first();
        }

        $berkas = database_path('seeders/data/prompt-adiva.txt');
        if (! file_exists($berkas)) {
            return null;
        }

        $isi = (string) file_get_contents($berkas);
        $bagian = ImporPromptN8n::pecah($isi);
        if ($bagian === []) {
            return null;
        }

        $user = $user ?? User::query()->first();

        self::create([
            'versi' => 12,
            'ringkasan' => 'Program karyawan & singkatan',
            'bagian' => $bagian,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDays(17),
        ]);
        self::create([
            'versi' => 13,
            'ringkasan' => 'Satu order aktif saja',
            'bagian' => $bagian,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDays(8),
        ]);
        self::create([
            'versi' => 14,
            'ringkasan' => 'Tahap Tidak minat & aturan sapaan ulang',
            'bagian' => $bagian,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDays(2),
        ]);
        self::create([
            'versi' => 15,
            'ringkasan' => 'Eskalasi order besar, larang harga karangan',
            'bagian' => $bagian,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDay(),
        ]);

        $aktif = self::create([
            'versi' => 16,
            'ringkasan' => 'Versi 6 (ringkas & optimal): integrasi Cek_Ongkir_Mengantar & skema order',
            'bagian' => $bagian,
            'status' => StatusPrompt::Aktif,
            'user_id' => $user?->id,
            'diterbitkan_at' => now(),
        ]);

        return $aktif;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The version Adiva reads now.
     *
     * @param  Builder<PromptVersi>  $query
     */
    #[Scope]
    protected function aktif(Builder $query): void
    {
        $query->where('status', StatusPrompt::Aktif);
    }

    /**
     * @param  Builder<PromptVersi>  $query
     */
    #[Scope]
    protected function draf(Builder $query): void
    {
        $query->where('status', StatusPrompt::Draf);
    }

    /**
     * A section's text by its number ('' when the version has no such section).
     */
    public function teksBagian(int $no): string
    {
        return collect($this->bagian)->firstWhere('no', $no)['teks'] ?? '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'versi' => 'integer',
            'bagian' => 'array',
            'status' => StatusPrompt::class,
            'diterbitkan_at' => 'datetime',
        ];
    }
}
