<?php

namespace App\Bot;

use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use App\Models\User;
use App\Support\PesanKeluar;
use App\Support\SimpanCrm;

/**
 * "Jawab lewat Adiva" without n8n doing the sending: n8n only words the supervisor's answer
 * (its small agent), the panel sends it, writes the chat line and closes the question in the CRM.
 *
 * The agent's sentence is used only when every number of the supervisor's answer is in it and no
 * new number or internal word appeared; otherwise the standard sentence carries the answer as it
 * was written. That check is n8n's "Rapikan Jawaban".
 */
class LokalJawabAdiva implements JawabAdiva
{
    private const TERLARANG = '/\b(sistem|bot|AI|admin|ACC|di-ACC|otomatis|prompt)\b/i';

    public function __construct(private N8nWebhook $n8n, private PesanKeluar $pesan) {}

    public function sampaikan(CrmCustomer $customer, string $jawaban, User $oleh): void
    {
        $jawaban = trim($jawaban);
        $pertanyaan = (string) (self::json($customer->eskalasi_terbuka)['alasan'] ?? '');

        $hasil = $this->n8n->kirim('JAWAB', [
            'chat_id' => $customer->chat_id,
            'nama' => $customer->nama,
            'pertanyaan' => $pertanyaan,
            'pesan_customer_terakhir' => (string) $customer->pesan_terakhir_customer,
            'balasan_bot_terakhir' => (string) $customer->balasan_terakhir_bot,
            'jawaban' => $jawaban,
            'oleh' => ['id' => $oleh->id, 'nama' => $oleh->name, 'nomor_wa' => $oleh->nomor_wa],
        ], (int) config('services.n8n.timeout_uji', 60));

        $teks = self::rapikan((string) ($hasil['teks'] ?? ''), $jawaban, $customer, $pertanyaan);
        $stamp = now()->timezone('Asia/Jakarta')->format('Y-m-d H:i');

        try {
            $pesan = $this->pesan->teks(PesanKeluar::sesi($customer->sesi_wa), (string) $customer->chat_id, $teks, meta: ['jawaban_supervisor' => $oleh->name]);
        } catch (PengirimanGagal) {
            throw new PerintahGagal('WhatsApp menolak kiriman, jawaban belum sampai ke customer. Coba lagi sebentar.');
        }

        $pesan->update(['user_id' => $oleh->id, 'pengirim' => PengirimPesan::Adiva]);

        SimpanCrm::jawabanSupervisor(
            (string) $customer->chat_id,
            "Supervisor menjawab ({$stamp}): {$jawaban} — sudah kami sampaikan ke customer.",
            $teks,
            (string) json_encode(['waktu' => $stamp, 'pertanyaan' => $pertanyaan, 'jawaban' => $jawaban, 'pesan' => $teks], JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The agent's sentence, or the standard one with the answer as the supervisor wrote it.
     */
    private static function rapikan(string $ai, string $jawaban, CrmCustomer $customer, string $pertanyaan): string
    {
        $ai = trim((string) preg_replace('/\n{3,}/', "\n\n", str_replace('**', '*', trim($ai, "\"'“” \t\n\r"))));
        $angka = fn (string $s) => array_map('intval', preg_match_all('/\d+/', (string) preg_replace('/(\d)[.,](?=\d{3}\b)/', '$1', $s), $m) ? $m[0] : []);

        $boleh = array_merge($angka($jawaban), $angka($pertanyaan), $angka((string) $customer->pesan_terakhir_customer), $angka((string) $customer->balasan_terakhir_bot));
        $wajib = $angka($jawaban);
        $angkaAi = $angka($ai);
        $aman = array_diff($angkaAi, $boleh) === [] && array_diff($wajib, $angkaAi) === [];
        $pakaiAi = $ai !== '' && mb_strlen($ai) >= 15 && mb_strlen($ai) <= 900 && $aman && ! preg_match(self::TERLARANG, $ai);

        if ($pakaiAi) {
            return $ai;
        }

        $depan = explode(' ', trim((string) $customer->nama))[0];

        return 'Kak'.($depan !== '' ? " {$depan}" : '').", tadi sudah kami tanyakan ke supervisor kami ya 🙏\n\n".(mb_strtoupper(mb_substr($jawaban, 0, 1)).mb_substr($jawaban, 1));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(mixed $v): array
    {
        $d = is_string($v) ? json_decode($v, true) : $v;

        return is_array($d) ? $d : [];
    }
}
