<?php

namespace App\Support;

use App\Models\Broadcast;
use App\Models\CrmCustomer;
use App\Models\GambarProduk;
use Illuminate\Support\Facades\Storage;

/**
 * A campaign message as one customer receives it: the variables filled in like the
 * preview on Buat kampanye, then the buttons as numbered lines (WAHA cannot send
 * WhatsApp buttons) and the STOP line.
 */
class PesanBroadcast
{
    public const BARIS_STOP = 'Balas STOP bila tidak ingin menerima info lagi';

    public static function untuk(Broadcast $broadcast, ?CrmCustomer $customer): string
    {
        $opsi = $broadcast->opsi ?? [];

        return self::rakit($broadcast->pesan, array_values((array) ($opsi['tombol'] ?? [])), (bool) ($opsi['stop'] ?? false), $customer);
    }

    /**
     * @param  list<string>  $tombol
     */
    public static function rakit(string $pesan, array $tombol, bool $stop, ?CrmCustomer $customer): string
    {
        $teks = $customer ? strtr($pesan, [
            '{nama}' => Nama::panggilan((string) $customer->nama),
            '{kota}' => (string) $customer->kota,
            '{minat_produk}' => (string) $customer->minat_produk,
        ]) : $pesan;

        $bagian = [trim($teks)];

        if ($tombol !== []) {
            $bagian[] = collect($tombol)->values()->map(fn (string $t, int $i) => ($i + 1).'. '.$t)->implode("\n");
        }

        if ($stop) {
            $bagian[] = self::BARIS_STOP;
        }

        return implode("\n\n", $bagian);
    }

    /**
     * Where WAHA can fetch the campaign photo, or null when it has no file.
     *
     * @return array{url: string, mimetype: string}|null
     */
    public static function gambar(?GambarProduk $gambar): ?array
    {
        if (! $gambar) {
            return null;
        }

        $url = $gambar->image_url ?? ($gambar->path ? Storage::disk('public')->url($gambar->path) : null);

        return $url ? ['url' => $url, 'mimetype' => $gambar->mime_type ?? 'image/jpeg'] : null;
    }
}
