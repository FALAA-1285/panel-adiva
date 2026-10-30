<?php

/*
 * Database backups (php artisan cadangan:buat), for BCA CSA butir 5 and 11. They run on the
 * VPS cron container, which has pg_dump; switch them on there with CADANGAN_AKTIF=true and
 * point CADANGAN_DISK at storage outside that server (an S3-compatible disk).
 */
return [
    'aktif' => (bool) env('CADANGAN_AKTIF', false),

    // a disk from config/filesystems.php
    'disk' => env('CADANGAN_DISK', 'local'),

    'folder' => env('CADANGAN_FOLDER', 'cadangan/basis-data'),

    // how long a backup is kept: every one for 30 days, the first of each month for 12
    // months, the first of each year for 10 years (transaction data and logs: 10 years)
    'simpan' => [
        'harian' => 30,
        'bulanan' => 12,
        'tahunan' => 10,
    ],
];
