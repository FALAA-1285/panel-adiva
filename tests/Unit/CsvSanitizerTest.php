<?php

use App\Support\CsvSanitizer;

test('sanitizes formula triggers starting with =, +, -, @, tab, and return', function () {
    expect(CsvSanitizer::sanitasi('=SUM(A1:A10)'))->toBe("'=SUM(A1:A10)")
        ->and(CsvSanitizer::sanitasi('+12345'))->toBe("'+12345")
        ->and(CsvSanitizer::sanitasi('-cmd|'))->toBe("'-cmd|")
        ->and(CsvSanitizer::sanitasi('@SUM'))->toBe("'@SUM")
        ->and(CsvSanitizer::sanitasi("\tmalicious"))->toBe("'\tmalicious");
});

test('leaves safe strings, numbers, and dates untouched', function () {
    expect(CsvSanitizer::sanitasi('Normal Customer'))->toBe('Normal Customer')
        ->and(CsvSanitizer::sanitasi(150000))->toBe(150000)
        ->and(CsvSanitizer::sanitasi('150000'))->toBe('150000')
        ->and(CsvSanitizer::sanitasi(-500))->toBe(-500)
        ->and(CsvSanitizer::sanitasi('-500'))->toBe('-500')
        ->and(CsvSanitizer::sanitasi('2026-09-28 13:42'))->toBe('2026-09-28 13:42');
});

test('sanitizes an array row', function () {
    $row = ['=cmd', 'Budi', 25000, '+628123'];
    expect(CsvSanitizer::baris($row))->toBe(["'=cmd", 'Budi', 25000, "'+628123"]);
});
