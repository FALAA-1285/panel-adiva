<?php

namespace App\Bot;

use App\Models\User;
use App\Support\NomorBot;
use App\Support\PengaturanToko;

/**
 * Who is writing: staff or the supervisor (their messages are never answered by the AI), or a
 * customer. n8n's "Ambil Konteks Awal" + the first half of "Cek Apakah Admin".
 *
 * WhatsApp often shows a customer as an "@lid" code instead of a phone number, so the real
 * number is looked up first (WahaChat::nomorDariLid) and both are compared with the staff list.
 */
class KenaliPengirim
{
    /**
     * A number the way n8n always compared them: digits only, 0812… and 812… become 62812…
     * ("@c.us" and other suffixes dropped).
     */
    public static function norm(?string $nomor): string
    {
        $d = (string) preg_replace('/\D/', '', explode('@', (string) $nomor)[0]);

        return match (true) {
            $d === '' => '',
            str_starts_with($d, '0') => '62'.substr($d, 1),
            str_starts_with($d, '8') => '62'.$d,
            default => $d,
        };
    }

    /**
     * @param  string|null  $pn  the phone number behind an "@lid" ("6281…@c.us"), when it was found
     */
    public function admin(PesanMasuk $pesan, ?string $pn = null): bool
    {
        $nomor = self::norm($pesan->dari());
        $asli = filled($pn) && str_ends_with((string) $pn, '@c.us') ? self::norm($pn) : '';

        $admin = User::query()
            ->whereNotNull('nomor_wa')->where('nomor_wa', '!=', '')
            ->whereNull('nonaktif_at')->whereNull('wa_dicabut_at')
            ->pluck('nomor_wa')
            ->map(fn ($n) => self::norm($n))
            ->push(self::norm(PengaturanToko::nilai('ADMIN_WA')))
            ->filter()
            ->all();

        return ($nomor !== '' && in_array($nomor, $admin, true)) || ($asli !== '' && in_array($asli, $admin, true));
    }

    /**
     * Which of the bot's numbers the customer wrote to, but only when there is more than one
     * (or this one is not on the list): the AI is told, so it does not say "our number" wrongly.
     */
    public function labelNomorBot(string $sesi): string
    {
        $daftar = NomorBot::daftar();

        return count($daftar) > 1 || (count($daftar) > 0 && ! isset($daftar[$sesi])) ? ($daftar[$sesi] ?? $sesi) : '';
    }
}
