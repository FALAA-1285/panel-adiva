<?php

namespace App\Http\Controllers;

use App\Bot\KirimUlangInvoice;
use App\Bot\PerintahGagal;
use App\Bot\PerintahOrder;
use App\Enums\AksiPesanan;
use App\Enums\KategoriAktivitas;
use App\Enums\PengirimPesan;
use App\Enums\StatusOrder;
use App\Enums\TabPesanan;
use App\Http\Requests\AksiPesananRequest;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\Pesan;
use App\Models\Riwayat;
use App\Models\User;
use App\Support\Aktivitas;
use App\Support\AlurPesanan;
use App\Support\CsvUnduhan;
use App\Support\JamKerja;
use App\Support\NomorWa;
use App\Support\PerintahPesanan;
use App\Support\Rupiah;
use App\Support\UbahItemPesanan;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pesanan (Orders.dc.html): the orders by status, the selected order and the same
 * commands the admin sends on WhatsApp.
 */
class PesananController extends Controller
{
    /** Orders per page of the list. */
    private const PER_HALAMAN = 25;

    /** How the list may be sorted; "status" is the workflow order (running first). */
    private const URUTAN = ['status', 'terbaru', 'terlama', 'total'];

    /** Seconds an order command waits for another command on the same order to finish. */
    private const TUNGGU_KUNCI = 15;

    public function index(Request $request): Response
    {
        $user = $request->user() ?? abort(403);
        $tab = TabPesanan::tryFrom((string) $request->query('tab')) ?? TabPesanan::Semua;
        $urut = in_array($request->query('urut'), self::URUTAN, true) ? (string) $request->query('urut') : 'status';
        $halaman = max(1, $request->integer('halaman', 1));

        return Inertia::render('pesanan', [
            'tab' => fn () => array_map(fn (TabPesanan $t) => [
                'kode' => $t->value,
                'label' => $t->label(),
                'n' => $this->hitungan()[$t->value] ?? 0,
            ], TabPesanan::cases()),
            'aktifTab' => $tab->value,
            'urut' => $urut,
            'halaman' => fn () => ['sekarang' => $halaman, 'total' => $this->daftar($tab)->reorder()->count(), 'per' => self::PER_HALAMAN],
            'baris' => fn () => $this->daftar($tab, $urut)->forPage($halaman, self::PER_HALAMAN)->get()->map(fn (LogOrder $o) => $this->baris($o)),
            // ?order=…, else the first order in the tab
            'detail' => function () use ($request, $tab, $user, $urut) {
                $order = $request->filled('order')
                    ? LogOrder::query()->where('order_id', $request->query('order'))->with(['items', 'customer:id,nama'])->first()
                    : $this->daftar($tab, $urut)->first();

                return $order ? $this->detail($order, $user) : null;
            },
        ]);
    }

    /**
     * A command button: ACC, LUNAS, SIAP, KIRIM, SELESAI, BATAL, TOLAK, or under
     * "Lainnya" TUTUP.
     */
    public function aksi(AksiPesananRequest $request, LogOrder $order, PerintahOrder $perintah): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $aksi = $request->aksi();
        $catatan = $request->validated('catatan');

        // One command per order at a time: two staff pressing ACC together would otherwise
        // both pass the status check and send two invoices (Audit AUD-006). The second one
        // waits, then is checked against the status the first one left.
        try {
            Cache::lock("pesanan:{$order->id}", 60)->block(self::TUNGGU_KUNCI, function () use ($aksi, $order, $user, $catatan, $perintah) {
                $order->refresh();

                if ($alasan = PerintahPesanan::alasanTidakBerlaku($aksi, $order, $user, $catatan)) {
                    throw ValidationException::withMessages(['aksi' => $alasan]);
                }

                // an expedition parcel is sent by the CS in the courier's app: KIRIM records its waybill
                if ($aksi === AksiPesanan::Kirim && blank($catatan) && PerintahPesanan::butuhResi($order)) {
                    throw ValidationException::withMessages(['aksi' => 'Nomor resinya belum disertakan. Contoh: JNE1234567890']);
                }

                try {
                    $perintah->jalankan($aksi, $order, $user, $catatan);
                } catch (PerintahGagal $e) {
                    throw ValidationException::withMessages(['aksi' => $e->getMessage()]);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['aksi' => "{$order->order_id} sedang diproses staf lain. Tunggu sebentar, lalu coba lagi."]);
        }

        Inertia::flash('toast', "{$aksi->value} {$order->order_id} · {$aksi->keterangan()}.");

        return back();
    }

    /**
     * Detail pesanan, "Ubah item": before ACC the items and the product total can change.
     */
    public function ubahItem(Request $request, LogOrder $order): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        if ($alasan = UbahItemPesanan::alasanTidakBisa($order, $user)) {
            throw ValidationException::withMessages(['item' => $alasan]);
        }

        $angka = fn (mixed $v): ?int => ($d = preg_replace('/\D/', '', (string) $v)) === '' || $d === null ? null : (int) $d;
        $request->merge(['item' => collect((array) $request->input('item'))->map(fn ($b) => [
            'nama' => trim((string) ($b['nama'] ?? '')),
            'qty' => $angka($b['qty'] ?? null),
            'harga' => $angka($b['harga'] ?? null),
        ])->values()->all()]);
        $data = $request->validate([
            'item' => ['required', 'array', 'min:1'],
            'item.*.nama' => ['required', 'string', 'max:255'],
            'item.*.qty' => ['required', 'integer', 'min:1'],
            'item.*.harga' => ['required', 'integer', 'min:0'],
        ], [
            'item.required' => 'Order harus punya paling sedikit satu item.',
            'item.min' => 'Order harus punya paling sedikit satu item.',
            'item.*.nama.required' => 'Isi nama tiap item.',
            'item.*.qty.required' => 'Isi jumlah tiap item.',
            'item.*.qty.min' => 'Jumlah tiap item paling sedikit 1.',
            'item.*.harga.required' => 'Isi harga tiap item.',
        ]);

        UbahItemPesanan::simpan($order, $data['item'], $user);

        Inertia::flash('toast', "Item {$order->order_id} disimpan.");

        return back();
    }

    /**
     * "Kirim ulang ke customer": n8n sends the invoice PDF again.
     */
    public function kirimUlangInvoice(Request $request, LogOrder $order, KirimUlangInvoice $invoice): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        if ($order->invoice_number === null) {
            throw ValidationException::withMessages(['invoice' => 'Order ini belum punya invoice.']);
        }

        try {
            $invoice->kirim($order, $user);
        } catch (PerintahGagal $e) {
            throw ValidationException::withMessages(['invoice' => $e->getMessage()]);
        }

        Aktivitas::catat($user, KategoriAktivitas::Pesanan, 'Kirim ulang invoice', "{$order->order_id} · {$order->invoice_number}", $order);

        Inertia::flash('toast', "Invoice {$order->invoice_number} dikirim ulang ke customer.");

        return back();
    }

    /**
     * "Unduh PDF": the invoice PDF n8n made and sent to the customer.
     */
    public function invoicePdf(LogOrder $order): HttpResponse
    {
        $pdf = $order->invoicePdf ?? abort(404);

        return response($pdf->bytes(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $pdf->nama_file).'"',
        ]);
    }

    /**
     * "Ekspor CSV": the orders in the open tab.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $tab = TabPesanan::tryFrom((string) $request->query('tab')) ?? TabPesanan::Semua;
        $query = $this->daftar($tab);

        return CsvUnduhan::buat(
            "pesanan-{$tab->value}-".now()->format('Y-m-d').'.csv',
            ['Order', 'Tanggal', 'Customer', 'Tipe', 'Item', 'Total', 'Metode bayar', 'Kurir', 'Status'],
            $query->lazy()->map(fn (LogOrder $o) => [
                $o->order_id,
                $o->tanggal->format('Y-m-d H:i'),
                $o->namaCustomer(),
                $o->tipe_customer?->label(),
                $this->item($o),
                $o->tagihan(),
                $o->metode_bayar,
                $o->kurir(),
                $o->status_order->label(),
            ]),
        );
    }

    /**
     * The orders in a tab, in Pesanan's order.
     *
     * @return Builder<LogOrder>
     */
    private function daftar(TabPesanan $tab, string $urut = 'status'): Builder
    {
        $query = LogOrder::query()->tampilDiPesanan()->with(['items', 'customer:id,nama']);
        $tab->terapkan($query);

        return match ($urut) {
            'terbaru' => $query->orderByDesc('tanggal')->orderByDesc('id'),
            'terlama' => $query->orderBy('tanggal')->orderBy('id'),
            'total' => $query->orderByRaw(LogOrder::sqlTagihan().' desc')->orderByDesc('id'),
            default => $query->urutanPesanan(),
        };
    }

    /**
     * @return array<string, int>
     */
    private function hitungan(): array
    {
        return once(fn () => TabPesanan::hitungan(LogOrder::query()->tampilDiPesanan()));
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(LogOrder $o): array
    {
        return [
            'id' => $o->order_id,
            'tanggal' => $o->tanggal->translatedFormat('j M H:i'),
            'nama' => $o->namaCustomer(),
            'tipe' => $o->tipe_customer?->label(),
            'ringkas' => collect([$this->item($o), $o->kurir()])->filter()->implode(' · '),
            'total' => Rupiah::teks($o->tagihan()),
            'bayar' => $o->metodeSingkat(),
            'status' => ['label' => $o->status_order->label(), 'nada' => $o->status_order->nada()],
            'bukti' => $o->buktiMenunggu(),
            // the photo itself, from the customer's chat (Percakapan keeps it)
            'buktiUrl' => $o->buktiMenunggu() ? $this->fotoBukti($o) : null,
            'dobel' => $this->dobel($o),
        ];
    }

    /**
     * The selected order in the right panel.
     *
     * @return array<string, mixed>
     */
    private function detail(LogOrder $o, User $user): array
    {
        $nomor = $o->no_hp ?? $o->nomor_wa;

        return [
            'id' => $o->order_id,
            'status' => ['label' => $o->status_order->label(), 'nada' => $o->status_order->nada()],
            'nama' => $o->namaCustomer(),
            'tipe' => $o->tipe_customer?->label(),
            'dibuat' => $o->tanggal->translatedFormat('j M H:i'),
            'customerId' => $o->crm_customer_id,
            // the time under each step is what makes the strip read as a trail and not
            // as a second row of tabs under the status filter
            'langkah' => array_map(fn (array $l) => [
                ...array_diff_key($l, ['waktu' => true]),
                'waktu' => $l['waktu'] instanceof CarbonInterface ? $l['waktu']->timezone((string) config('app.timezone'))->format('j M H:i') : null,
            ], AlurPesanan::langkah($o)),
            'item' => $o->items->map(fn (OrderItem $item) => [
                'nama' => $item->namaTampil(),
                'qty' => $item->qty,
                'subtotal' => Rupiah::teks($item->subtotal),
            ]),
            'produk' => Rupiah::teks($o->total_produk),
            // an ONGKIR COD cost is paid to the courier and is not in the total
            'ongkir' => $o->ongkirCod() ? ($o->ongkir > 0 ? Rupiah::teks($o->ongkir).' · ' : '').'COD ke kurir' : Rupiah::teks($o->ongkir),
            'packing' => $o->biaya_packing > 0 ? Rupiah::teks($o->biaya_packing) : null,
            'kurir' => $o->kurir(),
            'total' => Rupiah::teks($o->tagihan()),
            'penerima' => $o->nama_penerima ?? $o->namaCustomer(),
            'hp' => $nomor ? NomorWa::samarkanUjung($nomor) : '—',
            'alamat' => $o->alamat() ?? '—',
            'metode' => $o->metode_bayar ?? '—',
            'berat' => $o->beratTeks() ?? '—',
            'invoice' => $o->invoice_number ?? '—',
            'resi' => $o->no_resi ?? '—',
            'adaInvoice' => $o->invoice_number !== null,
            'bukti' => $o->buktiMenunggu(),
            'dobel' => $this->dobel($o),
            'riwayat' => $this->riwayat($o),
            'aksi' => array_map(fn (AksiPesanan $a) => $this->tombolAksi($a, $o, $user), AksiPesanan::utama()),
            'lainnya' => array_map(fn (AksiPesanan $a) => $this->tombolAksi($a, $o, $user), AksiPesanan::lainnya()),
        ];
    }

    private function fotoBukti(LogOrder $o): ?string
    {
        $pesan = Pesan::query()
            ->where('crm_customer_id', $o->crm_customer_id)
            ->where('pengirim', PengirimPesan::Customer)
            ->whereNotNull('lampiran_url')
            ->latest('waktu')
            ->first();

        return $pesan ? route('percakapan.lampiran', $pesan) : null;
    }

    /**
     * One command button. ACC on an order already ACC'd turns on once a new shipping
     * cost is typed in the note (a revised cost).
     *
     * @return array{kode: string, boleh: bool, bolehDenganOngkir: bool, judul: string, gaya: string}
     */
    private function tombolAksi(AksiPesanan $a, LogOrder $o, User $user): array
    {
        $alasan = PerintahPesanan::alasanTidakBerlaku($a, $o, $user);
        $denganOngkir = $alasan !== null && PerintahPesanan::alasanTidakBerlaku($a, $o, $user, '25000') === null;

        return [
            'kode' => $a->value,
            'boleh' => $alasan === null,
            'bolehDenganOngkir' => $denganOngkir,
            'judul' => $alasan ?? "{$a->value} → {$a->keterangan()}",
            'gaya' => $a->gaya(),
        ];
    }

    /**
     * The order's status history (the conversation before it is on OrderDetail only),
     * plus the payment deadline while it is still waiting.
     *
     * @return list<array{waktu: string, teks: string}>
     */
    private function riwayat(LogOrder $o): array
    {
        $baris = $o->riwayat()
            ->where('jenis', '!=', 'PERCAKAPAN')
            ->orderBy('waktu')
            ->get()
            ->map(fn (Riwayat $r) => ['waktu' => $r->waktuSingkat(), 'teks' => $r->teks])
            ->all();

        // "— · Batas bayar 5 jam kerja (BATAS_BAYAR_JAM) · sisa 2 jam kerja"
        if ($o->status_order === StatusOrder::Diacc && $o->batas_bayar_at !== null && ! $o->buktiMenunggu()) {
            $sisa = JamKerja::dariPengaturan()->sisa(now(), $o->batas_bayar_at);
            $baris[] = [
                'waktu' => '—',
                'teks' => "Batas bayar {$o->jamBatasBayar()} jam kerja ({$o->kunciBatasBayar()}) · sisa {$sisa} jam kerja",
            ];
        }

        return array_values($baris);
    }

    /**
     * "Molreng Reguler Bawang Original ×40, Basreng Pedas Daun Jeruk ×30".
     */
    private function item(LogOrder $o): string
    {
        return $o->items->map(fn (OrderItem $item) => "{$item->namaTampil()} ×{$item->qty}")->implode(', ');
    }

    /**
     * "Mungkin dobel": the same as an order already paid, still to be checked before ACC.
     */
    private function dobel(LogOrder $o): bool
    {
        return $o->dobel_dengan !== null && $o->status_order === StatusOrder::MenungguKonfirmasi;
    }
}
