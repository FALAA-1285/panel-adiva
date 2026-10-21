<?php

use App\Support\NomorWa;

test('numbers are normalised to 62 followed by digits', function (string $masuk) {
    expect(NomorWa::normalisasi($masuk))->toBe('6285712340036');
})->with(['857 1234 0036', '0857-1234-0036', '6285712340036', '+62 857 1234 0036']);

test('numbers are masked as in the design', function () {
    expect(NomorWa::samarkan('6285712340036'))->toBe('+62 857-••••-••36')
        ->and(NomorWa::samarkan('081277770007'))->toBe('+62 812-••••-••07')
        ->and(NomorWa::samarkanLokal('6281234560007'))->toBe('0812-••••-••07')
        ->and(NomorWa::samarkanPenuh('6281234560007'))->toBe('0812-••••-••••')
        ->and(NomorWa::samarkanUjung('6289612000077'))->toBe('0896-1200-••77')
        ->and(NomorWa::samarkanTengah('6281298760646'))->toBe('0812-••••-0646')
        ->and(NomorWa::samarkanKartu('6287800415120'))->toBe('+62 878-••••-5120');
});
