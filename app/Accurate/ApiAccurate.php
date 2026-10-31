<?php

namespace App\Accurate;

use App\Models\AksesAccurate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Accurate Online's item list, read page by page; only reading endpoints are called. The way in:
 * - OAuth, once "Sambungkan Accurate" has stored an AksesAccurate: Bearer + X-Session-ID on the
 *   host open-db.do gave (OauthAccurate renews the token and opens the database);
 * - else an API Token (ACCURATE_API_TOKEN + ACCURATE_SIGNATURE_SECRET): the token tells which
 *   database host to ask, every request signed.
 *
 * The endpoint and field names follow Accurate's API docs; `php artisan accurate:sinkron --coba`
 * shows what came back without saving, to check them against the real database.
 */
class ApiAccurate implements BacaAccurate
{
    private const AKUN = 'https://account.accurate.id';

    private const PER_HALAMAN = 100;

    /** a guard against a paging answer that never ends */
    private const HALAMAN_MAKS = 500;

    public function __construct(private OauthAccurate $oauth) {}

    public function barang(): array
    {
        $akses = AksesAccurate::sekarang();

        if ($akses !== null) {
            $this->oauth->siapkan($akses);
        }

        // the API Token's host is asked once; an OAuth host may change when the database reopens
        $hostToken = $akses === null ? $this->host() : null;
        $barang = [];
        $halaman = 1;
        $jumlahHalaman = 1;
        $dibukaUlang = false;

        do {
            $kueri = [
                'fields' => 'id,no,name,upcNo,unitPrice,unit1,itemCategory,suspended',
                'sp.page' => $halaman,
                'sp.pageSize' => self::PER_HALAMAN,
            ];

            try {
                $jawaban = $this->baca(($akses->host ?? $hostToken).'/accurate/api/item/list.do', $kueri, $akses);
            } catch (AccurateGagal $e) {
                // an OAuth session Accurate closed: open the database again, once
                if ($akses === null || $dibukaUlang || ! in_array($e->status, [401, 403], true)) {
                    throw $e;
                }

                $dibukaUlang = true;
                $this->oauth->bukaDatabase($akses);

                continue;
            }

            foreach ((array) ($jawaban['d'] ?? []) as $b) {
                if (is_array($b) && isset($b['id']) && filled($b['name'] ?? null)) {
                    $barang[] = self::baris($b);
                }
            }

            $jumlahHalaman = (int) data_get($jawaban, 'sp.pageCount', 1);
            $halaman++;
        } while ($halaman <= $jumlahHalaman && $halaman <= self::HALAMAN_MAKS);

        return $barang;
    }

    /**
     * The host of database ACCURATE_DB_ID, from the token's own information.
     */
    private function host(): string
    {
        $jawaban = $this->baca(self::AKUN.'/api/api-token.do');
        $db = (string) config('services.accurate.db_id');
        $database = collect((array) data_get($jawaban, 'd.database', []));
        // one database per token, or a list of them
        $pilihan = isset($database['host']) ? $database->all() : $database->first(fn ($d) => (string) data_get($d, 'id') === $db);
        $host = rtrim((string) data_get($pilihan, 'host', ''), '/');

        if ($host === '') {
            throw new AccurateGagal("Database Accurate {$db} tidak ada di API Token ini.");
        }

        return $host;
    }

    /**
     * @param  array<string, mixed>  $kueri
     * @return array<string, mixed>
     */
    private function baca(string $url, array $kueri = [], ?AksesAccurate $akses = null): array
    {
        try {
            $jawaban = ($akses ? $this->mintaOauth($akses) : $this->minta())->get($url, $kueri);
        } catch (ConnectionException $e) {
            throw new AccurateGagal('Accurate tidak bisa dihubungi ('.$e->getMessage().').');
        }

        $isi = $jawaban->json();

        if (! $jawaban->successful() || ! is_array($isi) || ($isi['s'] ?? false) !== true) {
            throw new AccurateGagal('Accurate menolak permintaan: '.self::alasan($jawaban, $isi, $akses), $jawaban->status());
        }

        return $isi;
    }

    private function mintaOauth(AksesAccurate $akses): PendingRequest
    {
        return Http::withToken($akses->access_token)
            ->withHeaders(['X-Session-ID' => (string) $akses->session, 'X-Language-Profile' => 'US'])
            ->acceptJson()
            ->timeout((int) config('services.accurate.timeout', 30))
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || $e->response?->status() === 429, throw: false);
    }

    private function minta(): PendingRequest
    {
        $token = (string) config('services.accurate.token');
        $rahasia = (string) config('services.accurate.signature_secret');

        if ($token === '' || $rahasia === '') {
            throw new AccurateGagal('Accurate belum tersambung: tekan Sambungkan Accurate di Katalog → Barang toko (atau isi ACCURATE_API_TOKEN dan ACCURATE_SIGNATURE_SECRET).');
        }

        // as Accurate's own API Token example (aol-integration/php-03): Unix seconds, signed
        // with HMAC-SHA256 in hex; real time, not the demo clock
        $waktu = (string) time();

        return Http::withToken($token)
            ->withHeaders([
                'X-Api-Timestamp' => $waktu,
                'X-Api-Signature' => hash_hmac('sha256', $waktu, $rahasia),
                'X-Language-Profile' => 'US',
            ])
            ->acceptJson()
            ->timeout((int) config('services.accurate.timeout', 30))
            // Accurate limits requests per second; a short wait clears it
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || $e->response?->status() === 429, throw: false);
    }

    /**
     * @param  array<string, mixed>  $b  one row of item/list.do
     * @return array{accurate_id: int, kode: string|null, nama: string, barcode: string|null, kategori: string|null, satuan: string|null, harga: int|null, nonaktif: bool}
     */
    private static function baris(array $b): array
    {
        $teks = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        // a related record comes as {"name": …}, or as a plain name
        $nama = fn (mixed $v): ?string => $teks(is_array($v) ? ($v['name'] ?? null) : $v);
        $harga = $b['unitPrice'] ?? null;

        return [
            'accurate_id' => (int) $b['id'],
            'kode' => $teks($b['no'] ?? null),
            'nama' => (string) $teks($b['name']),
            'barcode' => $teks($b['upcNo'] ?? null),
            'kategori' => $nama($b['itemCategory'] ?? null),
            'satuan' => $nama($b['unit1'] ?? null),
            'harga' => is_numeric($harga) && $harga > 0 ? (int) round((float) $harga) : null,
            'nonaktif' => (bool) ($b['suspended'] ?? false),
        ];
    }

    /**
     * @param  mixed  $isi  the decoded body
     */
    private static function alasan(Response $jawaban, mixed $isi, ?AksesAccurate $akses = null): string
    {
        $pesan = is_array($isi) ? ($isi['d'] ?? $isi['message'] ?? $isi['error_description'] ?? null) : null;
        $pesan = is_array($pesan) ? implode(' ', array_filter($pesan, 'is_string')) : $pesan;

        // Accurate repeats the token in "Invalid access token: …"; it must not reach the panel
        return OauthAccurate::samarkan('HTTP '.$jawaban->status().(filled($pesan) ? ' · '.mb_substr((string) $pesan, 0, 200) : ''), $akses);
    }
}
