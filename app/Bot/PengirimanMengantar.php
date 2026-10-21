<?php

namespace App\Bot;

use App\Models\LogOrder;
use App\Support\PengaturanToko;
use App\Support\Rupiah;
use Carbon\CarbonInterface;

/**
 * An order's shipment at Mengantar, and the rates: what n8n's "Buat Order Mengantar" and
 * "Cek Ongkir Panel" did. The Mengantar balance pays the courier; when it is short the
 * order is booked "belum dibayar" and the supervisor is told.
 *
 * CONFIG_TOKO MENGANTAR_BUAT_ORDER: OTOMATIS (default), UJI (only a preview, nothing booked)
 * or MATI.
 */
class PengirimanMengantar
{
    /** What a customer's "kurir_pilihan" is called at Mengantar. */
    private const PETA = [
        'jne' => 'JNE', 'sicepat' => 'SiCepat', 'sap' => 'Sap', 'idexpress' => 'iDexpress', 'jt' => 'JT', 'j&t' => 'JT',
        'jnt' => 'JT', 'jtexpress' => 'JT', 'lion' => 'lion', 'lionparcel' => 'lion', 'anteraja' => 'anteraja',
        'pos' => 'pos', 'posindonesia' => 'pos', 'spx' => 'spx', 'shopeeexpress' => 'spx',
    ];

    private const NAMA_KURIR = [
        'JNE' => 'JNE', 'SiCepat' => 'SiCepat', 'Sap' => 'SAP', 'iDexpress' => 'iDexpress', 'JT' => 'J&T',
        'lion' => 'Lion Parcel', 'anteraja' => 'Anteraja', 'pos' => 'POS Indonesia', 'spx' => 'SPX',
    ];

    private const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    public function __construct(private MengantarApi $api) {}

    /**
     * The mode in CONFIG_TOKO.
     */
    public static function mode(): string
    {
        return strtoupper(trim((string) (PengaturanToko::nilai('MENGANTAR_BUAT_ORDER') ?: 'OTOMATIS')));
    }

    /**
     * Rates for one destination, cheapest first.
     *
     * @return list<array{kurir: string, layanan: string, harga: int, estimasi: string|null}>
     *
     * @throws PerintahGagal
     */
    public function tarif(string $tujuanMentah, float $beratKg): array
    {
        $tujuanMentah = trim($tujuanMentah);
        $asalId = trim((string) PengaturanToko::nilai('MENGANTAR_PICKUP_ADDRESS_ID'));
        $asalMentah = (string) PengaturanToko::nilai('MENGANTAR_KOTA_ASAL');

        if (! $this->api->terisi() || ($asalId === '' && $asalMentah === '')) {
            throw new PerintahGagal('API key atau alamat asal Mengantar belum diisi');
        }

        try {
            $tujuan = $this->api->cariTujuan($tujuanMentah);

            if ($tujuan === null) {
                throw new PerintahGagal("Daerah \"{$tujuanMentah}\" tidak ditemukan di sistem ongkir.");
            }

            $daftar = [];

            if ($asalId !== '') {
                try {
                    $daftar = $this->terpakai($this->api->estimasi($asalId, (string) $tujuan['_id'], $beratKg));
                } catch (MengantarGagal) {
                    $daftar = [];
                }
            }

            if ($daftar === []) {
                $asal = $this->api->cariAlamat($this->bersihkanNama($asalMentah)) ?? $this->api->cariAlamat($asalMentah);

                if ($asal === null) {
                    throw new PerintahGagal("Alamat asal \"{$asalMentah}\" tidak ditemukan di sistem ongkir.");
                }

                $daftar = $this->terpakai($this->api->estimasi((string) $asal['_id'], (string) $tujuan['_id'], $beratKg));
            }
        } catch (MengantarGagal $e) {
            throw new PerintahGagal(preg_match('/kode (401|403)|unauthori|forbidden/i', $e->getMessage())
                ? 'API key Mengantar ditolak'
                : 'Mengantar tidak menjawab: '.mb_substr($e->getMessage(), 0, 120));
        }

        $dikecualikan = $this->kurirDikecualikan();
        $tarif = collect($daftar)
            ->map(function (array $x) {
                $normal = (float) ($x['info']['estimatedPrice'] ?? $x['info']['price'] ?? 0);
                $spesial = (float) ($x['info']['estimatedSpecialPrice'] ?? 0);

                return [
                    'kurir' => $x['nama'],
                    'layanan' => '',
                    'harga' => (int) ($spesial > 0 ? $spesial : $normal),
                    'estimasi' => filled($e = $x['info']['estimatedDate'] ?? $x['info']['estimate_delivery'] ?? null) ? (string) $e : null,
                ];
            })
            // a courier whose price from Mengantar is not believable (SAPLite is always Rp9.975) is hidden;
            // from 10 kg, under Rp500 a kilo cannot be the total
            ->filter(fn ($t) => $t['harga'] > 0
                && ! in_array(mb_strtolower(trim($t['kurir'])), $dikecualikan, true)
                && ! ($beratKg >= 10 && $t['harga'] / $beratKg < 500))
            ->sortBy('harga')
            ->all();

        if ($tarif === []) {
            throw new PerintahGagal('Tidak ada kurir yang tersedia untuk rute ini via Mengantar.');
        }

        return array_values($tarif);
    }

    /**
     * Book the shipment: courier, pickup time and the order itself.
     */
    public function buat(LogOrder $order, ?string $mode = null): HasilPengiriman
    {
        $order->loadMissing('items');
        $mode ??= self::mode();
        $status = $order->status_order->value;
        $label = $order->order_id.($order->invoice_number ? " / {$order->invoice_number}" : '');
        $nama = trim($order->nama_penerima ?: 'Kak');
        $catatan = (string) $order->catatan_admin;
        $wib = now()->timezone('Asia/Jakarta');
        $stamp = $wib->format('Y-m-d H:i');

        $gagal = fn (string $alasan) => new HasilPengiriman(
            ok: false, simpanCatatan: false, statusBaru: $status, alasan: $this->bersih($alasan),
            pesanAdmin: "⚠️ *Order pengiriman BELUM dibuat* untuk {$label} (".($order->kurir_pilihan ?: '-').").\nAlasan: ".$this->bersih($alasan)
                ."\n\nSilakan buat manual di aplikasi Mengantar, lalu ketik *KIRIM {$order->order_id} nomor-resi* supaya customer dikabari.\nKalau masalahnya sudah beres (misalnya saldo sudah diisi), ketik *KIRIM {$order->order_id}* tanpa resi untuk mencoba lagi.",
        );

        if (trim((string) $order->no_resi) !== '') {
            return $gagal("order ini sudah punya resi {$order->no_resi}");
        }

        if (($terakhir = $this->orderMengantarTerakhir($catatan)) !== null && ! str_contains($catatan, "BATAL KIRIM {$terakhir['id']}")) {
            return $gagal('order pengiriman untuk pesanan ini sudah pernah dibuat di Mengantar (lihat catatan order). Cek di aplikasi Mengantar supaya tidak dobel.');
        }

        if (! $this->api->terisi()) {
            return $gagal('MENGANTAR_API_KEY di CONFIG_TOKO masih kosong');
        }

        [$kurir, $cargo] = $this->kurir($order);

        if ($kurir === null) {
            return $gagal('kurir "'.($order->kurir_pilihan ?: '-').'" belum bisa dibuatkan order otomatis lewat Mengantar');
        }

        $kurirLabel = self::NAMA_KURIR[$kurir].($cargo ? ' Cargo' : '');
        $hp = $this->nomorHp($order);

        if (strlen($hp) < 10 || strlen($hp) > 15) {
            return $gagal('nomor HP penerima tidak valid ('.($order->no_hp ?: ($order->nomor_wa ?: '-')).')');
        }

        if (trim((string) $order->alamat_lengkap) === '') {
            return $gagal('alamat lengkap penerima kosong');
        }

        $berat = max(0.1, round(((float) str_replace(',', '.', (string) $order->berat_kg) ?: 1.0) * 10) / 10);
        $jumlah = (int) $order->items->sum('qty') ?: 1;
        $nilai = (int) preg_replace('/\D/', '', (string) $order->total_produk) ?: (int) $order->items->sum(fn ($i) => $i->qty * $i->harga_satuan);
        $isiPaket = $order->items->map(fn ($i) => "{$i->nama_produk} ({$i->qty})")->implode(', ');

        try {
            // 0) the pickup address kept in the Mengantar account
            $jemputId = trim((string) PengaturanToko::nilai('MENGANTAR_ALAMAT_JEMPUT_ID'));
            $jemputNama = '';

            if ($jemputId === '') {
                $alamat = $this->api->panggil('GET', '/address');
                $daftar = array_is_list($alamat) ? $alamat : (array) ($alamat['data'] ?? []);
                $asal = strtoupper((string) PengaturanToko::nilai('MENGANTAR_KOTA_ASAL'));
                $pilih = collect($daftar)->first(function ($a) use ($asal) {
                    $dis = strtoupper(trim((string) ($a['PICKUP_DISTRICT'] ?? '')));

                    return $dis !== '' && str_contains($asal, $dis);
                }) ?? ($daftar[0] ?? null);

                if (is_array($pilih) && filled($pilih['_id'] ?? null)) {
                    $jemputId = (string) $pilih['_id'];
                    $jemputNama = trim((string) ($pilih['PICKUP_NAME'] ?? ''));
                }
            }

            if ($jemputId === '') {
                return $gagal('alamat jemput belum tersimpan di akun Mengantar (menu Alamat)');
            }

            // 1) the destination, found as the rate check does (the kelurahan first, so it is exact)
            $kunciTujuan = collect([$order->kecamatan, $order->kota])->map(fn ($x) => trim((string) $x))->filter()->implode(', ');
            $tujuan = filled($order->kelurahan) && $kunciTujuan !== ''
                ? $this->api->cariTujuan(trim((string) $order->kelurahan).', '.$kunciTujuan)
                : null;
            $tujuan ??= $kunciTujuan !== '' ? $this->api->cariTujuan($kunciTujuan) : null;

            if ($tujuan === null || blank($tujuan['_id'] ?? null)) {
                return $gagal('daerah tujuan "'.($kunciTujuan ?: '-').'" tidak ditemukan di Mengantar');
            }

            $tujuanLabel = collect([$tujuan['SUBDISTRICT_NAME'] ?? null, $tujuan['DISTRICT_NAME'] ?? null, $tujuan['CITY_NAME'] ?? null])->filter()->implode(', ');

            // 2) pickup: the hour in MENGANTAR_JAM_PICKUP, at least 100 minutes from now, never a Sunday
            preg_match('/(\d{1,2})/', (string) (PengaturanToko::nilai('MENGANTAR_JAM_PICKUP') ?: '13:00'), $m);
            $jam = min(18, max(9, (int) ($m[1] ?? 13) ?: 13));
            $jemput = $wib->copy()->setTime($jam, 0, 0);

            while ($jemput->getTimestamp() - $wib->getTimestamp() < 100 * 60 || $jemput->dayOfWeek === 0) {
                $jemput = $jemput->addDay();
            }

            $ymd = $jemput->format('Y-m-d');
            $jamTeks = "{$jam}:00";
            $teksJemput = $this->teksHari($jemput, $wib).sprintf(' sekitar jam %02d.00', $jam);
            $timeId = $this->cariJadwal($ymd, $jamTeks, $jemputId);

            $pesanan = [
                'customerName' => mb_substr($nama, 0, 60),
                'customerPhone' => $hp,
                'customerAddress' => mb_substr((string) preg_replace('/\s+/', ' ', (string) $order->alamat_lengkap), 0, 250),
                'customerAddressDataId' => (string) $tujuan['_id'],
                'parcelContent' => mb_substr('Makanan ringan: '.preg_replace('/\s+/', ' ', $isiPaket), 0, 120),
                'weight' => $berat,
                'quantity' => $jumlah,
                'goodsValue' => $nilai,
                'destinationMark' => mb_substr((string) ($order->invoice_number ?: $order->order_id), 0, 40),
                ...($cargo ? ['cargo' => true] : []),
            ];
            $volume = trim((string) PengaturanToko::nilai('MENGANTAR_VOLUME_PICKUP')) ?: 'volumeMotor';

            if ($mode === 'UJI') {
                return new HasilPengiriman(
                    ok: false, simpanCatatan: false, statusBaru: $status,
                    pesanAdmin: "🧪 *MODE UJI — order pengiriman TIDAK dibuat* untuk {$label}\nKurir: {$kurir}".($cargo ? ' (cargo)' : '')." | jemput {$ymd} {$jamTeks}"
                        .($timeId ? ' (jadwal sudah ada)' : ' (jadwal baru akan dibuat)')." | {$volume}\nDijemput di: ".($jemputNama ?: 'alamat tersimpan di Mengantar')
                        ."\nPenerima: {$pesanan['customerName']} — {$hp}\nAlamat: {$pesanan['customerAddress']}\nDaerah Mengantar: {$tujuanLabel}"
                        ."\nBerat {$berat} kg | {$jumlah} pcs | nilai barang ".Rupiah::teks($nilai)."\nIsi: {$pesanan['parcelContent']}"
                        ."\n\nKalau datanya sudah benar, ubah CONFIG_TOKO MENGANTAR_BUAT_ORDER menjadi OTOMATIS.",
                );
            }

            if ($timeId === '') {
                $buat = $this->api->panggil('POST', '/time', [], ['address_id' => $jemputId, 'date' => $jemput->format('m-d-Y'), 'time' => $jamTeks]);
                $timeId = (string) ($buat['data']['_id'] ?? '');
            }

            if ($timeId === '') {
                return $gagal("jadwal jemput {$ymd} {$jamTeks} tidak bisa dibuat di Mengantar");
            }

            // 3) the shipment
            $res = $this->api->panggil('POST', '/order', [], [
                'courier' => $kurir,
                'pickup' => ['type' => 'scheduledPickup', 'address_id' => $jemputId, 'time_id' => $timeId, 'volume' => $volume],
                'orders' => [$pesanan],
            ]);
        } catch (MengantarGagal $e) {
            return $gagal($e->getMessage());
        }

        $d0 = is_array($res['data'][0] ?? null) ? $res['data'][0] : null;

        if ($d0 === null) {
            $errs = is_array($res['errors'] ?? null) && $res['errors'] !== [] ? json_encode($res['errors']) : '';

            return $gagal('Mengantar menolak order'.($errs !== '' ? ": {$errs}" : (filled($res['message'] ?? null) ? ': '.$res['message'] : '')));
        }

        $idM = (string) ($d0['ORDER_ID'] ?? $d0['_id'] ?? '');
        $mongo = isset($d0['_id']) && (string) $d0['_id'] !== $idM ? ' #'.$d0['_id'] : '';
        $ongkirM = (int) ($d0['estimatedSpecialPrice'] ?? $d0['estimatedPrice'] ?? 0);
        $dasar = $catatan !== '' ? $catatan.' | ' : '';

        if (blank($d0['cnote_no'] ?? null)) {
            return new HasilPengiriman(
                ok: false, simpanCatatan: true, statusBaru: $status,
                alasan: 'order pengiriman sudah dibuat di Mengantar tapi belum dibayar (saldo Mengantar kurang?)',
                catatanAdmin: "{$dasar}MENGANTAR ORDER {$idM}{$mongo} BELUM DIBAYAR {$stamp}",
                pesanAdmin: "⚠️ *Order pengiriman sudah dibuat di Mengantar tapi BELUM DIBAYAR* — {$label}\nID Mengantar: {$idM}".(filled($d0['error'] ?? null) ? "\nKeterangan: ".$this->bersih((string) $d0['error']) : '')
                    ."\n\nKemungkinan *saldo Mengantar kurang*. Isi saldo lalu bayar order ini di aplikasi Mengantar (menu order belum dibayar). Setelah resinya keluar, ketik *KIRIM {$order->order_id} nomor-resi* supaya customer dikabari.",
            );
        }

        $resi = (string) $d0['cnote_no'];

        return new HasilPengiriman(
            ok: true, simpanCatatan: true, statusBaru: 'DIKIRIM', resi: $resi, kurirFinal: $kurirLabel,
            catatanAdmin: "{$dasar}DIKIRIM {$stamp} resi {$resi} (MENGANTAR ORDER {$idM}{$mongo}, {$kurirLabel}, jemput {$ymd} {$jamTeks})",
            pesanCustomer: 'Halo Kak '.explode(' ', $nama)[0]." 😊\n\nPesanan *{$label}* sudah kami siapkan untuk dikirim lewat *{$kurirLabel}* 📦\nNomor resi: *{$resi}*\n\nPaketnya dijemput kurir {$teksJemput}, jadi resinya bisa dilacak setelah paket dijemput ya kak. Kalau paketnya sudah sampai, kabari kami ya 🙏",
            pesanAdmin: "🚚 *Order pengiriman dibuat otomatis* — {$label}\nKurir: {$kurirLabel}\nResi: *{$resi}*\nDijemput: {$teksJemput} ({$ymd})".($jemputNama !== '' ? " di {$jemputNama}" : '')
                ."\nOngkir dipotong saldo Mengantar: ".Rupiah::teks($ongkirM).((int) $order->ongkir > 0 ? ' (ditagih ke customer '.Rupiah::teks((int) $order->ongkir).')' : '')
                ."\n\nStatus order sekarang DIKIRIM dan resinya sudah dikirim ke customer. Pastikan paket sudah dikemas sebelum kurir datang.\nSetelah barang diterima: *SELESAI {$order->order_id}*",
        );
    }

    /**
     * Cancel the shipment this bot booked; Mengantar only allows it before the courier picks the parcel up.
     */
    public function batal(LogOrder $order): HasilPengiriman
    {
        $status = $order->status_order->value;
        $label = $order->order_id.($order->invoice_number ? " / {$order->invoice_number}" : '');
        $catatan = (string) $order->catatan_admin;
        $terakhir = $this->orderMengantarTerakhir($catatan);
        $id = $terakhir['id'] ?? '';
        $mongo = $terakhir['mongo'] ?? '';

        $gagal = fn (string $alasan) => new HasilPengiriman(
            ok: false, simpanCatatan: false, statusBaru: $status, alasan: $this->bersih($alasan),
            pesanAdmin: '⚠️ Order pengiriman Mengantar '.($id !== '' ? "{$id} " : '')."untuk {$label} *BELUM bisa dibatalkan*.\nAlasan: ".$this->bersih($alasan)
                ."\n\nPembatalan hanya bisa sebelum paket dijemput kurir. Silakan cek atau batalkan langsung di aplikasi Mengantar.",
        );

        if ($id === '') {
            return $gagal('order ini tidak punya order pengiriman Mengantar yang dibuat bot');
        }

        if (str_contains($catatan, "BATAL KIRIM {$id}")) {
            return $gagal("order pengiriman {$id} sudah pernah dibatalkan");
        }

        if (! $this->api->terisi()) {
            return $gagal('MENGANTAR_API_KEY di CONFIG_TOKO masih kosong');
        }

        [$kurir] = $this->kurir($order);

        if ($kurir === null) {
            return $gagal('kurir "'.($order->kurir_pilihan ?: '-').'" tidak dikenali');
        }

        try {
            $res = $this->api->panggil('DELETE', '/order', [], $mongo !== '' ? ['courier' => $kurir, 'ids' => [$mongo]] : ['courier' => $kurir, 'orderIds' => [$id]]);
        } catch (MengantarGagal $e) {
            return $gagal($e->getMessage());
        }

        $jumlah = (int) ($res['deletedCount'] ?? $res['data']['deletedCount'] ?? 0);

        if ($jumlah <= 0) {
            return $gagal(filled($res['message'] ?? null) ? (string) $res['message'] : 'Mengantar tidak menghapus order ini (kemungkinan paket sudah dijemput atau sudah diproses kurir)');
        }

        return new HasilPengiriman(
            ok: true, simpanCatatan: true, statusBaru: 'LUNAS',
            catatanAdmin: ($catatan !== '' ? $catatan.' | ' : '')."BATAL KIRIM {$id} ".now()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            pesanAdmin: "✅ Order pengiriman Mengantar {$id} untuk {$label} sudah *DIBATALKAN*.\nStatus order kembali LUNAS dan resinya dihapus dari catatan."
                .(trim((string) $order->no_resi) !== '' ? "\n\nCustomer sudah pernah menerima resi {$order->no_resi} — mohon kabari customer bahwa pengirimannya dijadwalkan ulang." : '')
                ."\n\nUntuk membuat order pengiriman baru: *KIRIM {$order->order_id}* (tanpa resi).",
        );
    }

    /**
     * The last "MENGANTAR ORDER M123 #<mongo id>" in the order's note.
     *
     * @return array{id: string, mongo: string}|null
     */
    public function orderMengantarTerakhir(string $catatan): ?array
    {
        preg_match_all('/MENGANTAR ORDER ([A-Za-z0-9]+)(?: #([a-f0-9]{24}))?/', $catatan, $semua, PREG_SET_ORDER);

        if ($semua === []) {
            return null;
        }

        $akhir = end($semua);

        return ['id' => $akhir[1], 'mongo' => $akhir[2] ?? ''];
    }

    /**
     * The courier as Mengantar names it, and whether it is cargo; null when it is not one it books.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function kurir(LogOrder $order): array
    {
        $kl = (string) preg_replace('/[^a-z&]/', '', strtolower((string) $order->kurir_pilihan));
        $cargo = str_ends_with($kl, 'cargo');

        return [self::PETA[$cargo ? substr($kl, 0, -5) : $kl] ?? null, $cargo];
    }

    /**
     * The recipient's number as 08…: no_hp first, the chat's number when that is too short.
     */
    private function nomorHp(LogOrder $order): string
    {
        $hp = (string) preg_replace('/\D/', '', (string) $order->no_hp);

        if (strlen($hp) < 9) {
            $hp = (string) preg_replace('/\D/', '', (string) $order->nomor_wa);
        }

        return match (true) {
            str_starts_with($hp, '62') => '0'.substr($hp, 2),
            str_starts_with($hp, '8') => '0'.$hp,
            default => $hp,
        };
    }

    /**
     * The id of a pickup slot Mengantar already has for that day and hour at that address, or ''.
     */
    private function cariJadwal(string $ymd, string $jamTeks, string $jemputId): string
    {
        try {
            $hasil = $this->api->panggil('GET', '/time');
        } catch (MengantarGagal) {
            return '';
        }

        foreach ((array) ($hasil['data'] ?? []) as $s) {
            if (! is_array($s) || preg_replace('/^0/', '', (string) ($s['time'] ?? '')) !== $jamTeks) {
                continue;
            }

            $alamat = $s['address'] ?? null;
            $cocokAlamat = blank($alamat) || (string) (is_array($alamat) ? ($alamat['_id'] ?? '') : $alamat) === $jemputId;

            if ($cocokAlamat && in_array($ymd, $this->tanggalDari($s['date'] ?? null), true)) {
                return (string) ($s['_id'] ?? '');
            }
        }

        return '';
    }

    /**
     * The days a slot's date can mean: Mengantar sends UTC midnight, and a day in WIB is 7 hours on.
     *
     * @return list<string>
     */
    private function tanggalDari(mixed $nilai): array
    {
        $detik = is_int($nilai) || is_float($nilai) ? (int) ($nilai / 1000) : (strtotime((string) $nilai) ?: null);

        return $detik === null ? [] : [gmdate('Y-m-d', $detik), gmdate('Y-m-d', $detik + 7 * 3600)];
    }

    private function teksHari(CarbonInterface $jemput, CarbonInterface $sekarang): string
    {
        return match (true) {
            $jemput->isSameDay($sekarang) => 'hari ini',
            $jemput->isSameDay($sekarang->copy()->addDay()) => 'besok ('.self::HARI[$jemput->dayOfWeek].')',
            default => 'hari '.self::HARI[$jemput->dayOfWeek].' ('.$jemput->format('d-m').')',
        };
    }

    /**
     * @param  list<array{nama: string, info: array<string, mixed>}>  $daftar
     * @return list<array{nama: string, info: array<string, mixed>}>
     */
    private function terpakai(array $daftar): array
    {
        return array_values(array_filter($daftar, fn ($x) => $x['info'] !== [] && empty($x['info']['unsupported'])));
    }

    /**
     * Couriers kept out of the offer (KURIR_DIKECUALIKAN, comma separated). With no row at all
     * SAPLite stays out, as it always did.
     *
     * @return list<string>
     */
    private function kurirDikecualikan(): array
    {
        $nilai = PengaturanToko::nilai('KURIR_DIKECUALIKAN');

        return array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim($s)), explode(',', $nilai === null ? 'SAPLite' : $nilai))));
    }

    private function bersihkanNama(string $s): string
    {
        return trim((string) preg_replace('/^\s*(kota|kabupaten|kab\.?|kecamatan|kec\.?|kelurahan|kel\.?|desa)\s+/i', '', $s));
    }

    /**
     * An error text for a notice: no API key, no longer than 400 characters.
     */
    private function bersih(string $teks): string
    {
        return mb_substr($teks, 0, 400);
    }
}
