<?php

namespace App\Support;

use App\Bot\LapisanData;
use App\Enums\SkorProspek;
use App\Enums\TahapFunnel;
use App\Models\CrmCustomer;
use App\Models\LogOrder;

/**
 * What the bot writes to the CRM customer. It replaces n8n's `n8n_simpan_crm()`; this
 * first part is the closing of an order (SELESAI, TUTUP), the rest follows with the reply.
 */
class SimpanCrm
{
    /**
     * What Adiva decided about the customer after a message (n8n's "Simpan CRM"): the rating, the
     * running order draft, the open question to the supervisor, and when to follow up. It is written
     * through n8n_simpan_crm (docs/kontrak-n8n.md §8), which knows the bot's wording and stamps
     * tahap_sejak; only the keys given are touched.
     *
     * @param  array<string, mixed>  $rb  what RapikanBalasan made
     */
    public static function penilaian(array $rb, ?string $balasanLama): ?CrmCustomer
    {
        $chat = trim((string) ($rb['chatId'] ?? ''));

        if ($chat === '') {
            return null;
        }

        LapisanData::simpanCrm([
            'chat_id' => $chat,
            'nomor_wa' => $rb['nomor_wa'],
            'nama' => $rb['nama'],
            'sesi_wa' => $rb['session'],
            'tipe_customer' => $rb['tipe_customer'],
            'tahap_funnel' => $rb['tahap_funnel'],
            'skor_prospek' => $rb['skor_prospek'],
            'minat_produk' => $rb['minat_produk'],
            'lokasi_jualan' => $rb['lokasi_jualan'],
            'estimasi_modal' => $rb['estimasi_modal'],
            'kota' => $rb['kota'],
            'ringkasan_terakhir' => $rb['ringkasan_terakhir'],
            'pesan_terakhir_customer' => $rb['pesan_terakhir_customer'],
            'jumlah_followup' => $rb['jumlah_followup'],
            'tgl_interaksi_terakhir' => $rb['tgl_interaksi_terakhir'],
            'tgl_followup_berikutnya' => $rb['tgl_followup_berikutnya'],
            'status_optout' => $rb['status_optout'],
            'butuh_admin' => $rb['butuh_admin'],
            'catatan' => $rb['catatan'],
            // an auto-reply is not answered, so what the bot last said stays what it was
            'balasan_terakhir_bot' => $rb['perlu_dibalas'] === false ? (string) $balasanLama : mb_substr((string) $rb['balasan'], 0, 700),
            'draft_pesanan' => (string) $rb['draft_pesanan'],
            'eskalasi_terbuka' => $rb['eskalasi_terbuka'],
        ]);

        return CrmCustomer::query()->where('chat_id', $chat)->first();
    }

    /**
     * The supervisor's answer has reached the customer: the question is closed and what was said
     * is kept, so Adiva does not ask again or make up an answer.
     */
    public static function jawabanSupervisor(string $chatId, string $ringkasan, string $balasan, string $jawaban): void
    {
        LapisanData::simpanCrm([
            'chat_id' => $chatId,
            'ringkasan_terakhir' => $ringkasan,
            'balasan_terakhir_bot' => $balasan,
            'jawaban_supervisor' => $jawaban,
            'eskalasi_terbuka' => '',
        ], buat: false);
    }

    /**
     * Adiva could not answer twice over: the chat memory is started again, so the next message is
     * not weighed down by the conversation that broke it.
     */
    public static function resetIngatan(string $chatId, int $memGen): void
    {
        LapisanData::simpanCrm(['chat_id' => $chatId, 'mem_gen' => $memGen + 1]);
    }

    /**
     * An order is finished or closed: the customer cools to WARM, the follow-up counter
     * and date are cleared, and Adiva no longer asks for an admin. A new stage is stamped
     * in tahap_sejak, and a stage the team moved by hand is let go.
     */
    public static function penutupan(LogOrder $order, ?TahapFunnel $tahap, string $catatan): ?CrmCustomer
    {
        if (blank($order->chat_id)) {
            return null;
        }

        $customer = CrmCustomer::query()->firstOrCreate(['chat_id' => $order->chat_id]);

        $ubah = [
            'nama' => filled($order->nama_penerima) ? $order->nama_penerima : $customer->nama,
            'skor_prospek' => SkorProspek::Warm,
            'jumlah_followup' => 0,
            'tgl_interaksi_terakhir' => now(),
            'tgl_followup_berikutnya' => null,
            'butuh_admin' => false,
            'alasan_butuh_admin' => null,
            'catatan' => $catatan,
        ];

        if ($tahap !== null && $tahap !== $customer->tahap_funnel) {
            $ubah += [
                'tahap_funnel' => $tahap,
                'tahap_sejak' => [...($customer->tahap_sejak ?? []), $tahap->value => now()->toIso8601String()],
                'tahap_manual_oleh_id' => null,
                'tahap_manual_at' => null,
            ];
        }

        $customer->update($ubah);

        return $customer;
    }
}
