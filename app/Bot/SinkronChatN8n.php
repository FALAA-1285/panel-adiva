<?php

namespace App\Bot;

use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Percakapan shows the running chatbot ("chatbot bangbana (main)"), which keeps its chats in
 * n8n Data Tables instead of the panel's database: RIWAYAT_CHAT (one row per line) and
 * CRM_CUSTOMER. This copies both in, reading the n8n API only (GET), so the bot and its
 * workflows are never touched. Customers go through n8n_simpan_crm, which already turns the
 * bot's text values into the panel's columns; chat lines are added once, by their row id.
 */
class SinkronChatN8n
{
    public const TABEL_RIWAYAT = 'CCcuxk7LDieGZJQO';

    public const TABEL_CRM = '7AuF3cevsuzmRizS';

    /**
     * Chat lines copied per run; a backlog is caught up over the next runs.
     */
    public const BATAS_PESAN = 1000;

    /**
     * CRM_CUSTOMER row id => the updatedAt last saved.
     */
    private const KUNCI_CRM = 'sinkron-chat:crm-versi';

    /**
     * @return array{customer: int, pesan: int}
     *
     * @throws RuntimeException n8n is not configured, cannot be reached, or another run is busy
     */
    public function jalankan(): array
    {
        $url = rtrim((string) config('services.n8n.api_url'), '/');
        $kunci = (string) config('services.n8n.api_key');

        if ($url === '' || $kunci === '') {
            throw new RuntimeException('N8N_API_URL dan N8N_API_KEY belum diisi');
        }

        $api = Http::baseUrl($url.'/api/v1')->withHeaders(['X-N8N-API-KEY' => $kunci])->timeout(20)->acceptJson();
        $lock = Cache::lock('sinkron-chat', 120);

        if (! $lock->get()) {
            throw new RuntimeException('Sinkron chat lain sedang berjalan');
        }

        try {
            return ['customer' => $this->customer($api), 'pesan' => $this->pesan($api)];
        } finally {
            $lock->release();
        }
    }

    /**
     * CRM_CUSTOMER rows changed since the last run (all of them on the first). The API's
     * updatedAt filter does not match reliably, so every row is read (a few dozen) and only
     * those whose updatedAt moved are saved.
     */
    private function customer(PendingRequest $api): int
    {
        /** @var array<string, string> $versi */
        $versi = (array) Cache::get(self::KUNCI_CRM, []);
        $berubah = 0;

        foreach ($this->semua($api, self::TABEL_CRM, null) as $b) {
            $id = (string) ($b['id'] ?? '');
            $diubah = (string) ($b['updatedAt'] ?? '');

            if (trim((string) ($b['chat_id'] ?? '')) === '' || ($diubah !== '' && ($versi[$id] ?? null) === $diubah)) {
                continue;
            }

            LapisanData::simpanCrm(array_diff_key($b, array_flip(['id', 'createdAt', 'updatedAt'])));
            $versi[$id] = $diubah;
            $berubah++;
        }

        Cache::forever(self::KUNCI_CRM, $versi);

        return $berubah;
    }

    /**
     * RIWAYAT_CHAT rows newer than the last one copied.
     */
    private function pesan(PendingRequest $api): int
    {
        $terakhir = (int) DB::table('pesan')->whereNotNull('meta->riwayat_id')->max(DB::raw("(meta->>'riwayat_id')::bigint"));
        $baris = $this->semua($api, self::TABEL_RIWAYAT, [
            'type' => 'and',
            'filters' => [['columnName' => 'id', 'condition' => 'gt', 'value' => $terakhir]],
        ], self::BATAS_PESAN, 'id:asc');

        if ($baris === []) {
            return 0;
        }

        $customer = $this->idCustomer(array_values(array_unique(array_map(fn ($b) => (string) ($b['chat_id'] ?? ''), $baris))));
        $sekarang = now();
        $isi = [];

        foreach ($baris as $b) {
            $chat = (string) ($b['chat_id'] ?? '');

            if ($chat === '' || ! isset($customer[$chat])) {
                continue;
            }

            $isi[] = [
                'crm_customer_id' => $customer[$chat]->id,
                'chat_id' => $chat,
                'sesi_wa' => $customer[$chat]->sesi_wa,
                // with its offset: the connection reads a bare time as WIB
                'waktu' => self::waktu($b)->toIso8601String(),
                'pengirim' => match (strtolower((string) ($b['peran'] ?? ''))) {
                    'customer' => PengirimPesan::Customer->value,
                    'adiva' => PengirimPesan::Adiva->value,
                    default => PengirimPesan::Sistem->value,
                },
                'teks' => $b['teks'] ?? null,
                'meta' => json_encode(['riwayat_id' => (int) $b['id']]),
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ];
        }

        foreach (array_chunk($isi, 200) as $potong) {
            DB::table('pesan')->insert($potong);
        }

        return count($isi);
    }

    /**
     * The customers of these chats; a chat the bot has no CRM row for yet gets an empty one.
     *
     * @param  list<string>  $chat
     * @return array<string, CrmCustomer>
     */
    private function idCustomer(array $chat): array
    {
        $chat = array_values(array_filter($chat, fn ($c) => $c !== ''));
        $ada = CrmCustomer::query()->whereIn('chat_id', $chat)->get()->keyBy('chat_id');

        foreach (array_diff($chat, $ada->keys()->all()) as $baru) {
            LapisanData::simpanCrm(['chat_id' => $baru]);
        }

        return CrmCustomer::query()->whereIn('chat_id', $chat)->get()->keyBy('chat_id')->all();
    }

    /**
     * When the line was written: waktu_ms, else the row's createdAt.
     *
     * @param  array<string, mixed>  $b
     */
    private static function waktu(array $b): Carbon
    {
        $ms = $b['waktu_ms'] ?? null;

        return is_numeric($ms) && $ms > 0
            ? Carbon::createFromTimestampMs((int) $ms)
            : Carbon::parse((string) ($b['createdAt'] ?? 'now'));
    }

    /**
     * Every row the filter leaves, following nextCursor, up to $batas.
     *
     * @param  array<string, mixed>|null  $filter
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    private function semua(PendingRequest $api, string $tabel, ?array $filter, int $batas = 5000, ?string $urut = null): array
    {
        $baris = [];
        $kursor = null;

        do {
            $jawab = $api->get("data-tables/{$tabel}/rows", array_filter([
                'limit' => min(250, $batas - count($baris)),
                'filter' => $filter ? json_encode($filter) : null,
                'sortBy' => $urut,
                'cursor' => $kursor,
            ]))->throw();

            array_push($baris, ...array_values((array) $jawab->json('data')));
            $kursor = $jawab->json('nextCursor');
        } while (is_string($kursor) && $kursor !== '' && count($baris) < $batas);

        return $baris;
    }
}
