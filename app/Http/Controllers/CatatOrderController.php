<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAktivitas;
use App\Enums\StatusOrder;
use App\Http\Requests\CatatOrderRequest;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Support\Aktivitas;
use App\Support\Nama;
use App\Support\NomorBot;
use App\Support\NomorWa;
use App\Support\Rupiah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catat order manual (OrderNew.dc.html): an order taken by phone or at the shop, the
 * same as CATAT on WhatsApp. It waits for ACC like any other order.
 */
class CatatOrderController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('pesanan/baru', [
            // "CATAT <nama>": the customer whose name matches best
            'customer' => fn () => $this->cari(trim((string) $request->query('cari'))),
        ]);
    }

    public function store(CatatOrderRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $customer = $request->customer() ?? abort(404);
        $item = $request->item();
        $jumlah = $request->jumlahItem();
        // "Ongkir tidak dihitung untuk order manual — masukkan ke total bila perlu"
        $ongkir = $request->filled('total') ? $request->integer('total') - $jumlah : 0;

        $order = retry(3, fn () => DB::transaction(function () use ($request, $user, $customer, $item, $jumlah, $ongkir) {
            $order = LogOrder::create([
                'order_id' => $this->nomorBerikutnya(),
                'crm_customer_id' => $customer->id,
                'chat_id' => $customer->chat_id,
                'nomor_wa' => $customer->nomor_wa,
                'sesi_wa' => $customer->sesi_wa,
                'nama_penerima' => $customer->nama,
                'tipe_customer' => $customer->tipe_customer,
                'tanggal' => now(),
                'status_order' => StatusOrder::MenungguKonfirmasi,
                'metode_bayar' => 'TRANSFER',
                'total_produk' => $jumlah,
                'ongkir' => $ongkir,
                'kurir_pilihan' => $request->validated('kurir'),
                'alamat_lengkap' => $request->validated('alamat'),
                'pesanan_tambahan' => $request->boolean('tambahan'),
                'dicatat_oleh_id' => $user->id,
                'catatan_admin' => 'dicatat manual oleh admin',
            ]);

            foreach ($item as $i => $baris) {
                $order->items()->create([
                    'urutan' => $i + 1,
                    'nama_produk' => $baris['nama'],
                    'qty' => $baris['qty'],
                    'harga_satuan' => $baris['harga'],
                    'subtotal' => $baris['qty'] * $baris['harga'],
                ]);
            }

            $order->riwayat()->create([
                'waktu' => now(),
                'jenis' => 'DICATAT',
                'nada' => 'blue',
                'teks' => "Dicatat manual oleh {$user->name} dari panel",
            ]);

            Aktivitas::catat($user, KategoriAktivitas::Pesanan, 'Catat order manual', implode(' · ', [
                $order->order_id, $customer->nama, Rupiah::teks($jumlah + $ongkir),
            ]), $order);

            return $order;
        }), 100);

        Inertia::flash('toast', "{$order->order_id} dicatat · Menunggu ACC.");

        return to_route('pesanan.detail', $order);
    }

    /**
     * The customer card under the search field.
     *
     * @return array<string, mixed>|null
     */
    private function cari(string $cari): ?array
    {
        if (mb_strlen($cari) < 2) {
            return null;
        }

        $customer = CrmCustomer::query()
            ->where('nama', 'ilike', '%'.addcslashes($cari, '%_\\').'%')
            // names that start with what was typed first, then the latest chat
            ->orderByRaw('nama ilike ? desc', [addcslashes($cari, '%_\\').'%'])
            ->orderByDesc('tgl_interaksi_terakhir')
            ->first();

        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'inisial' => Nama::inisialCustomer($customer->nama ?? ''),
            'nama' => $customer->nama,
            'nomor' => $customer->nomor_wa ? NomorWa::samarkanUjung($customer->nomor_wa) : null,
            'tipe' => $customer->tipe_customer->label(),
            'kota' => $customer->kota,
            'nomorBot' => NomorBot::daftar()[$customer->sesi_wa ?? ''] ?? $customer->sesi_wa,
            'orderBerjalan' => $customer->orderBerjalan()?->order_id,
        ];
    }

    /**
     * "ORD-260928-015": the next number of the day.
     */
    private function nomorBerikutnya(): string
    {
        $awalan = 'ORD-'.now()->format('ymd').'-';
        $terakhir = LogOrder::query()
            ->where('order_id', 'like', $awalan.'%')
            ->lockForUpdate()
            ->orderByDesc('order_id')
            ->value('order_id');

        $nomor = $terakhir && str_starts_with($terakhir, $awalan) ? (int) substr($terakhir, strlen($awalan)) : 0;

        return $awalan.sprintf('%03d', $nomor + 1);
    }
}
