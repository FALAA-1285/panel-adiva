<?php

use App\Support\DiffBaris;

test('the draft is marked against the active version as in the design', function () {
    $lama = "a\nb\n\nc\nresi karangan";
    $baru = "a\nb\norder 200 pcs\n\nc\nresi atau harga karangan";

    expect(DiffBaris::banding($lama, $baru))->toBe([
        ['jenis' => 'sama', 'no' => 1, 'teks' => 'a'],
        ['jenis' => 'sama', 'no' => 2, 'teks' => 'b'],
        ['jenis' => 'tambah', 'no' => 3, 'teks' => 'order 200 pcs'],
        ['jenis' => 'sama', 'no' => 4, 'teks' => ''],
        ['jenis' => 'sama', 'no' => 5, 'teks' => 'c'],
        // the removed line comes first and has no number
        ['jenis' => 'hapus', 'no' => null, 'teks' => 'resi karangan'],
        ['jenis' => 'tambah', 'no' => 6, 'teks' => 'resi atau harga karangan'],
    ]);
});

test('unchanged text has no marks', function () {
    expect(array_column(DiffBaris::banding("a\r\nb", "a\nb"), 'jenis'))->toBe(['sama', 'sama'])
        ->and(DiffBaris::banding('', ''))->toBe([]);
});

test('a new section is all added lines and a removed one all removed lines', function () {
    expect(array_column(DiffBaris::banding('', "a\nb"), 'jenis'))->toBe(['tambah', 'tambah'])
        ->and(DiffBaris::banding("a\nb", ''))->toBe([
            ['jenis' => 'hapus', 'no' => null, 'teks' => 'a'],
            ['jenis' => 'hapus', 'no' => null, 'teks' => 'b'],
        ]);
});
