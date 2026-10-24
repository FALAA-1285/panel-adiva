<?php

namespace App\Http\Controllers;

use App\Enums\AksiPesanan;
use App\Enums\StatusOrder;
use App\Enums\TipeCustomer;
use App\Models\LogOrder;
use App\Models\OrderItem;
use App\Models\Riwayat;
use App\Support\AlurPesanan;
use App\Support\JamKerja;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\PengaturanToko;
use App\Support\PerintahPesanan;
use App\Support\Rupiah;
use App\Support\UbahItemPesanan;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detail pesanan & invoice (OrderDetail.dc.html): one order, its whole history and the
 * invoice the customer receives after ACC.
 */
class DetailPesananController extends Controller
{
    /**
     * Shown on the invoice while REKENING is still empty.
     */
    private const REKENING_KOSONG = 'Rekening belum diisi di Pengaturan';

    public function __invoke(Request $request, LogOrder $order): Response
    {
        $user = $request->user() ?? abort(403);
        $order->load(['items', 'customer:id,nama', 'dicatatOleh:id,name']);

        return Inertia::render('pesanan/detail', [
            'order' => [
                'id' => $order->order_id,
                'status' => ['label' => $order->status_order->label(), 'nada' => $order->status_order->nada()],
                // "Rina Maharani · Reseller · dicatat Adiva dari chat, 28 Sep 13:06"
                'sub' => collect([
                    $order->namaCustomer(),
                    $order->tipe_customer?->label(),
                    ($order->dicatatOleh ? "dicatat {$order->dicatatOleh->name}" : 'dicatat Adiva dari chat').', '.$order->tanggal->translatedFormat('j M H:i'),
                ])->filter()->implode(' · '),
            ],
            'langkah' => array_map(fn (array $l) => [...$l, 'waktu' => $this->waktu($l['waktu'])], AlurPesanan::langkah($order, lengkap: true)),
            // "Ubah item": allowed before ACC; the form starts from the current items
            'ubahItem' => [
                'alasan' => UbahItemPesanan::alasanTidakBisa($order, $user),
                'isian' => $order->items->map(fn (OrderItem $item) => [
                    'nama' => $item->nama_produk,
                    'qty' => (string) $item->qty,
                    'harga' => (string) $item->harga_satuan,
                ])->values(),
            ],
            'item' => $order->items->map(fn (OrderItem $item) => [
                'nama' => $item->nama_produk,
                'keterangan' => $item->keterangan_harga,
                'harga' => Rupiah::teks($item->harga_satuan),
                'qty' => $item->qty,
                'subtotal' => Rupiah::teks($item->subtotal),
            ]),
            'total' => [
                'produk' => Rupiah::teks($order->total_produk),
                // "Ongkir JNE REG · 1,6 kg"; nothing to send for an order picked up
                'ongkir' => $order->ambil_sendiri ? null : [
                    'label' => collect(['Ongkir '.$this->namaKurir($order), $order->beratTeks()])->filter()->implode(' · '),
                    ...$this->nilaiOngkir($order),
                ],
                'packing' => $order->biaya_packing > 0 ? Rupiah::teks($order->biaya_packing) : null,
                'tagihan' => Rupiah::teks($order->tagihan()),
            ],
            'pengiriman' => $this->pengiriman($order),
            'riwayat' => $order->riwayat()->orderByDesc('waktu')->orderByDesc('id')->get()->map(fn (Riwayat $r) => [
                'waktu' => $r->waktuSingkat(),
                // OrderDetail writes the full sentence where Pesanan has the short form
                'teks' => is_string($r->data['lengkap'] ?? null) ? $r->data['lengkap'] : $r->teks,
                'nada' => $r->nada,
            ]),
            'invoice' => $this->invoice($order),
            // the commands that apply now: the ones ending the order first, the next step last
            'aksi' => collect([AksiPesanan::Tolak, AksiPesanan::Batal, AksiPesanan::Acc, AksiPesanan::Lunas, AksiPesanan::Siap, AksiPesanan::Kirim, AksiPesanan::Selesai])
                ->filter(fn (AksiPesanan $a) => PerintahPesanan::alasanTidakBerlaku($a, $order, $user) === null)
                ->map(fn (AksiPesanan $a) => ['kode' => $a->value, 'label' => $a->label(), 'bahaya' => $a->gaya() === 'bahaya'])
                ->values(),
        ]);
    }

    /**
     * "Pengiriman & pembayaran".
     *
     * @return array<string, string>
     */
    private function pengiriman(LogOrder $o): array
    {
        $nomor = $o->no_hp ?? $o->nomor_wa;
        $jamBayar = $o->jamBatasBayar();

        return [
            'penerima' => $o->nama_penerima ?? $o->namaCustomer(),
            'nomor' => $nomor ? NomorWa::samarkanUjung($nomor) : '—',
            'alamat' => $o->alamat() ?? '—',
            'kurir' => collect([$this->namaKurir($o), $o->estimasi_kirim ? "estimasi {$o->estimasi_kirim}" : null])->filter()->implode(' · ') ?: '—',
            'berat' => $o->beratTeks() ?? '—',
            'metode' => $o->metodeSingkat() ?? '—',
            'batasBayar' => match (true) {
                $jamBayar === null => '—',
                $o->status_order === StatusOrder::MenungguKonfirmasi => "{$jamBayar} jam kerja setelah ACC",
                default => $o->batas_bayar_at?->translatedFormat('j M H:i') ?? '—',
            },
            'nomorBot' => NomorBot::daftar()[$o->sesi_wa ?? ''] ?? $o->sesi_wa ?? '—',
            // the invoice: Adiva sends it once the order is ACC'd
            'dikirimOleh' => match (true) {
                $o->tipe_customer === TipeCustomer::Karyawan => '—',
                $o->status_order === StatusOrder::MenungguKonfirmasi => 'Adiva · setelah ACC',
                $o->diacc_at !== null => 'Adiva · '.$o->diacc_at->translatedFormat('j M H:i'),
                default => '—',
            },
        ];
    }

    /**
     * "Pratinjau invoice": the PDF Adiva sends after ACC (a draft number before that).
     *
     * @return array<string, mixed>
     */
    private function invoice(LogOrder $o): array
    {
        $config = PengaturanToko::nilaiBanyak(['NAMA_TOKO', 'REKENING', 'CATATAN_INVOICE']);
        [$toko, $perusahaan] = array_pad(array_map('trim', explode('—', (string) $config['NAMA_TOKO'], 2)), 2, null);
        $jamBayar = $o->jamBatasBayar();
        $jamKerja = JamKerja::dariPengaturan();

        return [
            'nomor' => $o->invoice_number ?? PerintahPesanan::invoiceBerikutnya(),
            'draf' => $o->invoice_number === null,
            // "Unduh PDF": the file n8n made at ACC, kept in invoice_pdf (or a link n8n gave)
            'pdf' => match (true) {
                $o->invoice_number === null => null,
                $o->invoicePdf()->exists() => route('pesanan.invoice-pdf', $o),
                default => $o->invoice_pdf_url,
            },
            'toko' => $toko,
            // "CV TKT Bana Food · Sawojajar, Kota Malang"
            'perusahaan' => collect([$perusahaan, 'Sawojajar, Kota Malang'])->filter()->implode(' · '),
            'tanggal' => ($o->diacc_at ?? now())->translatedFormat('j F Y'),
            'kepada' => $o->nama_penerima ?? $o->namaCustomer(),
            'alamat' => $o->alamatInvoice(),
            'order' => $o->order_id,
            'item' => $o->items->map(fn (OrderItem $item) => [
                'nama' => $item->nama_produk,
                'qty' => $item->qty,
                'harga' => Rupiah::teks($item->harga_satuan),
                'subtotal' => Rupiah::teks($item->subtotal),
            ]),
            'produk' => Rupiah::teks($o->total_produk),
            'ongkir' => $o->ambil_sendiri ? null : ['label' => 'Ongkir '.$this->namaKurir($o), ...$this->nilaiOngkir($o)],
            'packing' => $o->biaya_packing > 0 ? Rupiah::teks($o->biaya_packing) : null,
            // the chatbot's invoices are always in Adiva's name
            'admin' => 'ADIVA',
            'total' => Rupiah::teks($o->tagihan()),
            // transfer lines only when the customer pays by transfer
            'rekening' => $jamBayar === null ? null : (filled($config['REKENING']) ? $config['REKENING'] : self::REKENING_KOSONG),
            // "5 jam kerja (07.00–21.00, Minggu tidak dihitung)"
            'ditahan' => $jamBayar === null ? null : "{$jamBayar} jam kerja (".collect([
                str_replace(':', '.', $jamKerja->mulai).'–'.str_replace(':', '.', $jamKerja->selesai),
                $jamKerja->libur !== [] ? implode(', ', $jamKerja->libur).' tidak dihitung' : null,
            ])->filter()->implode(', ').')',
            // a COD parcel always says so, whatever CATATAN_INVOICE says about the shipping cost
            'catatan' => match (true) {
                $o->ambil_sendiri => null,
                $o->ongkirCod() => 'Ongkir ekspedisi dibayar COD ke kurir saat paket sampai, tidak ikut ditransfer.',
                default => $config['CATATAN_INVOICE'],
            },
        ];
    }

    /**
     * The shipping cost as the invoice shows it: billed, or paid COD to the courier and
     * only there for information.
     *
     * @return array{nilai: string, cod: bool}
     */
    private function nilaiOngkir(LogOrder $o): array
    {
        if (! $o->ongkirCod()) {
            return ['nilai' => Rupiah::teks($o->ongkir), 'cod' => false];
        }

        return ['nilai' => ($o->ongkir > 0 ? Rupiah::teks($o->ongkir).' · ' : '').'COD ke kurir', 'cod' => true];
    }

    private function namaKurir(LogOrder $o): ?string
    {
        return $o->kurir_final ?? $o->kurir_pilihan;
    }

    private function waktu(?CarbonInterface $waktu): string
    {
        return $waktu?->translatedFormat('j M H:i') ?? '—';
    }
}
