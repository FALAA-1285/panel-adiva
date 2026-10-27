<?php

namespace App\Http\Controllers;

use App\Bot\KirimKeStaf;
use App\Bot\PengirimanGagal;
use App\Enums\KategoriAktivitas;
use App\Enums\SkorProspek;
use App\Enums\StatusBroadcast;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Http\Requests\SimpanBroadcastRequest;
use App\Models\Broadcast;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use App\Models\Produk;
use App\Models\TemplateBroadcast;
use App\Support\Aktivitas;
use App\Support\AturanBroadcast;
use App\Support\Nama;
use App\Support\NomorBot;
use App\Support\PenerimaBroadcast;
use App\Support\PengaturanToko;
use App\Support\PesanBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buat kampanye (BroadcastNew.dc.html): the recipients, the message, when and how fast,
 * with a WhatsApp preview.
 *
 * @phpstan-import-type Filter from PenerimaBroadcast
 */
class BuatBroadcastController extends Controller
{
    /**
     * Customers the preview can be shown for.
     */
    private const CONTOH = 2;

    public function create(Request $request): Response
    {
        $template = TemplateBroadcast::query()->orderBy('urutan')->orderBy('id')->get();
        $dari = $request->filled('dari') ? Broadcast::query()->find($request->integer('dari')) : null;
        $ubah = $request->filled('ubah')
            ? Broadcast::query()->where('status', StatusBroadcast::Draf)->find($request->integer('ubah'))
            : null;

        $awal = match (true) {
            // "Ubah" on a draft: saving writes over it
            $ubah !== null => [...$this->dariKampanye($ubah), 'ubah' => $ubah->id],
            $dari !== null => $this->dariKampanye($dari),
            // Pipeline "Broadcast ke segmen ini": an empty form for that segment
            $request->hasAny(['tipe', 'skor']) => [...$this->kosong(), 'filter' => PenerimaBroadcast::normalisasi([
                'tipe' => array_filter([(string) $request->query('tipe')]),
                'skor' => array_filter([(string) $request->query('skor')]),
            ])],
            // a new campaign starts empty: a filled-in template looked like a campaign ready to send
            default => $this->kosong(),
        };

        return Inertia::render('broadcast/baru', [
            'awal' => $awal,
            'template' => $template->map(fn (TemplateBroadcast $t) => ['id' => $t->id, 'nama' => $t->nama, 'isian' => $this->dariTemplate($t)]),
            'kosong' => $this->kosong(),
            'pilihan' => $this->pilihan(),
            'perkiraan' => PenerimaBroadcast::query($awal['filter'])->count(),
            'contoh' => $this->contoh(),
            'kuota' => $this->kuota(),
        ]);
    }

    /**
     * "Perkiraan": the recipients for the filter on the form, recounted as it changes.
     */
    public function perkiraan(Request $request): JsonResponse
    {
        return response()->json(['jumlah' => PenerimaBroadcast::query(PenerimaBroadcast::normalisasi($request->query()))->count()]);
    }

    /**
     * "Simpan draf", "Kirim sekarang" and "Jadwalkan kampanye".
     */
    public function store(SimpanBroadcastRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $filter = $request->filter();
        $aksi = $request->string('aksi')->value();
        $jumlah = PenerimaBroadcast::query($filter)->count();
        $nomorBot = $request->string('nomor_bot')->value();

        $data = [
            'nama' => $request->string('nama')->value(),
            'segmen_keterangan' => PenerimaBroadcast::keterangan($filter),
            'filter' => $filter,
            'opsi' => [
                'tombol' => array_values($request->array('tombol')),
                'stop' => $request->boolean('stop'),
                'konteks' => $request->boolean('konteks'),
                'nomor_bot' => $nomorBot !== '' ? $nomorBot : null,
            ],
            'pesan' => $request->string('pesan')->value(),
            'gambar_produk_id' => $request->integer('gambar') ?: null,
            'mode' => $aksi === 'jadwal' ? 'JADWAL' : 'SEKARANG',
            'kecepatan' => $request->string('kecepatan')->value(),
            'status' => match ($aksi) {
                'kirim' => StatusBroadcast::Mengirim,
                'jadwal' => StatusBroadcast::Terjadwal,
                default => StatusBroadcast::Draf,
            },
            'jumlah_target' => $jumlah,
            'jadwal_at' => match ($aksi) {
                'kirim' => now(),
                'jadwal' => $request->jadwal(),
                default => null,
            },
            'mulai_at' => $aksi === 'kirim' ? now() : null,
            'user_id' => $user->id,
        ];

        // a draft being edited is written over; otherwise a new campaign
        $draf = $request->filled('ubah') ? Broadcast::query()->where('status', StatusBroadcast::Draf)->find($request->integer('ubah')) : null;
        $broadcast = $draf !== null ? tap($draf)->update($data) : Broadcast::create($data);

        // "Daftar penerima dikunci saat kampanye mulai": a scheduled one is counted again then
        if ($aksi === 'kirim') {
            $broadcast->kunciPenerima();
        }

        if ($aksi !== 'draf') {
            $broadcast->mulaiKirim();
        }

        Aktivitas::catat($user, KategoriAktivitas::Broadcast, match ($aksi) {
            'kirim' => 'Kirim broadcast',
            'jadwal' => 'Jadwalkan broadcast',
            default => 'Simpan draf broadcast',
        }, "{$broadcast->nama} · {$jumlah} penerima", $broadcast);

        Inertia::flash('toast', match ($aksi) {
            'kirim' => "Kampanye {$broadcast->nama} mulai dikirim.",
            'jadwal' => "Kampanye {$broadcast->nama} dijadwalkan.",
            default => 'Draf kampanye disimpan.',
        });

        return to_route('broadcast', ['pilih' => $broadcast->id]);
    }

    /**
     * "Kirim uji ke supervisor": the message as the customer in the preview would get
     * it, sent right away to the supervisor's number (ADMIN_WA).
     */
    public function uji(Request $request, KirimKeStaf $kirimKeStaf): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'pesan' => ['required', 'string', 'max:4096'],
            'gambar' => ['nullable', 'integer', Rule::exists('gambar_produk', 'id')],
            'tombol' => ['nullable', 'array', 'max:3'],
            'tombol.*' => ['string', 'max:20'],
            'stop' => ['boolean'],
            'nomor_bot' => ['nullable', 'string', Rule::in(array_keys(NomorBot::daftar()))],
            'contoh' => ['nullable', 'integer'],
        ], [
            'nama.required' => 'Isi nama kampanye.',
            'pesan.required' => 'Isi pesan kampanye.',
        ]);

        $supervisor = PengaturanToko::nilai('ADMIN_WA');

        if (blank($supervisor)) {
            throw ValidationException::withMessages(['pesan' => 'Nomor supervisor belum diisi di Pengaturan.']);
        }

        $teks = PesanBroadcast::rakit($data['pesan'], array_values($data['tombol'] ?? []), $request->boolean('stop'), CrmCustomer::query()->whereKey($data['contoh'] ?? null)->first());
        $gambar = PesanBroadcast::gambar(GambarProduk::query()->whereKey($data['gambar'] ?? null)->first());

        try {
            $gambar
                ? $kirimKeStaf->kirimGambar($supervisor, $gambar['url'], $gambar['mimetype'], $teks, $data['nomor_bot'] ?? null)
                : $kirimKeStaf->kirim($supervisor, $teks, $data['nomor_bot'] ?? null);
        } catch (PengirimanGagal $e) {
            throw ValidationException::withMessages(['pesan' => 'Pesan uji gagal dikirim: '.$e->getMessage()]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Broadcast, 'Kirim uji broadcast', "{$data['nama']} · ke nomor supervisor", data: ['pesan' => $data['pesan']]);

        Inertia::flash('toast', 'Pesan uji dikirim ke nomor supervisor.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function dariTemplate(TemplateBroadcast $t): array
    {
        return [
            ...$this->kosong(),
            'template' => $t->id,
            'nama' => $t->nama_kampanye ?? '',
            'filter' => PenerimaBroadcast::normalisasi($t->filter ?? []),
            'pesan' => $t->pesan ?? '',
            'gambar' => $t->gambar_produk_id,
            'tombol' => $t->tombol ?? [],
        ];
    }

    /**
     * "Duplikat" on Broadcast: the same recipients, message and settings.
     *
     * @return array<string, mixed>
     */
    private function dariKampanye(Broadcast $b): array
    {
        $opsi = $b->opsi ?? [];

        return [
            ...$this->kosong(),
            'nama' => $b->nama,
            'filter' => PenerimaBroadcast::normalisasi($b->filter ?? []),
            'pesan' => $b->pesan,
            'gambar' => $b->gambar_produk_id,
            'tombol' => array_values((array) ($opsi['tombol'] ?? [])),
            'stop' => (bool) ($opsi['stop'] ?? true),
            'konteks' => (bool) ($opsi['konteks'] ?? true),
            'kecepatan' => array_key_exists((string) $b->kecepatan, AturanBroadcast::KECEPATAN) ? $b->kecepatan : 'AMAN',
            'nomor_bot' => (string) ($opsi['nomor_bot'] ?? ''),
        ];
    }

    /**
     * Template "Kosong", and the defaults the design starts with.
     *
     * @return array{template: int|null, nama: string, filter: Filter, pesan: string, gambar: int|null, tombol: list<string>, stop: bool, konteks: bool, mode: string, tanggal: string, jam: string, kecepatan: string, nomor_bot: string}
     */
    private function kosong(): array
    {
        // "Jadwalkan" starts two days ahead at 09:00 (BroadcastNew.dc.html: 30 Sep 09:00)
        $jadwal = CarbonImmutable::now()->addDays(2)->setTime(9, 0);

        return [
            'ubah' => null,
            'template' => null,
            'nama' => '',
            'filter' => PenerimaBroadcast::normalisasi([]),
            'pesan' => '',
            'gambar' => null,
            'tombol' => [],
            'stop' => true,
            'konteks' => true,
            'mode' => 'SEKARANG',
            'tanggal' => $jadwal->format('Y-m-d'),
            'jam' => $jadwal->format('H:i'),
            'kecepatan' => 'AMAN',
            'nomor_bot' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pilihan(): array
    {
        $opsi = fn (array $isi) => array_map(fn (string $nilai, string $label) => ['nilai' => $nilai, 'label' => $label], array_keys($isi), $isi);

        return [
            'tipe' => array_map(fn (TipeCustomer $t) => ['nilai' => $t->value, 'label' => $t->label()], TipeCustomer::cases()),
            'tahap' => array_map(fn (TahapFunnel $t) => ['nilai' => $t->value, 'label' => $t->label()], TahapFunnel::cases()),
            'skor' => array_map(fn (SkorProspek $s) => ['nilai' => $s->value, 'label' => $s->value], SkorProspek::cases()),
            'minat' => $this->minat(),
            'kota' => $opsi(array_map(fn (array $k) => $k['label'], AturanBroadcast::KOTA)),
            'interaksi' => $opsi(AturanBroadcast::INTERAKSI),
            'kecepatan' => array_map(fn (string $nilai, array $k) => ['nilai' => $nilai, 'label' => $k['label'], 'detik' => $k['detik']], array_keys(AturanBroadcast::KECEPATAN), AturanBroadcast::KECEPATAN),
            'nomorBot' => [['nilai' => '', 'label' => 'Ikuti nomor customer'], ...$opsi(array_map(fn (string $label) => "{$label} saja", NomorBot::daftar()))],
            'gambar' => GambarProduk::query()->where('aktif', true)->with('produk')->orderBy('id')->get()
                ->map(fn (GambarProduk $g) => ['id' => $g->id, 'judul' => $g->title, 'kategori' => $g->produk?->kategori_produk]),
        ];
    }

    /**
     * The catalogue's categories, the ones most customers are interested in first
     * (catalogue order between equals).
     *
     * @return list<string>
     */
    private function minat(): array
    {
        $kategori = [];

        foreach (Produk::query()->dijual()->orderBy('id')->whereNotNull('kategori_produk')->get() as $produk) {
            if ($produk->kategori_produk !== null && ! in_array($produk->kategori_produk, $kategori, true)) {
                $kategori[] = $produk->kategori_produk;
            }
        }

        $peminat = array_map(fn (string $k) => PenerimaBroadcast::peminat($k), $kategori);
        $urutan = array_keys($kategori);
        // usort keeps equals in catalogue order
        usort($urutan, fn (int $a, int $b) => $peminat[$b] <=> $peminat[$a]);

        return array_map(fn (int $i) => $kategori[$i], $urutan);
    }

    /**
     * The latest chats, for "Pratinjau untuk": the name, city and interest fill the
     * variables ("Halo kak {nama}" → "Halo kak Rina").
     *
     * @return Collection<int, array{id: int, nama: string, panggilan: string, kota: string|null, minat: string|null}>
     */
    private function contoh(): Collection
    {
        return CrmCustomer::query()
            ->whereNotNull('nama')
            ->orderByDesc('tgl_interaksi_terakhir')
            ->limit(self::CONTOH)
            ->get()
            ->map(fn (CrmCustomer $c) => [
                'id' => $c->id,
                'nama' => $c->namaTampil(),
                'panggilan' => Nama::panggilan((string) $c->nama),
                'kota' => $c->kota,
                'minat' => $c->minat_produk,
            ]);
    }

    /**
     * "Kuota harian tersisa": the daily limit less what was sent today.
     */
    private function kuota(): int
    {
        return max(0, AturanBroadcast::BATAS_HARIAN - AturanBroadcast::terkirimHariIni());
    }
}
