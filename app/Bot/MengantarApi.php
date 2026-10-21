<?php

namespace App\Bot;

use App\Support\PengaturanToko;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mengantar's public API (api-public.mengantar.com): finding a destination, rates, pickup
 * schedule, booking and cancelling. It holds the API key from CONFIG_TOKO (MENGANTAR_API_KEY)
 * and never lets it into an error message.
 *
 * Finding the destination is the hard part. Mengantar's search gives at most 50 hits and
 * often other regions with a similar name ("Lawang" → Aceh, "Malang" → Banten), so it is
 * searched step by step and the hits are scored; the scoring is the one n8n's tools always
 * used (kept as it was, see docs/kontrak-n8n.md).
 */
class MengantarApi
{
    private const FILLER = '/^(kota|kabupaten|kab|kec|kecamatan|kel|kelurahan|desa|ds|prov|provinsi)$/i';

    private const ALAMAT_JALAN = '/^\s*(jl|jln|jalan|gg|gang|perum|perumahan|blok|no|rt|rw)\b/i';

    private ?string $kunci = null;

    public function terisi(): bool
    {
        return $this->kunci() !== '';
    }

    /**
     * One call. A refusal (HTTP error, or `success: false`) becomes MengantarGagal with
     * Mengantar's own reason, so the admin sees why and not just "status code 400".
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $isi
     * @return array<string, mixed>|list<mixed>
     *
     * @throws MengantarGagal
     */
    public function panggil(string $metode, string $jalur, array $query = [], ?array $isi = null): array
    {
        $kunci = $this->kunci();

        if ($kunci === '') {
            throw new MengantarGagal('MENGANTAR_API_KEY di CONFIG_TOKO masih kosong');
        }

        $url = "https://api-public.mengantar.com/api/public/{$kunci}{$jalur}";

        try {
            $permintaan = Http::acceptJson()->timeout((int) config('services.mengantar.timeout', 30));
            $jawaban = $isi === null
                ? $permintaan->send($metode, $url, ['query' => $query])
                : $permintaan->asJson()->send($metode, $url, ['query' => $query, 'json' => $isi]);
        } catch (ConnectionException $e) {
            throw new MengantarGagal($this->sembunyi('gagal menghubungi Mengantar: '.$e->getMessage()), previous: $e);
        }

        $badan = $jawaban->json();
        $badan = is_array($badan) ? $badan : [];

        if ($jawaban->status() >= 400 || ($badan['success'] ?? null) === false) {
            $alasan = (string) ($badan['message'] ?? $badan['error'] ?? $badan['msg'] ?? '');

            if (is_array($badan['errors'] ?? null) && $badan['errors'] !== []) {
                $alasan .= ($alasan !== '' ? ' — ' : '').collect($badan['errors'])->map(fn ($e) => is_array($e)
                    ? (isset($e['path']) ? $e['path'].': ' : '').($e['message'] ?? $e['msg'] ?? json_encode($e))
                    : (string) $e)->implode('; ');
            }

            $langkah = strtoupper($metode).' '.$jalur;

            throw new MengantarGagal($this->sembunyi("Mengantar menolak {$langkah} (kode {$jawaban->status()}): ".($alasan !== '' ? $alasan : mb_substr($jawaban->body(), 0, 300))));
        }

        return $badan;
    }

    /**
     * @return list<array<string, mixed>> the destinations for a keyword, best match first
     *
     * @throws MengantarGagal when Mengantar refuses the API key
     */
    public function cariDaftar(string $kata): array
    {
        try {
            $hasil = $this->panggil('GET', '/address/search', ['keyword' => $kata]);
        } catch (MengantarGagal $e) {
            // a search that finds nothing is just a miss; a refused key is not, or "Daerah tidak ditemukan" would hide it
            if (preg_match('/kode (401|403)/', $e->getMessage())) {
                throw $e;
            }

            return [];
        }

        $daftar = array_is_list($hasil) ? $hasil : (array) ($hasil['data'] ?? []);
        $kw = strtoupper(trim($kata));

        usort($daftar, fn ($a, $b) => $this->skorKasar($b, $kw) <=> $this->skorKasar($a, $kw));

        return $daftar;
    }

    /**
     * The first hit that holds every word of the name searched, whole words only, so "Malang"
     * does not land in "Samalanga" (Aceh) or "Dau" in "Sultan Daulat".
     *
     * @return array<string, mixed>|null
     */
    public function cariAlamat(string $kata): ?array
    {
        foreach ($this->cariDaftar($kata) as $a) {
            if ($this->cocokKata($a, $kata)) {
                return $a;
            }
        }

        return null;
    }

    /**
     * The destination for an address ("Dinoyo, Lowokwaru, Malang"): the hit that holds the most
     * parts of it, preferring the exact district or city name and East Java.
     *
     * @return array<string, mixed>|null
     */
    public function cariTujuan(string $mentah): ?array
    {
        $asli = collect(preg_split('/[,;\/]+/', $mentah) ?: [])
            ->reject(fn ($s) => preg_match(self::ALAMAT_JALAN, $s) === 1)
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values();
        $bagian = $asli->map(fn ($s) => $this->bersihkanNama($s))->filter()->values();
        $kataBagian = $bagian->map(fn ($s) => $this->kata($s))->filter(fn ($k) => $k !== [])->values()->all();

        // a part written "Kota …" / "Kabupaten …" names a city, so the city of that exact name beats a district of it
        $kotaDisebut = [];
        foreach ($asli as $s) {
            if (preg_match('/^\s*(kota|kabupaten|kab\.?)\s+/i', $s) && ($kk = implode(' ', $this->kata($this->bersihkanNama($s)))) !== '') {
                $kotaDisebut[$kk] = true;
            }
        }

        $semua = array_values(array_unique(array_merge(...($kataBagian ?: [[]]))));

        if ($semua === []) {
            return null;
        }

        $gabung = implode(' ', $semua);
        $sebutProvinsi = (bool) preg_match('/\b(JAWA|BALI|SUMATERA|SUMATRA|KALIMANTAN|SULAWESI|PAPUA|MALUKU|NUSA|BANTEN|JAKARTA|YOGYAKARTA|ACEH|RIAU|JAMBI|BENGKULU|LAMPUNG|GORONTALO|BANGKA)\b/', $gabung);

        $skor = function (array $a) use ($kataBagian, $semua, $kotaDisebut, $sebutProvinsi): int {
            $isi = $this->isi($a);
            $cocokBagian = count(array_filter($kataBagian, fn ($k) => $this->semuaAda($k, $isi)));

            if ($cocokBagian === 0) {
                return -1;
            }

            $cocokKata = count(array_filter($semua, fn ($x) => str_contains($isi, " {$x} ")));
            $fld = array_map(fn ($v) => trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper((string) $v))), [$a['SUBDISTRICT_NAME'] ?? '', $a['DISTRICT_NAME'] ?? '', $a['CITY_NAME'] ?? '']);
            $s = $cocokBagian * 1000 + $cocokKata * 100;

            foreach ($kataBagian as $k) {
                $kk = implode(' ', $k);
                $s += ($fld[1] === $kk ? 60 : 0) + ($fld[2] === $kk ? 150 + (isset($kotaDisebut[$kk]) ? 100 : 0) : 0) + ($fld[0] === $kk ? 40 : 0);
            }

            return $s + (! $sebutProvinsi && preg_match('/JAWA TIMUR/i', (string) ($a['PROVINCE_NAME'] ?? '')) ? 80 : 0);
        };

        // every part matches and a district or city is named exactly: no need to search on
        $kuat = function (?array $a) use ($kataBagian): bool {
            if ($a === null) {
                return false;
            }

            $isi = $this->isi($a);

            foreach ($kataBagian as $k) {
                if (! $this->semuaAda($k, $isi)) {
                    return false;
                }
            }

            $kec = trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper((string) ($a['DISTRICT_NAME'] ?? ''))));
            $kota = trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper((string) ($a['CITY_NAME'] ?? ''))));

            foreach ($kataBagian as $k) {
                $kk = implode(' ', $k);

                if ($kk === $kota || (count($kataBagian) > 1 && $kk === $kec)) {
                    return true;
                }
            }

            return false;
        };

        $kunci = $sebutProvinsi ? [$gabung] : ["{$gabung} JAWA TIMUR", $gabung];

        if (count($kataBagian) > 1) {
            foreach ($kataBagian as $k) {
                $kunci[] = implode(' ', $k);
            }
        }

        $terbaik = null;
        $skorTerbaik = -1;

        foreach (array_unique($kunci) as $k) {
            foreach ($this->cariDaftar($k) as $a) {
                $s = $skor($a);

                if ($s > $skorTerbaik) {
                    $skorTerbaik = $s;
                    $terbaik = $a;
                }
            }

            if ($kuat($terbaik)) {
                break;
            }
        }

        // a two-level address (district, city) matching only one part outside East Java is most likely another region of the same name
        if ($terbaik !== null && count($kataBagian) > 1 && intdiv($skorTerbaik, 1000) < 2 && ! preg_match('/JAWA TIMUR/i', (string) ($terbaik['PROVINCE_NAME'] ?? ''))) {
            return null;
        }

        return $terbaik;
    }

    /**
     * "Dinoyo, Lowokwaru, Malang, Jawa Timur": the parts of a destination that are named.
     *
     * @param  array<string, mixed>  $a
     */
    public function labelAlamat(array $a): string
    {
        $bagian = [];

        foreach (['name', 'label', 'village', 'kelurahan', 'subdistrict', 'subDistrict', 'district', 'kecamatan', 'city', 'kota', 'regency', 'province', 'provinsi', 'SUBDISTRICT_NAME', 'DISTRICT_NAME', 'CITY_NAME', 'PROVINCE_NAME'] as $k) {
            $v = $a[$k] ?? null;

            if (is_string($v) && trim($v) !== '' && ! in_array(trim($v), $bagian, true)) {
                $bagian[] = trim($v);
            }
        }

        return implode(', ', array_slice($bagian, 0, 4));
    }

    /**
     * The rates of every courier, from one place to another, as Mengantar names them.
     *
     * @return list<array{nama: string, info: array<string, mixed>}>
     */
    public function estimasi(string $asalId, string $tujuanId, float $beratKg): array
    {
        $hasil = $this->panggil('GET', '/order/estimate', [
            'origin_id' => $asalId, 'destination_id' => $tujuanId, 'courier' => 'all', 'weight' => $beratKg,
        ]);
        $data = is_array($hasil['data'] ?? null) ? $hasil['data'] : [];

        return array_map(fn ($nama, $info) => ['nama' => (string) $nama, 'info' => (array) $info], array_keys($data), $data);
    }

    /**
     * The API key must stay out of any text shown to the admin.
     */
    private function sembunyi(string $teks): string
    {
        $kunci = $this->kunci();

        return mb_substr($kunci !== '' ? str_replace($kunci, '***', $teks) : $teks, 0, 400);
    }

    private function kunci(): string
    {
        return $this->kunci ??= trim((string) PengaturanToko::nilai('MENGANTAR_API_KEY'));
    }

    private function bersihkanNama(string $s): string
    {
        return trim((string) preg_replace('/^\s*(kota|kabupaten|kab\.?|kecamatan|kec\.?|kelurahan|kel\.?|desa)\s+/i', '', $s));
    }

    /**
     * The words of a name that can tell places apart: upper case, three letters or more,
     * not a number and not "kota", "kec" and the like.
     *
     * @return list<string>
     */
    private function kata(string $s): array
    {
        $kata = explode(' ', trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s))));

        return array_values(array_filter($kata, fn ($x) => strlen($x) >= 3 && ! ctype_digit($x) && ! preg_match(self::FILLER, $x)));
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function isi(array $a): string
    {
        $teks = implode(' ', array_map(fn ($k) => (string) ($a[$k] ?? ''), ['SUBDISTRICT_NAME', 'DISTRICT_NAME', 'CITY_NAME', 'PROVINCE_NAME']));

        return ' '.preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($teks)).' ';
    }

    /**
     * @param  list<string>  $kata
     */
    private function semuaAda(array $kata, string $isi): bool
    {
        foreach ($kata as $x) {
            if (! str_contains($isi, " {$x} ")) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function cocokKata(array $a, string $kw): bool
    {
        $isi = $this->isi($a);

        // an unknown shape of hit is not filtered
        if (trim($isi) === '') {
            return true;
        }

        $kata = $this->kata($kw);

        return $kata === [] || $this->semuaAda($kata, $isi);
    }

    /**
     * The first sort of a search: where the keyword sits in the hit's names.
     *
     * @param  array<string, mixed>  $a
     */
    private function skorKasar(array $a, string $kw): int
    {
        if ($kw === '') {
            return 0;
        }

        $sub = strtoupper((string) ($a['SUBDISTRICT_NAME'] ?? ''));
        $dis = strtoupper((string) ($a['DISTRICT_NAME'] ?? ''));
        $kota = strtoupper((string) ($a['CITY_NAME'] ?? ''));
        $s = ($dis === $kw ? 100 : 0) + ($sub === $kw ? 90 : 0) + ($kota === $kw ? 80 : 0);
        $s += (str_contains($dis, $kw) ? 40 : 0) + (str_contains($sub, $kw) ? 30 : 0) + (str_contains($kota, $kw) ? 20 : 0);

        return $s + (str_contains(strtoupper((string) ($a['PROVINCE_NAME'] ?? '')), 'JAWA TIMUR') ? 5 : 0);
    }
}
