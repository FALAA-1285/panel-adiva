<?php

// Inisialisasi direktori penyimpanan sementara di /tmp untuk lingkungan Serverless Vercel
$storageDirs = [
    '/tmp/storage/framework/views',
    '/tmp/storage/framework/cache/data',
    '/tmp/storage/framework/sessions',
    '/tmp/storage/logs',
    '/tmp/storage/app/public',
    '/tmp/views',
];

foreach ($storageDirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Teruskan seluruh request masuk ke public/index.php bawaan Laravel
require __DIR__.'/../public/index.php';
