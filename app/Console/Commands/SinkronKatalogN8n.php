<?php

namespace App\Console\Commands;

use App\Models\GambarProduk;
use App\Models\Produk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('n8n:sinkron-katalog')]
#[Description('Sinkronkan data produk dan gambar produk dari Data Tables n8n')]
class SinkronKatalogN8n extends Command
{
    public const N8N_TABEL_PRODUK = 'o9sagCqjzNsgFNiW';

    public const N8N_TABEL_GAMBAR = '3dqmjCWWcparIe9d';

    public function handle(): int
    {
        $url = rtrim((string) config('services.n8n.api_url'), '/');
        $kunci = (string) config('services.n8n.api_key');

        if ($url === '' || $kunci === '') {
            $this->error('N8N_API_URL dan N8N_API_KEY belum dikonfigurasi.');

            return self::FAILURE;
        }

        $this->info('Mengambil data dari n8n Data Tables...');

        $api = Http::baseUrl($url.'/api/v1')
            ->withHeaders(['X-N8N-API-KEY' => $kunci])
            ->timeout(30)
            ->acceptJson();

        $produkRows = $api->get('data-tables/'.self::N8N_TABEL_PRODUK.'/rows', ['limit' => 250])->throw()->json('data') ?? [];
        $gambarRows = $api->get('data-tables/'.self::N8N_TABEL_GAMBAR.'/rows', ['limit' => 250])->throw()->json('data') ?? [];

        $this->info('Ditemukan: '.count($produkRows).' produk dan '.count($gambarRows).' gambar dari n8n.');

        DB::transaction(function () use ($produkRows, $gambarRows) {
            $idProdukN8n = [];
            $produkMap = [];

            foreach ($produkRows as $row) {
                $pId = (int) $row['id'];
                $idProdukN8n[] = $pId;

                $hargaBertingkat = self::parseHarga(
                    $row['informasi_harga'] ?? '',
                    $row['kategori_kemasan'] ?? ''
                );

                $targetPasar = self::tentukanTargetPasar(
                    $row['deskripsi'] ?? '',
                    $row['jenis_produk'] ?? ''
                );

                $data = [
                    'jenis_produk' => trim((string) ($row['jenis_produk'] ?? '')),
                    'kategori_produk' => trim((string) ($row['kategori_produk'] ?? '')) ?: null,
                    'brand' => trim((string) ($row['brand'] ?? '')) ?: null,
                    'kategori_kemasan' => trim((string) ($row['kategori_kemasan'] ?? '')) ?: null,
                    'gramasi' => trim((string) ($row['gramasi'] ?? '')) ?: null,
                    'deskripsi' => trim((string) ($row['deskripsi'] ?? '')) ?: null,
                    'rasa' => trim((string) ($row['rasa'] ?? '')) ?: null,
                    'informasi_harga' => trim((string) ($row['informasi_harga'] ?? '')) ?: null,
                    'harga_bertingkat' => $hargaBertingkat,
                    'target_pasar' => $targetPasar,
                    'diarsip_at' => null,
                ];

                $p = Produk::query()->find($pId);
                if ($p) {
                    $p->update($data);
                } else {
                    $p = new Produk($data);
                    $p->id = $pId;
                    $p->save();
                }

                $produkMap[$pId] = $p;
            }

            // Hapus/arsipkan produk lama yang tidak ada di daftar n8n
            Produk::query()->whereNotIn('id', $idProdukN8n)->delete();

            // Simpan gambar_produk dan relasikan ke produk (produk_id)
            $idGambarN8n = [];
            foreach ($gambarRows as $row) {
                $gId = (int) $row['id'];
                $idGambarN8n[] = $gId;

                $produkId = self::cocokkanGambarKeProduk($row, $produkMap);

                $dataGambar = [
                    'produk_id' => $produkId,
                    'title' => trim((string) ($row['title'] ?? '')),
                    'description_for_ai' => trim((string) ($row['description_for_ai'] ?? '')) ?: null,
                    'tags' => trim((string) ($row['tags'] ?? '')) ?: null,
                    'filename' => trim((string) ($row['filename'] ?? '')) ?: null,
                    'image_url' => trim((string) ($row['image_url'] ?? '')) ?: null,
                    'gdrive_file_id' => trim((string) ($row['gdrive_file_id'] ?? '')) ?: null,
                    'mime_type' => trim((string) ($row['mime_type'] ?? '')) ?: null,
                    'aktif' => true,
                ];

                $g = GambarProduk::query()->find($gId);
                if ($g) {
                    $g->update($dataGambar);
                } else {
                    $g = new GambarProduk($dataGambar);
                    $g->id = $gId;
                    $g->save();
                }
            }

            // Hapus gambar lama yang tidak ada di daftar n8n
            GambarProduk::query()->whereNotIn('id', $idGambarN8n)->delete();
        });

        // Set PostgreSQL sequence ID agar auto-increment tetap sinkron jika menambah produk/gambar lewat panel
        $maxProdukId = (int) Produk::query()->max('id');
        if ($maxProdukId > 0 && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('produk', 'id'), {$maxProdukId})");
        }

        $maxGambarId = (int) GambarProduk::query()->max('id');
        if ($maxGambarId > 0 && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('gambar_produk', 'id'), {$maxGambarId})");
        }

        $this->info('Sinkronisasi katalog berhasil.');
        $this->line('Total produk aktif: '.Produk::query()->count());
        $this->line('Total gambar produk: '.GambarProduk::query()->count());
        $this->line('Gambar dengan relasi produk: '.GambarProduk::query()->whereNotNull('produk_id')->count());

        return self::SUCCESS;
    }

    /**
     * @return array{satuan: string, tier: list<array{min: int, max: int|null, harga: int}>}|null
     */
    public static function parseHarga(string $informasiHarga, string $kemasan = ''): ?array
    {
        if (trim($informasiHarga) === '') {
            return null;
        }

        $out = [];
        $satuan = 'pcs';

        if (stripos($kemasan, 'pack') !== false) {
            $satuan = 'pack';
        } elseif (stripos($kemasan, 'botol') !== false) {
            $satuan = 'botol';
        }

        foreach (preg_split('/\n|\//', $informasiHarga) ?: [] as $line) {
            $line = trim($line);
            if (! preg_match('/rp\s*([\d.]+)/i', $line, $h)) {
                continue;
            }

            $harga = (int) preg_replace('/\D/', '', $h[1]);

            if (preg_match('/(\d+)\s*-\s*(\d+)\s*([a-z]+)?\s*:/i', $line, $m)) {
                if (! empty($m[3])) {
                    $satuan = strtolower($m[3]);
                }
                $out[] = ['min' => (int) $m[1], 'max' => (int) $m[2], 'harga' => $harga];
            } elseif (preg_match('/(?:≥|>=|>)\s*(\d+)\s*([a-z]+)?/u', $line, $m)) {
                if (! empty($m[2])) {
                    $satuan = strtolower($m[2]);
                }
                $out[] = ['min' => (int) $m[1], 'max' => null, 'harga' => $harga];
            } elseif (preg_match('/(\d+)\s*([a-z]+)?\s*:/i', $line, $m)) {
                if (! empty($m[2])) {
                    $satuan = strtolower($m[2]);
                }
                $out[] = ['min' => (int) $m[1], 'max' => (int) $m[1], 'harga' => $harga];
            } else {
                $out[] = ['min' => 1, 'max' => null, 'harga' => $harga];
            }
        }

        if ($out === []) {
            return null;
        }

        return [
            'satuan' => $satuan,
            'tier' => $out,
        ];
    }

    public static function tentukanTargetPasar(string $deskripsi, string $jenisProduk): string
    {
        if (stripos($deskripsi, 'Target Grosir') !== false && stripos($deskripsi, 'Target Reseller') !== false) {
            return 'Grosir, Reseller';
        }
        if (stripos($deskripsi, 'Target Grosir') !== false) {
            return 'Grosir';
        }
        if (stripos($deskripsi, 'Target Reseller') !== false) {
            return 'Reseller';
        }
        if (stripos($deskripsi, 'konsumsi pribadi') !== false) {
            return 'Konsumen akhir, oleh-oleh';
        }
        if (stripos($deskripsi, 'kantin') !== false || stripos($deskripsi, 'warung') !== false) {
            return 'Warung, kantin';
        }
        if (stripos($deskripsi, 'serba 4000') !== false || stripos($deskripsi, 'serba 4k') !== false || stripos($jenisProduk, 'serba 4k') !== false) {
            return 'Warung, eceran serba 4K';
        }

        return 'Reseller, End User';
    }

    /**
     * @param  array<string, mixed>  $rowGambar
     * @param  array<int, Produk>  $produkMap
     */
    public static function cocokkanGambarKeProduk(array $rowGambar, array $produkMap): ?int
    {
        $id = (int) ($rowGambar['id'] ?? 0);
        $t = strtolower((string) ($rowGambar['title'] ?? ''));
        $f = strtolower((string) ($rowGambar['filename'] ?? ''));
        $tags = strtolower((string) ($rowGambar['tags'] ?? ''));
        $desc = strtolower((string) ($rowGambar['description_for_ai'] ?? ''));

        // Katalog umum / marketing banner (tidak terikat ke satu varian produk)
        if (in_array($id, [1, 4, 7, 8, 9], true)) {
            return null;
        }

        // Serba 4K (Produk 38-56)
        if (str_contains($t, 'serba 4k')) {
            foreach ($produkMap as $pId => $p) {
                if (str_contains(strtolower($p->jenis_produk), 'serba 4k')) {
                    $var = trim(str_replace(' - serba 4k', '', strtolower($p->jenis_produk)));
                    $tVar = trim(str_replace(' - serba 4k', '', $t));
                    if ($var === $tVar || str_contains($tVar, $var) || str_contains($var, $tVar)) {
                        return $pId;
                    }
                }
            }
        }

        // Basreng Stik 1,8 kg (ID 1)
        if (str_contains($t, 'basreng stik') && (str_contains($t, '1,8 kg') || (str_contains($f, 'basreng stik') && str_contains($f, 'loss')))) {
            return 1;
        }
        // Basreng Koin 1,8 kg (ID 2)
        if (str_contains($t, 'basreng koin') && (str_contains($t, '1,8 kg') || (str_contains($f, 'basreng koin') && str_contains($f, 'loss')))) {
            return 2;
        }
        // Molreng Pedas 1,8 kg (ID 3)
        if (str_contains($t, 'molreng') && (str_contains($t, 'pedas') || str_contains($t, 'cabe')) && (str_contains($t, '1,8 kg') || (str_contains($f, 'molreng') && str_contains($f, 'loss')))) {
            return 3;
        }
        // Molreng Original 1,8 kg (ID 4)
        if (str_contains($t, 'molreng') && str_contains($t, 'original') && (str_contains($t, '1,8 kg') || (str_contains($f, 'molreng') && str_contains($f, 'loss')))) {
            return 4;
        }
        // Jengkol Sisir 1,5 kg (ID 5)
        if (str_contains($t, 'jengkol sisir') && (str_contains($t, '1,5 kg') || str_contains($f, 'loss'))) {
            return 5;
        }
        // Makaroni Bantat 1,8 kg (ID 6)
        if (str_contains($t, 'makaroni bantat') && (str_contains($t, '1,8 kg') || str_contains($f, 'loss'))) {
            return 6;
        }
        // Kripca 1,8 kg (ID 7)
        if ((str_contains($t, 'kripca') || str_contains($t, 'keripik kaca')) && (str_contains($t, '1,8 kg') || str_contains($f, 'loss'))) {
            return 7;
        }
        // Seblak Mix 1,8 kg (ID 8)
        if (str_contains($t, 'seblak mix') && (str_contains($t, '1,8 kg') || str_contains($f, 'loss'))) {
            return 8;
        }

        // 500g
        if (str_contains($t, 'basreng stik') && str_contains($t, '500 gram')) {
            return 9;
        }
        if (str_contains($t, 'basreng koin') && str_contains($t, '500 gram')) {
            return 10;
        }
        if (str_contains($t, 'molreng') && str_contains($t, '500 gram')) {
            return 11;
        }
        if (str_contains($t, 'kentang balado') && str_contains($t, '500 gram')) {
            return 26;
        }

        // 1 kg
        if (str_contains($t, 'basreng stik') && (str_contains($t, '1 kg') || str_contains($t, '1 kilogram'))) {
            return 12;
        }
        if (str_contains($t, 'basreng koin') && (str_contains($t, '1 kg') || str_contains($t, '1 kilogram'))) {
            return 13;
        }
        if (str_contains($t, 'kentang balado') && (str_contains($t, '1 kg') || str_contains($t, '1 kilogram'))) {
            return 14;
        }

        // 250g
        if (str_contains($t, 'basreng stik') && str_contains($t, '250 gram')) {
            return 15;
        }
        if (str_contains($t, 'basreng koin') && str_contains($t, '250 gram')) {
            return 16;
        }
        if (str_contains($t, 'molreng') && str_contains($t, '250 gram')) {
            return 17;
        }
        if (str_contains($t, 'seblak jengkol') && str_contains($t, '250 gram')) {
            return 18;
        }
        if (str_contains($t, 'seblak mix') && str_contains($t, '250 gram')) {
            return 19;
        }
        if (str_contains($t, 'usus') && (str_contains($t, '250gr') || str_contains($t, '250 gram'))) {
            if (str_contains($t, 'pedas') || str_contains($t, 'cikruh')) {
                return 57;
            }

            return 59;
        }

        // Pack isi 25 & isi 20
        if (str_contains($t, 'basreng stik') && (str_contains($t, 'isi 25') || (str_contains($t, 'pack') && str_contains($tags, 'basreng-pack')))) {
            return 20;
        }
        if (str_contains($t, 'basreng koin') && (str_contains($t, 'isi 25') || (str_contains($t, 'pack') && str_contains($tags, 'basreng-pack')))) {
            return 21;
        }
        if (str_contains($t, 'seblak jengkol') && str_contains($t, 'isi 20')) {
            return 22;
        }
        if (str_contains($t, 'seblak mix') && str_contains($t, 'isi 20')) {
            return 23;
        }

        // Kentang Botol & Mini
        if (str_contains($t, 'kentang balado') && (str_contains($t, 'botol') || str_contains($t, '65 gram'))) {
            return 24;
        }
        if (str_contains($t, 'kentang balado') && (str_contains($t, 'mini') || str_contains($t, '25 gram'))) {
            return 25;
        }

        // Ngemil'o isi 10
        if (str_contains($t, "ngemil'o") || str_contains($tags, 'ngemilo') || str_contains($desc, "ngemil'o")) {
            if (str_contains($t, 'koin')) {
                return 27;
            }
            if (str_contains($t, 'stik')) {
                return 28;
            }
            if (str_contains($t, 'usus')) {
                return 29;
            }
            if (str_contains($t, 'kaca') || str_contains($t, 'kripca')) {
                return 30;
            }
            if (str_contains($t, 'makaroni')) {
                return 31;
            }
            if (str_contains($t, 'jengkol')) {
                return 32;
            }
            if (str_contains($t, 'seblak mix')) {
                return 33;
            }
        }

        // Molreng reguler (ID 34)
        if (str_contains($t, 'molreng reguler')) {
            return 34;
        }

        // Molreng premium (ID 35)
        if (str_contains($t, 'molreng premium')) {
            return 35;
        }

        // Basreng premium (ID 36)
        if (str_contains($t, 'basreng') && (str_contains($t, 'premium') || str_contains($f, 'premium'))) {
            return 36;
        }

        // Kerupuk rujak (ID 37)
        if (str_contains($t, 'kerupuk rujak')) {
            return 37;
        }

        // Usus Loss
        if (str_contains($t, 'usus') && (str_contains($t, 'loss') || str_contains($f, 'loss'))) {
            if (str_contains($t, 'pedas') || str_contains($t, 'cikruh')) {
                return 58;
            }

            return 60;
        }

        return null;
    }
}
