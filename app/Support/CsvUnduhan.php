<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every "Ekspor CSV" in the panel: streamed line by line (a lazy query or a generator is
 * only read while the file goes out), a BOM so Excel reads "·", "×" and "→" as UTF-8, and
 * each data cell guarded against formula injection (CsvSanitizer).
 */
class CsvUnduhan
{
    /**
     * @param  list<string>  $kepala  the header line, written as is
     * @param  iterable<array<int, mixed>>  $baris  one array per line
     */
    public static function buat(string $namaBerkas, array $kepala, iterable $baris): StreamedResponse
    {
        return response()->streamDownload(function () use ($kepala, $baris) {
            $keluar = fopen('php://output', 'w');

            if ($keluar === false) {
                return;
            }

            fwrite($keluar, "\xEF\xBB\xBF");
            fputcsv($keluar, $kepala, escape: '');

            foreach ($baris as $isi) {
                fputcsv($keluar, CsvSanitizer::baris($isi), escape: '');
            }

            fclose($keluar);
        }, $namaBerkas, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
