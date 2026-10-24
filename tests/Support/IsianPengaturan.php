<?php

namespace Tests\Support;

use App\Models\ConfigToko;
use App\Models\Workflow;

/**
 * Settings.dc.html as stored, and the form as the page sends it back.
 */
final class IsianPengaturan
{
    /**
     * CONFIG_TOKO rows and the chatbot workflow as in the design.
     */
    public static function simpanAwal(): void
    {
        $tersimpan = collect(self::form())
            ->except('ai')
            ->map(fn (string|array $nilai) => is_array($nilai) ? implode(',', $nilai) : $nilai)
            // empty fields have no row, as in the demo data
            ->filter(fn (string $nilai) => $nilai !== '')
            ->all();

        foreach ([
            ...$tersimpan,
            'DAFTAR_NOMOR_BOT' => 'bangbana-waha=Nomor Utama,bangbana-2=Nomor Lawang',
            'INVOICE_TERAKHIR' => 'INV/2026/09/141',
        ] as $kunci => $nilai) {
            ConfigToko::create(['kunci' => $kunci, 'nilai' => $nilai]);
        }

        Workflow::create([
            'kode' => 'chatbot',
            'nama' => 'chatbot bangbana (main)',
            'pengaturan' => ['model_utama' => 'gemini-3.6-flash', 'model_cadangan' => 'gemini-flash-latest', 'temperature' => 0.4, 'ingatan_pesan' => 60, 'tunggu_gabung_detik' => 8],
        ]);
    }

    /**
     * Every field of the page, valid, with the given changes.
     *
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    public static function form(array $ubah = []): array
    {
        $form = [
            'ADMIN_WA' => '6281234560007',
            'SARAH_WA' => '6281298760646',
            'NAMA_TOKO' => 'Bang Bana — CV TKT Bana Food',
            'REKENING' => '',
            'BATAS_BAYAR_JAM' => '5',
            'BATAS_BAYAR_PICKUP_JAM' => '24',
            'BATAS_KONFIRMASI_JAM' => '24',
            'CATATAN_INVOICE' => '',
            'LINK_ULASAN' => '',
            'AMBIL_TOKO_SAWOJAJAR' => '',
            'AMBIL_PABRIK_LAWANG' => '',
            'JAM_PESAN_OTOMATIS' => '07:00-21:00',
            'HARI_LIBUR_OTOMATIS' => ['Minggu'],
            'JEDA_FOLLOWUP_JAM' => '10',
            'JEDA_KESAN_HARI' => '2',
            'PACKING_PER_KG' => '2000',
            'PACKING_MINIMAL' => '5000',
            'PACKING_GRATIS_DI_ATAS' => '',
            'SESI_UTAMA' => 'bangbana-waha',
            'ARSIP_ORDER_HARI' => '90',
            'ARSIP_CUSTOMER_HARI' => '365',
            'ai' => [
                'AI_MODEL_UTAMA' => 'gemini-3.6-flash',
                'AI_MODEL_CADANGAN' => 'gemini-flash-latest',
                'AI_TEMPERATURE' => '0.4',
                'AI_INGATAN_CHAT' => '60',
                'AI_TUNGGU_GABUNG' => '8',
            ],
        ];

        // lists are replaced whole (an empty list must stay empty); ai is merged per field
        return [...$form, ...$ubah, 'ai' => [...$form['ai'], ...($ubah['ai'] ?? [])]];
    }
}
