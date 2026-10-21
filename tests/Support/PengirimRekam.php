<?php

namespace Tests\Support;

use App\Bot\PengirimanGagal;
use App\Bot\PengirimWa;

/**
 * A WhatsApp sender for the tests: keeps what would have been sent, or refuses.
 */
final class PengirimRekam implements PengirimWa
{
    /** @var list<array{sesi: string, chat_id: string, teks: string, gambar: string|null, berkas?: string}> */
    public array $terkirim = [];

    public function __construct(public ?string $tolak = null) {}

    /**
     * Use it for everything the app sends from now on.
     */
    public static function pasang(?string $tolak = null): self
    {
        $rekam = new self($tolak);
        app()->instance(PengirimWa::class, $rekam);

        return $rekam;
    }

    public function kirimTeks(string $sesi, string $chatId, string $teks): string
    {
        return $this->simpan($sesi, $chatId, $teks, null);
    }

    public function kirimGambar(string $sesi, string $chatId, string $url, string $mimetype, string $caption): string
    {
        return $this->simpan($sesi, $chatId, $caption, $url);
    }

    public function kirimBerkas(string $sesi, string $chatId, string $isi, string $mimetype, string $namaFile, string $caption): string
    {
        return $this->simpan($sesi, $chatId, $caption, null, $namaFile);
    }

    /**
     * The 6-digit code in the last message.
     */
    public function kodeTerakhir(): string
    {
        preg_match('/\d{6}/', end($this->terkirim)['teks'] ?? '', $cocok);

        return $cocok[0] ?? '';
    }

    private function simpan(string $sesi, string $chatId, string $teks, ?string $gambar, ?string $berkas = null): string
    {
        if ($this->tolak !== null) {
            throw new PengirimanGagal($this->tolak);
        }

        // "berkas" is only there for a file, so what the other tests compare stays as it was
        $this->terkirim[] = ['sesi' => $sesi, 'chat_id' => $chatId, 'teks' => $teks, 'gambar' => $gambar, ...($berkas !== null ? ['berkas' => $berkas] : [])];

        return 'rekam-'.count($this->terkirim);
    }
}
