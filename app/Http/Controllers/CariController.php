<?php

namespace App\Http\Controllers;

use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use App\Models\LogOrder;
use App\Support\NomorWa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top bar's suggestions while typing: customers, their chats and orders, five of
 * each, so a name leads straight to the chat or the order and not only to Pelanggan
 * (Audit Panel Adiva: search only worked after Enter and always went to Pelanggan).
 */
class CariController extends Controller
{
    private const BATAS = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $kata = trim((string) $request->query('q'));

        if (mb_strlen($kata) < 2) {
            return response()->json(['customer' => [], 'chat' => [], 'pesanan' => []]);
        }

        $pola = '%'.addcslashes($kata, '%_\\').'%';
        $angka = ltrim((string) preg_replace('/\D/', '', $kata), '0');
        $cocok = fn (Builder $q) => $q->where('nama', 'ilike', $pola)
            ->when(strlen($angka) >= 3, fn (Builder $q) => $q->orWhere('nomor_wa', 'like', "%{$angka}%"));

        $customer = CrmCustomer::query()->where($cocok)->orderByDesc('tgl_interaksi_terakhir')->limit(self::BATAS)->get();
        $chat = CrmCustomer::query()->where($cocok)
            ->whereHas('pesan', fn ($q) => $q->where('pengirim', PengirimPesan::Customer))
            ->orderByDesc('tgl_interaksi_terakhir')->limit(self::BATAS)->get();
        $pesanan = LogOrder::query()
            ->where(fn (Builder $q) => $q->where('order_id', 'ilike', $pola)->orWhere('nama_penerima', 'ilike', $pola))
            ->orderByDesc('tanggal')->limit(self::BATAS)->get();

        $nomor = fn (CrmCustomer $c) => $c->nomor_wa ? NomorWa::samarkanUjung($c->nomor_wa) : null;

        return response()->json([
            'customer' => $customer->map(fn (CrmCustomer $c) => [
                'judul' => $c->nama ?? $nomor($c) ?? 'Tanpa nama',
                'sub' => collect([$c->tipe_customer->label(), $nomor($c)])->filter()->implode(' · '),
                'href' => route('pelanggan', ['pilih' => $c->id], false),
            ]),
            'chat' => $chat->map(fn (CrmCustomer $c) => [
                'judul' => $c->nama ?? $nomor($c) ?? 'Tanpa nama',
                'sub' => $c->ringkasan_terakhir,
                'href' => route('percakapan', ['pilih' => $c->id], false),
            ]),
            'pesanan' => $pesanan->map(fn (LogOrder $o) => [
                'judul' => $o->order_id,
                'sub' => collect([$o->namaCustomer(), $o->status_order->label()])->implode(' · '),
                'href' => route('pesanan.detail', $o->order_id, false),
            ]),
        ]);
    }
}
