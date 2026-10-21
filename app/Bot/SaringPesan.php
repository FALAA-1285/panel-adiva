<?php

namespace App\Bot;

/**
 * Sorts a WhatsApp message into the path it takes: n8n's "Cek Apakah Admin" (the part that
 * reads what kind of message it is), "Jenis Pesan" and the two checks of D1.
 *
 * - system messages (a newsletter, a reaction, a revoked message) and unreadable ones are marked ignored
 * - a location, a contact card and a sticker become a line of text, so the AI can read them
 * - then: an admin's command, an admin's other message, an attachment, or a customer's chat
 */
final readonly class SaringPesan
{
    /** WhatsApp message types the bot neither answers nor passes on. */
    private const TIPE_ABAIKAN = [
        'interactive', 'e2e_notification', 'notification', 'notification_template', 'gp2', 'call_log', 'protocol', 'ciphertext',
        'revoked', 'broadcast_notification', 'pinned_message', 'reaction', 'poll_update', 'newsletter_notification',
        'keep_in_chat', 'event_creation',
    ];

    /** What an admin types to run an order command (these now live in the panel). */
    private const PERINTAH_ORDER = '/^\s*(acc|tolak|lunas|kirim|selesai|batal|tutup)(\s+(ord-|inv\s*[\/-])|\s*$)|^\s*batal\s+kirim\s+(ord-|inv\s*[\/-])|^\s*siap\s+(ord-|inv\s*[\/-])|^\s*bantu\b/';

    private const PERINTAH_JEDA = '/^\s*(jeda|lanjut|daftar\s*jeda|list\s*jeda|catat|jawab)\b/';

    public const PERINTAH_ADMIN = 'perintah admin';

    public const ADMIN_LAIN = 'pesan admin lain';

    public const LAMPIRAN = 'lampiran';

    public const CHAT = 'chat customer';

    /**
     * @param  string  $jalur  PERINTAH_ADMIN, ADMIN_LAIN, LAMPIRAN or CHAT
     */
    public function __construct(
        public PesanMasuk $pesan,
        public string $jalur,
        public bool $abaikan = false,
        public string $alasanAbaikan = '',
    ) {}

    public static function pilah(PesanMasuk $pesan, bool $admin): self
    {
        [$pesan, $abaikan, $alasan] = self::baca($pesan);

        if ($admin) {
            $teks = strtolower(trim((string) preg_replace('/[*_~]/', '', $pesan->body())));

            return new self($pesan, preg_match(self::PERINTAH_ORDER, $teks) || preg_match(self::PERINTAH_JEDA, $teks) ? self::PERINTAH_ADMIN : self::ADMIN_LAIN, $abaikan, $alasan);
        }

        $lampiran = ! $abaikan && ! $pesan->dariSaya() && ! $pesan->grup() && ! $pesan->status() && $pesan->adaLampiran();

        return new self($pesan, $lampiran ? self::LAMPIRAN : self::CHAT, $abaikan, $alasan);
    }

    /**
     * Is it a message the bot would pass over (its own, a group's, a status, an empty one, a
     * system one)? D1 counts those as "Diabaikan" on Monitoring.
     */
    public function dibuang(): bool
    {
        return $this->abaikan || $this->pesan->dariSaya() || $this->pesan->grup() || $this->pesan->status() || trim($this->pesan->body()) === '';
    }

    /**
     * @return array{0: PesanMasuk, 1: bool, 2: string}
     */
    private static function baca(PesanMasuk $pesan): array
    {
        $payload = $pesan->payload;
        $data = $pesan->data();
        $tipe = $pesan->tipe();
        $dari = strtolower($pesan->dari());
        $body = $pesan->body();
        $awal = substr($body, 0, 400);
        $lokasi = is_array($payload['location'] ?? null) ? $payload['location'] : [];

        // text that is a long run of base64 with no space is a picture that lost its media
        $mirip = strlen($body) > 300 && ! preg_match('/\s/', $awal) && preg_match('/^[A-Za-z0-9+\/=]+$/', $awal);

        if ($dari === '0@c.us' || $dari === 'status@broadcast' || preg_match('/@(newsletter|broadcast)$/', $dari)) {
            return [$pesan, true, 'akun sistem WhatsApp'];
        }

        if (in_array($tipe, self::TIPE_ABAIKAN, true)) {
            return [$pesan, true, "pesan sistem ({$tipe})"];
        }

        if ($tipe === 'location' || ! empty($lokasi['latitude']) || ! empty($lokasi['longitude'])) {
            $lat = $lokasi['latitude'] ?? $data['lat'] ?? null;
            $lng = $lokasi['longitude'] ?? $data['lng'] ?? null;
            $ket = collect([$lokasi['name'] ?? null, $lokasi['address'] ?? null, $lokasi['description'] ?? null, $data['loc'] ?? null])
                ->filter(fn ($x) => is_string($x) && $x !== '')->implode(', ');

            return [self::jadiTeks($pesan, '[Customer mengirim lokasi'.($ket !== '' ? ": {$ket}" : '').($lat && $lng ? " | titik peta: https://maps.google.com/?q={$lat},{$lng}" : '').']'), false, ''];
        }

        $kartu = is_array($payload['vCards'] ?? null) ? $payload['vCards'] : [];

        if (in_array($tipe, ['vcard', 'multi_vcard'], true) || $kartu !== []) {
            $isi = collect($kartu)->map(function ($v) {
                preg_match('/FN:(.*)/', (string) $v, $fn);
                preg_match('/TEL[^:]*:(.*)/', (string) $v, $tel);

                return trim(($fn[1] ?? '').' '.($tel[1] ?? ''));
            })->filter()->implode('; ');

            return [self::jadiTeks($pesan, '[Customer mengirim kartu kontak'.($isi !== '' ? ": {$isi}" : '').']'), false, ''];
        }

        if ($tipe === 'sticker') {
            return [self::jadiTeks($pesan, '[Customer mengirim stiker]'), false, ''];
        }

        if ($mirip && ($payload['hasMedia'] ?? false) !== true) {
            return [$pesan, true, 'isi pesan tidak terbaca'];
        }

        return [$pesan, false, ''];
    }

    private static function jadiTeks(PesanMasuk $pesan, string $teks): PesanMasuk
    {
        return $pesan->dengan(['body' => $teks, 'hasMedia' => false, 'media' => null]);
    }
}
