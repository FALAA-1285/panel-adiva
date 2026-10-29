<?php

/*
 * Only the messages the panel shows in Indonesian; any other key falls back to English
 * (fallback_locale). The password lines explain the one password rule (BCA CSA butir 23).
 */
return [
    'confirmed' => 'Kedua kata sandi tidak sama.',
    'min' => [
        'string' => 'Isian :attribute minimal :min karakter.',
    ],
    'password' => [
        'letters' => 'Kata sandi harus memuat huruf.',
        'mixed' => 'Kata sandi harus memuat huruf besar dan huruf kecil.',
        'numbers' => 'Kata sandi harus memuat angka.',
        'symbols' => 'Kata sandi harus memuat simbol, misalnya ! @ # atau ?.',
        'uncompromised' => 'Kata sandi ini pernah bocor di internet. Pilih kata sandi lain.',
    ],
    'attributes' => [
        'password' => 'kata sandi',
    ],
];
