<?php

namespace App\Models;

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

        $judul = [
            'Baca dulu', 'Identitas', 'Kepribadian & gaya', 'Sumber data (tool)', 'Pembuka', 'Jalur reseller',
            'Jalur konsumsi sendiri', 'Ringkasan pesanan', 'Alamat & ongkir', 'Order & pembayaran', 'Gambar produk',
            'Tombol balasan', 'Eskalasi admin', 'Jam operasional', 'Kategorisasi', 'Prinsip percakapan',
            'Cek status pesanan', 'Program karyawan',
        ];

        $eskalasi = [
            'Set butuh_admin = true (dan isi alasan_butuh_admin) untuk:',
            '- bukti transfer, komplain/retur, nego harga khusus,',
            '- kerja sama distributor besar, permintaan sampel,',
            '- ongkir yang tidak ditemukan, data yang tidak ada di tool,',
            '- customer yang terdengar kecewa atau marah,',
            '- permintaan pembatalan atau perubahan pesanan (bagian 9),',
            '- status pesanan yang TIDAK ketemu di Cek_Order_Saya.',
            '',
            'Pertanyaan status pesanan, pembayaran, pengiriman, atau resi',
            'yang datanya ADA di Cek_Order_Saya dijawab sendiri (bagian 16)',
            'dan butuh_admin = false.',
        ];

        $isi = str_replace("\r\n", "\n", (string) file_get_contents($berkas));
        $potongan = preg_split('/^(═+\n(\d+)[a-z]?\. .+\n═+)\n/mu', $isi, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $teks = [];

        for ($i = 1; $i + 2 < count($potongan); $i += 3) {
            $no = (int) $potongan[$i + 1];
            $badan = trim($potongan[$i + 2], "\n");
            $teks[$no] = isset($teks[$no]) ? "{$teks[$no]}\n\n{$potongan[$i]}\n{$badan}" : $badan;
        }

        $v14 = array_map(fn (string $j, int $no) => ['no' => $no, 'judul' => $j, 'teks' => $teks[$no] ?? ''], $judul, array_keys($judul));
        $v14[12]['teks'] = implode("\n", [...$eskalasi, 'Jangan pernah memberi nomor resi karangan.']);
        $v14[12]['keterangan'] = 'kapan Adiva memanggil admin';

        $v15 = $v14;
        array_splice($eskalasi, 7, 0, ['- order di atas 200 pcs untuk satu varian.']);
        $v15[12]['teks'] = implode("\n", [...$eskalasi, 'Jangan pernah memberi nomor resi atau harga karangan.']);

        $user = $user ?? User::query()->first();

        self::create([
            'versi' => 12,
            'ringkasan' => 'Program karyawan & singkatan',
            'bagian' => $v14,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDays(17),
        ]);
        self::create([
            'versi' => 13,
            'ringkasan' => 'Satu order aktif saja',
            'bagian' => $v14,
            'status' => StatusPrompt::Arsip,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDays(8),
        ]);

        $aktif = self::create([
            'versi' => 14,
            'ringkasan' => 'Tahap Tidak minat & aturan sapaan ulang',
            'bagian' => $v14,
            'status' => StatusPrompt::Aktif,
            'user_id' => $user?->id,
            'diterbitkan_at' => now()->subDay(),
        ]);

        self::create([
            'versi' => 15,
            'ringkasan' => 'Eskalasi order besar, larang harga karangan',
            'bagian' => $v15,
            'status' => StatusPrompt::Draf,
            'user_id' => $user?->id,
            'diterbitkan_at' => null,
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
