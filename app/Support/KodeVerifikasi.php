<?php

namespace App\Support;

use App\Bot\KirimKeStaf;
use App\Bot\PengirimanGagal;
use Illuminate\Validation\ValidationException;

/**
 * 6-digit WhatsApp codes (Daftar, Lupa sandi). Only an HMAC of the code is kept
 * in the session.
 */
class KodeVerifikasi
{
    /**
     * A new random code.
     */
    public static function buat(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send the code to the staff member's WhatsApp. When WAHA does not take it the
     * request stops here, before the code is kept or a send is counted.
     *
     * @throws ValidationException
     */
    public static function kirim(KirimKeStaf $kirimKeStaf, string $nomor, string $baris): void
    {
        try {
            $kirimKeStaf->kirim($nomor, "{$baris}\nBerlaku 5 menit. Jangan berikan kode ini ke siapa pun.");
        } catch (PengirimanGagal $e) {
            report($e);

            throw ValidationException::withMessages(['nomor_wa' => 'Kode gagal dikirim ke WhatsApp. Coba lagi sebentar.']);
        }
    }

    public static function hash(string $kode): string
    {
        return hash_hmac('sha256', $kode, (string) config('app.key'));
    }

    public static function cocok(string $kode, string $hash): bool
    {
        return hash_equals($hash, self::hash($kode));
    }
}
