<?php

use App\Support\Nama;

test('staff avatars show one letter without the honorific', function () {
    expect(Nama::inisial('Falah'))->toBe('F')
        ->and(Nama::inisial('Bu Sarah'))->toBe('S');
});

test('customer avatars show the first letters of two words', function () {
    expect(Nama::inisialCustomer('Toko Berkah Jaya'))->toBe('TB')
        ->and(Nama::inisialCustomer('Bu Wati'))->toBe('BW')
        ->and(Nama::inisialCustomer(' rina  maharani '))->toBe('RM')
        ->and(Nama::inisialCustomer('Yoga'))->toBe('YG')
        ->and(Nama::inisialCustomer('Sinta'))->toBe('SN')
        ->and(Nama::inisialCustomer('Ai'))->toBe('A')
        ->and(Nama::inisialCustomer(''))->toBe('');
});
