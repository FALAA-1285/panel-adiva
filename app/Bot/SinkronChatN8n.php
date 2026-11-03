<?php

namespace App\Bot;

use App\Enums\PengirimPesan;
use App\Models\CrmCustomer;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Percakapan shows the running chatbot ("chatbot bangbana (main)"), which keeps its customers
 * in the n8n Data Table CRM_CUSTOMER instead of the panel's database. This copies them in,
 * reading the n8n API only (GET), so the bot and its workflows are never touched. Customers go
 * through n8n_simpan_crm, which already turns the bot's text values into the panel's columns.
 *
 * The chat lines are WhatsApp's own, read from WAHA through the gateway workflow (GerbangWaha),
 * each added once by its message id. Without the gateway they fall back to the bot's memory
 * table RIWAYAT_CHAT, which merges a turn into one line and keeps only what the bot answered.
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
     * Chat id => the newest WhatsApp timestamp copied (0: read, WAHA had nothing).
     */
    private const KUNCI_WAHA = 'sinkron-chat:waha-sejak';

    /**
     * Chats read from WAHA per run, and per gateway call.
     */
    private const BATAS_CHAT = 40;

    private const CHAT_PER_PANGGILAN = 20;

    public function __construct(private GerbangWaha $gerbang) {}

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
            $berubah = $this->customer($api);

            return [
                'customer' => count($berubah),
                'pesan' => GerbangWaha::siap() ? $this->pesanWaha($berubah) : $this->pesan($api),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * CRM_CUSTOMER rows changed since the last run (all of them on the first). The API's
     * updatedAt filter does not match reliably, so every row is read (a few dozen) and only
     * those whose updatedAt moved are saved.
     *
     * @return list<string> the chats of the customers saved
     */
    private function customer(PendingRequest $api): array
    {
        /** @var array<string, string> $versi */
        $versi = (array) Cache::get(self::KUNCI_CRM, []);
        $berubah = [];

        foreach ($this->semua($api, self::TABEL_CRM, null) as $b) {
            $id = (string) ($b['id'] ?? '');
            $diubah = (string) ($b['updatedAt'] ?? '');

            if (trim((string) ($b['chat_id'] ?? '')) === '' || ($diubah !== '' && ($versi[$id] ?? null) === $diubah)) {
                continue;
            }

            LapisanData::simpanCrm(array_diff_key($b, array_flip(['id', 'createdAt', 'updatedAt'])));
            $versi[$id] = $diubah;
            $berubah[] = trim((string) $b['chat_id']);
        }

        Cache::forever(self::KUNCI_CRM, $versi);

        return $berubah;
    }

    /**
     * WhatsApp's own messages from WAHA: chats whose customer just changed, those active in the
     * last day, and those never read yet. A chat read from WAHA for the first time drops the
     * lines copied from RIWAYAT_CHAT, so nothing shows twice.
     *
     * @param  list<string>  $berubah
     */
    private function pesanWaha(array $berubah): int
    {
        /** @var array<string, int> $sejak */
        $sejak = (array) Cache::get(self::KUNCI_WAHA, []);
        // what someone typed on the phone changes no customer row, so recent chats are read again
        $aktif = CrmCustomer::query()
            ->where(fn ($q) => $q->where('tgl_interaksi_terakhir', '>=', now()->subDay())
                ->orWhereHas('pesan', fn ($p) => $p->where('waktu', '>=', now()->subDays(3))))
            ->orderByDesc('tgl_interaksi_terakhir')->limit(20)->pluck('chat_id')->all();
        $belum = CrmCustomer::query()->whereNotIn('chat_id', array_keys($sejak))
            ->orderByDesc('tgl_interaksi_terakhir')->limit(self::BATAS_CHAT)->pluck('chat_id')->all();
        $chat = array_slice(array_values(array_unique([...$berubah, ...$aktif, ...$belum])), 0, self::BATAS_CHAT);
        $customer = CrmCustomer::query()->whereIn('chat_id', $chat)->get()->keyBy('chat_id');
        $tambah = 0;

        foreach (array_chunk($chat, self::CHAT_PER_PANGGILAN) as $potong) {
            $hasil = $this->gerbang->bacaPesan(array_map(fn (string $c) => [
                'chatId' => $c,
                'session' => $customer->get($c)?->sesi_wa,
                // a little overlap: a message id already copied is skipped anyway
                'sejak' => max(0, ($sejak[$c] ?? 0) - 120),
                'batas' => isset($sejak[$c]) ? 100 : 300,
            ], $potong));
            $ada = DB::table('pesan')->whereIn('chat_id', $potong)->whereNotNull('wa_message_id')->pluck('wa_message_id')->flip();
            $sekarang = now();
            $isi = [];

            foreach ($potong as $c) {
                $daftar = $hasil[$c] ?? [];
                $orang = $customer->get($c);

                if ($daftar !== [] && empty($sejak[$c])) {
                    DB::table('pesan')->where('chat_id', $c)->whereNotNull('meta->riwayat_id')->delete();
                }

                foreach ($daftar as $p) {
                    $id = (string) ($p['id'] ?? '');

                    if ($id === '' || $ada->has($id) || $orang === null) {
                        continue;
                    }

                    $ada->put($id, true);
                    $isi[] = self::barisWaha($orang, $p, $sekarang);
                }

                $sejak[$c] = max((int) ($sejak[$c] ?? 0), (int) collect($daftar)->max('timestamp'));
            }

            foreach (array_chunk($isi, 200) as $baris) {
                DB::table('pesan')->insert($baris);
            }

            $tambah += count($isi);
        }

        Cache::forever(self::KUNCI_WAHA, $sejak);

        return $tambah;
    }

    /**
     * One WAHA message as a `pesan` row. The bot sends through the API; what someone typed on the
     * phone (source "app") is an admin's line.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private static function barisWaha(CrmCustomer $c, array $p, CarbonInterface $sekarang): array
    {
        $jenis = strtolower((string) ($p['type'] ?? ''));
        $mime = strtolower((string) ($p['mimetype'] ?? ''));
        $media = ! empty($p['hasMedia']) || in_array($jenis, ['image', 'video', 'ptt', 'audio', 'document', 'sticker'], true);
        $lampiran = $media ? match (true) {
            $jenis === 'sticker' => 'Stiker',
            $jenis === 'image' || str_starts_with($mime, 'image/') => 'Foto',
            $jenis === 'video' || str_starts_with($mime, 'video/') => 'Video',
            in_array($jenis, ['ptt', 'audio'], true) || str_starts_with($mime, 'audio/') => 'Pesan suara',
            default => 'Berkas',
        } : null;
        $teks = trim((string) ($p['body'] ?? ''));

        return [
            'crm_customer_id' => $c->id,
            'chat_id' => $c->chat_id,
            'sesi_wa' => $c->sesi_wa,
            // with its offset: the connection reads a bare time as WIB
            'waktu' => Carbon::createFromTimestamp((int) ($p['timestamp'] ?? 0))->toIso8601String(),
            'pengirim' => match (true) {
                empty($p['fromMe']) => PengirimPesan::Customer->value,
                ($p['source'] ?? null) === 'app' => PengirimPesan::Admin->value,
                default => PengirimPesan::Adiva->value,
            },
            'teks' => $teks === '' ? null : $teks,
            'lampiran_keterangan' => $lampiran,
            'wa_message_id' => (string) $p['id'],
            'meta' => json_encode(['waha' => true]),
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ];
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
