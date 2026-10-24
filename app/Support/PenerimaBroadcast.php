<?php

namespace App\Support;

use App\Enums\SkorProspek;
use App\Enums\StatusOrder;
use App\Enums\TahapFunnel;
use App\Enums\TipeCustomer;
use App\Models\BroadcastPenerima;
use App\Models\CrmCustomer;
use App\Models\JedaBot;
use App\Models\Produk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who a campaign goes to (BroadcastNew.dc.html "Penerima"): the CRM filters, always
 * without opt-out and paused chats, and optionally without running orders or a
 * broadcast in the last 3 days.
 *
 * @phpstan-type Filter array{tipe: list<string>, tahap: list<string>, skor: list<string>, minat: list<string>, kota: string, interaksi: string, kecualikan_order: bool, kecualikan_baru: bool}
 */
class PenerimaBroadcast
{
    /**
     * "Sudah dapat broadcast 3 hari terakhir".
     */
    private const HARI_BROADCAST = 3;

    /**
     * A filter from the form, a template or a campaign, with unknown values dropped.
     *
     * @param  array<string, mixed>  $isi
     * @return Filter
     */
    public static function normalisasi(array $isi): array
    {
        $daftar = fn (string $kunci, callable $sah) => array_values(array_filter(
            array_map('strval', (array) ($isi[$kunci] ?? [])),
            $sah,
        ));

        return [
            'tipe' => $daftar('tipe', fn (string $v) => TipeCustomer::tryFrom($v) !== null),
            'tahap' => $daftar('tahap', fn (string $v) => TahapFunnel::tryFrom($v) !== null),
            'skor' => $daftar('skor', fn (string $v) => SkorProspek::tryFrom($v) !== null),
            'minat' => $daftar('minat', fn (string $v) => $v !== ''),
            'kota' => array_key_exists((string) ($isi['kota'] ?? ''), AturanBroadcast::KOTA) ? (string) $isi['kota'] : 'SEMUA',
            'interaksi' => array_key_exists((string) ($isi['interaksi'] ?? ''), AturanBroadcast::INTERAKSI) ? (string) $isi['interaksi'] : '90_HARI',
            'kecualikan_order' => filter_var($isi['kecualikan_order'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'kecualikan_baru' => filter_var($isi['kecualikan_baru'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  Filter  $filter
     * @return Builder<CrmCustomer>
     */
    public static function query(array $filter): Builder
    {
        $kata = self::kataMinat($filter['minat']);
        $kota = AturanBroadcast::KOTA[$filter['kota']]['kota'];

        return CrmCustomer::query()
            ->when($filter['tipe'] !== [], fn (Builder $q) => $q->whereIn('tipe_customer', $filter['tipe']))
            ->when($filter['tahap'] !== [], fn (Builder $q) => $q->whereIn('tahap_funnel', $filter['tahap']))
            ->when($filter['skor'] !== [], fn (Builder $q) => $q->whereIn('skor_prospek', $filter['skor']))
            ->when($kata !== [], fn (Builder $q) => $q->where(function (Builder $q) use ($kata) {
                foreach ($kata as $k) {
                    $q->orWhere('minat_produk', 'ilike', '%'.addcslashes($k, '%_\\').'%');
                }
            }))
            ->when($kota !== null, fn (Builder $q) => $q->whereIn('kota', $kota))
            ->when(
                $filter['interaksi'] === 'WINBACK',
                fn (Builder $q) => $q->where('tgl_interaksi_terakhir', '<', now()->subDays(90)),
                fn (Builder $q) => $q->where('tgl_interaksi_terakhir', '>=', now()->subDays((int) strtok($filter['interaksi'], '_'))),
            )
            ->where('status_optout', false)
            ->whereNotIn('chat_id', JedaBot::query()->menahan()->select('chat_id'))
            ->when($filter['kecualikan_order'], fn (Builder $q) => $q->whereDoesntHave('orders', fn (Builder $o) => $o->whereIn('status_order', StatusOrder::berjalan())))
            ->when($filter['kecualikan_baru'], fn (Builder $q) => $q->whereNotIn('id', BroadcastPenerima::query()
                ->select('crm_customer_id')
                ->whereNotNull('crm_customer_id')
                ->where(fn (Builder $p) => $p
                    ->where('terkirim_at', '>=', now()->subDays(self::HARI_BROADCAST))
                    ->orWhere('status', BroadcastPenerima::MENUNGGU))));
    }

    /**
     * "Reseller · Tanya harga, Repeat · HOT, WARM · minat Keripik kaca · Malang Raya".
     *
     * @param  Filter  $filter
     */
    public static function keterangan(array $filter): string
    {
        $bagian = [
            implode(', ', array_map(fn (string $v) => TipeCustomer::from($v)->label(), $filter['tipe'])),
            implode(', ', array_map(fn (string $v) => TahapFunnel::from($v)->label(), $filter['tahap'])),
            implode(', ', $filter['skor']),
            $filter['minat'] !== [] ? 'minat '.implode(', ', $filter['minat']) : '',
            $filter['kota'] !== 'SEMUA' ? Str::before(AturanBroadcast::KOTA[$filter['kota']]['label'], ' (') : '',
            $filter['interaksi'] !== '90_HARI' ? AturanBroadcast::INTERAKSI[$filter['interaksi']] : '',
        ];

        return collect($bagian)->filter()->implode(' · ') ?: 'Semua customer';
    }

    /**
     * How many customers are interested in a category.
     */
    public static function peminat(string $kategori): int
    {
        return CrmCustomer::query()->where(function (Builder $q) use ($kategori) {
            foreach (self::kataMinat([$kategori]) as $k) {
                $q->orWhere('minat_produk', 'ilike', '%'.addcslashes($k, '%_\\').'%');
            }
        })->count();
    }

    /**
     * Words a customer's interest is matched on: the category and the first word of
     * each product in it ("Keripik kaca" → "Keripik kaca", "Kripca").
     *
     * @param  list<string>  $minat
     * @return list<string>
     */
    private static function kataMinat(array $minat): array
    {
        if ($minat === []) {
            return [];
        }

        $produk = Produk::query()->whereIn('kategori_produk', $minat)->pluck('jenis_produk')
            ->map(fn (string $nama) => Str::before($nama, ' '));

        return array_values(array_unique([...$minat, ...$produk->all()]));
    }
}
