<?php

namespace App\Http\Controllers\Katalog;

use App\Enums\KategoriAktivitas;
use App\Enums\Peran;
use App\Enums\StatusData;
use App\Enums\StatusOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\SimpanKaryawanRequest;
use App\Models\HargaKaryawan;
use App\Models\Karyawan;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\CsvSanitizer;
use App\Support\NomorWa;
use App\Support\PengaturanToko;
use App\Support\Rupiah;
use App\Support\TabKatalog;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Program karyawan tab (Karyawan.dc.html): verified employees buy at employee
 * prices, paid by salary deduction and handled by the PJ karyawan.
 */
class KaryawanController extends Controller
{
    /**
     * Karyawan terverifikasi shows the first five employees.
     */
    private const TAMPIL = 5;

    public function index(): Response
    {
        $bulanIni = [now()->startOfMonth(), now()->endOfMonth()];
        $nilai = DB::raw(LogOrder::sqlTagihan());
        $pj = User::pjKaryawan();
        $namaPj = $pj->name ?? Peran::PjKaryawan->label();

        return Inertia::render('katalog/karyawan', [
            'tab' => TabKatalog::hitungan(),
            'ringkasan' => $this->ringkasan($bulanIni),
            'pj' => [
                'nama' => $namaPj,
                'peran' => Peran::PjKaryawan->label(),
                // the number the order karyawan notifications go to, not the PJ's login account
                'nomor' => filled($sarahWa = PengaturanToko::nilai('SARAH_WA')) ? NomorWa::samarkanTengah($sarahWa) : null,
            ],
            'order' => LogOrder::query()
                ->programKaryawan()
                ->with(['karyawan:id,nama,nomor_wa', 'items'])
                ->latest('tanggal')
                ->limit(4)
                ->get()
                ->map(fn (LogOrder $order) => [
                    'id' => $order->id,
                    'kode' => $order->order_id,
                    'karyawan' => $order->karyawan->nama ?? $order->nama_penerima,
                    'item' => $order->items->map(fn (OrderItem $item) => "{$item->nama_produk} ×{$item->qty}")->implode(', '),
                    'nilai' => Rupiah::teks($order->tagihan()),
                    'status' => $this->status($order, $namaPj),
                ]),
            'karyawan' => Karyawan::query()
                // "Order": every order so far; "Potong gaji": this month's deductions
                ->withCount(['order' => fn ($q) => $q->programKaryawan()])
                ->withSum(['order as potong_gaji' => fn ($q) => $q->programKaryawan()->whereBetween('tanggal', $bulanIni)], $nilai)
                ->orderBy('id')
                ->limit(self::TAMPIL)
                ->get()
                ->map(fn (Karyawan $k) => [
                    'id' => $k->id,
                    'nama' => $k->nama,
                    'nomor' => $k->nomor_wa ? NomorWa::samarkanUjung($k->nomor_wa) : null,
                    'bagian' => $k->bagian,
                    'order' => (int) $k->order_count,
                    'potongGaji' => (int) $k->potong_gaji > 0 ? Rupiah::teks((int) $k->potong_gaji) : null,
                    'aktif' => $k->status === StatusData::Aktif->value,
                    // the edit form
                    'isian' => [
                        'nama' => $k->nama,
                        'nomor_wa' => (string) $k->nomor_wa,
                        'bagian' => (string) $k->bagian,
                        'aktif' => $k->status === StatusData::Aktif->value,
                        'catatan' => (string) $k->catatan,
                    ],
                ]),
            'harga' => HargaKaryawan::query()
                ->orderBy('id')
                ->get()
                ->map(fn (HargaKaryawan $h) => [
                    'id' => $h->id,
                    'varian' => $h->varian_produk,
                    'kode' => $h->singkatan,
                    'harga' => $h->harga_karyawan === null ? null : Rupiah::teks($h->harga_karyawan),
                    'batas' => $h->batas,
                    'hargaAngka' => $h->harga_karyawan,
                ]),
        ]);
    }

    /**
     * The four numbers at the top: one query over this month's employee orders and
     * one over the employees, instead of a count per number.
     *
     * @param  array{CarbonInterface, CarbonInterface}  $bulanIni
     * @return array{bulan: string, order: int, berjalan: int, potongGaji: string, karyawan: int, nonaktif: int, rataRata: string|null}
     */
    private function ringkasan(array $bulanIni): array
    {
        $order = LogOrder::query()
            ->programKaryawan()
            ->whereBetween('tanggal', $bulanIni)
            ->toBase()
            ->selectRaw(
                'count(*) as jumlah, count(*) filter (where status_order <> ?) as berjalan, coalesce(sum('.LogOrder::sqlTagihan().'), 0) as total',
                [StatusOrder::Selesai->value],
            )
            ->first();
        $karyawan = Karyawan::query()
            ->toBase()
            ->selectRaw('count(*) as semua, count(*) filter (where status = ?) as nonaktif', [StatusData::Nonaktif->value])
            ->first();

        $jumlah = (int) ($order->jumlah ?? 0);
        $total = (int) ($order->total ?? 0);

        return [
            'bulan' => now()->translatedFormat('F'),
            'order' => $jumlah,
            'berjalan' => (int) ($order->berjalan ?? 0),
            'potongGaji' => Rupiah::teks($total),
            'karyawan' => (int) ($karyawan->semua ?? 0),
            'nonaktif' => (int) ($karyawan->nonaktif ?? 0),
            // "Rp64.400": rounded to the hundred
            'rataRata' => $jumlah > 0 ? Rupiah::teks((int) round($total / $jumlah, -2)) : null,
        ];
    }

    public function store(SimpanKaryawanRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $karyawan = Karyawan::create($this->isian($request));

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Tambah karyawan', "{$karyawan->nama} · {$karyawan->bagian}", $karyawan);

        Inertia::flash('toast', "Karyawan {$karyawan->nama} ditambahkan.");

        return back();
    }

    public function update(SimpanKaryawanRequest $request, Karyawan $karyawan): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $karyawan->update($this->isian($request));

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah karyawan', "{$karyawan->nama} · {$karyawan->bagian}", $karyawan);

        Inertia::flash('toast', "Data {$karyawan->nama} disimpan.");

        return back();
    }

    /**
     * "Harga karyawan", Ubah: the employee price and limit of every row at once.
     */
    public function simpanHarga(Request $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $request->merge(['harga' => collect((array) $request->input('harga'))->map(fn ($h) => [
            ...(array) $h,
            'harga' => ($angka = preg_replace('/\D/', '', (string) ($h['harga'] ?? ''))) === '' ? null : (int) $angka,
        ])->all()]);
        $data = $request->validate([
            'harga' => ['required', 'array'],
            'harga.*.id' => ['required', 'integer', Rule::exists('harga_karyawan', 'id')],
            'harga.*.harga' => ['nullable', 'integer', 'min:0'],
            'harga.*.batas' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['harga'] as $baris) {
                HargaKaryawan::query()->whereKey($baris['id'])->update(['harga_karyawan' => $baris['harga'], 'batas' => $baris['batas'] ?? null]);
            }
        });

        Aktivitas::catat($user, KategoriAktivitas::Pengaturan, 'Ubah harga karyawan', count($data['harga']).' varian');

        Inertia::flash('toast', 'Harga karyawan disimpan.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function isian(SimpanKaryawanRequest $request): array
    {
        return [
            ...$request->safe()->only(['nama', 'nomor_wa', 'bagian', 'catatan']),
            'status' => ($request->boolean('aktif') ? StatusData::Aktif : StatusData::Nonaktif)->value,
        ];
    }

    /**
     * "Unduh rekap bulan ini": one line per employee order this month, for payroll.
     */
    public function rekap(): StreamedResponse
    {
        $bulanIni = [now()->startOfMonth(), now()->endOfMonth()];
        $pj = User::pjKaryawan()->name ?? Peran::PjKaryawan->label();
        $order = LogOrder::query()
            ->programKaryawan()
            ->whereBetween('tanggal', $bulanIni)
            ->with(['karyawan:id,nama,nomor_wa,bagian', 'items'])
            ->orderBy('tanggal');

        return response()->streamDownload(function () use ($order, $pj) {
            $keluar = fopen('php://output', 'w');

            if ($keluar === false) {
                return;
            }

            // BOM so Excel reads "·" and "×" as UTF-8
            fwrite($keluar, "\xEF\xBB\xBF");
            fputcsv($keluar, ['Tanggal', 'Order', 'Karyawan', 'Bagian', 'Nomor WA', 'Item', 'Potong gaji', 'Status'], escape: '');

            $order->lazy()->each(function (LogOrder $o) use ($keluar, $pj) {
                fputcsv($keluar, CsvSanitizer::baris([
                    $o->tanggal->format('Y-m-d H:i'),
                    $o->order_id,
                    $o->karyawan->nama ?? $o->nama_penerima,
                    $o->karyawan->bagian ?? '',
                    $o->karyawan->nomor_wa ?? $o->nomor_wa,
                    $o->items->map(fn (OrderItem $item) => "{$item->nama_produk} ×{$item->qty}")->implode(', '),
                    $o->tagihan(),
                    $this->status($o, $pj)['label'],
                ]), escape: '');
            });

            fclose($keluar);
        }, 'rekap-karyawan-'.now()->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Where an employee order stands: forwarded to the PJ, ready for pickup, or done.
     *
     * @return array{label: string, nada: string}
     */
    private function status(LogOrder $order, string $pj): array
    {
        return match (true) {
            $order->status_order === StatusOrder::Selesai => ['label' => 'Selesai', 'nada' => 'green'],
            $order->siap_diambil_at !== null => ['label' => 'Siap diambil', 'nada' => 'blue'],
            default => ['label' => "Diteruskan ke {$pj}", 'nada' => 'amber'],
        };
    }
}
