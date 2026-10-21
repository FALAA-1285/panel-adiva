<?php

namespace App\Support;

/**
 * Line diff of a prompt section, draft against the active version (Prompt.dc.html).
 * Longest common subsequence: sections are tens of lines, so the table stays small.
 */
class DiffBaris
{
    /**
     * Lines in draft order. A removed line comes before the line added in its place
     * and has no number; the others are numbered as in the draft.
     *
     * @return list<array{jenis: 'sama'|'tambah'|'hapus', no: int|null, teks: string}>
     */
    public static function banding(string $lama, string $baru): array
    {
        $a = self::pecah($lama);
        $b = self::pecah($baru);
        $m = count($a);
        $n = count($b);

        // $lcs[$i][$j]: longest common subsequence of $a[$i..] and $b[$j..]
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $baris = [];
        $i = $j = 0;

        while ($i < $m || $j < $n) {
            if ($i < $m && $j < $n && $a[$i] === $b[$j]) {
                $baris[] = ['jenis' => 'sama', 'no' => $j + 1, 'teks' => $b[$j]];
                $i++;
                $j++;
            } elseif ($i < $m && ($j === $n || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $baris[] = ['jenis' => 'hapus', 'no' => null, 'teks' => $a[$i]];
                $i++;
            } else {
                $baris[] = ['jenis' => 'tambah', 'no' => $j + 1, 'teks' => $b[$j]];
                $j++;
            }
        }

        return $baris;
    }

    /**
     * @return list<string>
     */
    private static function pecah(string $teks): array
    {
        return $teks === '' ? [] : explode("\n", str_replace("\r\n", "\n", $teks));
    }
}
