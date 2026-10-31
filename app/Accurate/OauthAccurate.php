<?php

namespace App\Accurate;

use App\Models\AksesAccurate;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Accurate through OAuth (ACCURATE_CLIENT_ID + ACCURATE_CLIENT_SECRET): a supervisor logs in to
 * Accurate once and gives access ("Sambungkan Accurate"); the panel keeps the tokens in
 * AksesAccurate, renews them before their 15 days run out, and opens the Bana Food database for
 * its host and session. Only the item_view scope is asked for.
 */
class OauthAccurate
{
    public const AKUN = 'https://account.accurate.id';

    public const SCOPE = 'item_view';

    /** an access token closer than this to its end is renewed before reading */
    public const PERBARUI_HARI = 2;

    public static function siap(): bool
    {
        return filled(config('services.accurate.client_id')) && filled(config('services.accurate.client_secret'));
    }

    /**
     * Accurate's login and "Beri Akses" page.
     */
    public function urlMasuk(string $state): string
    {
        return self::AKUN.'/oauth/authorize?'.http_build_query([
            'client_id' => (string) config('services.accurate.client_id'),
            'response_type' => 'code',
            'redirect_uri' => (string) config('services.accurate.redirect'),
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    /**
     * The code from the callback, traded for tokens; the Bana Food database is opened at once,
     * so an account without access to it is refused here and not on the first sync.
     */
    public function sambungkan(string $kode, User $oleh): AksesAccurate
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $kode, 'redirect_uri' => (string) config('services.accurate.redirect')]);

        $akses = new AksesAccurate([
            'disambungkan_oleh_id' => $oleh->id,
            'disambungkan_at' => Demo::jamNyata(),
            ...self::isiToken($token),
        ]);
        $this->bukaDatabase($akses, simpan: false);

        AksesAccurate::query()->delete();
        $akses->save();

        return $akses;
    }

    /**
     * Before a sync: a token near its end is renewed, a missing session is opened.
     */
    public function siapkan(AksesAccurate $akses): void
    {
        if ($akses->kedaluwarsa_at !== null && $akses->kedaluwarsa_at->lt(Demo::jamNyata()->addDays(self::PERBARUI_HARI))) {
            $this->perbarui($akses);
        }

        if (blank($akses->host) || blank($akses->session)) {
            $this->bukaDatabase($akses);
        }
    }

    /**
     * A new access token from the refresh token.
     */
    public function perbarui(AksesAccurate $akses): void
    {
        if (blank($akses->refresh_token)) {
            throw new AccurateGagal('Sambungan Accurate perlu disambungkan ulang: tidak ada refresh token.');
        }

        try {
            $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $akses->refresh_token], $akses);
        } catch (AccurateGagal $e) {
            throw new AccurateGagal('Sambungan Accurate perlu disambungkan ulang ('.$e->getMessage().').', $e->status);
        }

        $akses->update(self::isiToken($token) + ['refresh_token' => $akses->refresh_token]);
    }

    /**
     * open-db.do: the Bana Food database's host and session (X-Session-ID).
     */
    public function bukaDatabase(AksesAccurate $akses, bool $simpan = true): void
    {
        $db = (string) config('services.accurate.db_id');

        try {
            $jawaban = Http::withToken($akses->access_token)->acceptJson()
                ->timeout((int) config('services.accurate.timeout', 30))
                ->get(self::AKUN.'/api/open-db.do', ['id' => $db]);
        } catch (ConnectionException $e) {
            throw new AccurateGagal('Accurate tidak bisa dihubungi ('.$e->getMessage().').');
        }

        $isi = (array) $jawaban->json();
        // the fields at the top, or under "d"
        $host = rtrim((string) ($isi['host'] ?? data_get($isi, 'd.host', '')), '/');
        $session = (string) ($isi['session'] ?? data_get($isi, 'd.session', ''));

        if (! $jawaban->successful() || ($isi['s'] ?? true) === false || $host === '' || $session === '') {
            throw new AccurateGagal("Database Accurate {$db} tidak bisa dibuka dengan akun ini: ".self::alasan($jawaban, $akses), $jawaban->status());
        }

        $akses->fill(['host' => $host, 'session' => $session, 'session_at' => Demo::jamNyata()]);

        if ($simpan) {
            $akses->save();
        }
    }

    /**
     * Only what can never be read back from the panel: a reason without tokens or secrets.
     */
    public static function samarkan(string $teks, ?AksesAccurate $akses = null): string
    {
        $rahasia = array_filter([
            (string) config('services.accurate.client_secret'),
            (string) config('services.accurate.token'),
            (string) config('services.accurate.signature_secret'),
            $akses?->access_token, $akses?->refresh_token, $akses?->session,
        ], fn ($r) => is_string($r) && mb_strlen($r) >= 6);

        return str_replace($rahasia, '***', $teks);
    }

    /**
     * @param  array<string, string>  $isian
     * @param  AksesAccurate|null  $akses  the access being renewed, whose tokens are hidden from the reason
     * @return array<string, mixed>
     */
    private function token(array $isian, ?AksesAccurate $akses = null): array
    {
        if (! self::siap()) {
            throw new AccurateGagal('ACCURATE_CLIENT_ID dan ACCURATE_CLIENT_SECRET belum diisi.');
        }

        try {
            $jawaban = Http::asForm()->acceptJson()
                ->withBasicAuth((string) config('services.accurate.client_id'), (string) config('services.accurate.client_secret'))
                ->timeout((int) config('services.accurate.timeout', 30))
                ->post(self::AKUN.'/oauth/token', $isian);
        } catch (ConnectionException $e) {
            throw new AccurateGagal('Accurate tidak bisa dihubungi ('.$e->getMessage().').');
        }

        $isi = (array) $jawaban->json();

        if (! $jawaban->successful() || blank($isi['access_token'] ?? null)) {
            throw new AccurateGagal('Accurate menolak: '.self::alasan($jawaban, $akses), $jawaban->status());
        }

        return $isi;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array{access_token: string, refresh_token?: string, kedaluwarsa_at: mixed, host: null, session: null, session_at: null}
     */
    private static function isiToken(array $token): array
    {
        $detik = (int) ($token['expires_in'] ?? 0);

        return array_filter([
            'access_token' => (string) $token['access_token'],
            'refresh_token' => filled($token['refresh_token'] ?? null) ? (string) $token['refresh_token'] : null,
            'kedaluwarsa_at' => $detik > 0 ? Demo::jamNyata()->addSeconds($detik) : null,
        ], fn ($v) => $v !== null) + ['host' => null, 'session' => null, 'session_at' => null];
    }

    private static function alasan(Response $jawaban, ?AksesAccurate $akses = null): string
    {
        $isi = $jawaban->json();
        $pesan = is_array($isi) ? ($isi['error_description'] ?? $isi['d'] ?? $isi['error'] ?? $isi['message'] ?? null) : null;
        $pesan = is_array($pesan) ? implode(' ', array_filter($pesan, 'is_string')) : $pesan;

        return self::samarkan('HTTP '.$jawaban->status().(filled($pesan) ? ' · '.mb_substr((string) $pesan, 0, 200) : ''), $akses);
    }
}
