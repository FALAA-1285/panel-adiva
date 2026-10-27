<?php

use App\Http\Controllers\AktivitasController;
use App\Http\Controllers\Auth\DaftarController;
use App\Http\Controllers\Auth\LupaSandiController;
use App\Http\Controllers\Auth\MasukKodeController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\BuatBroadcastController;
use App\Http\Controllers\CatatOrderController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DetailPesananController;
use App\Http\Controllers\JedaController;
use App\Http\Controllers\Katalog\GambarController;
use App\Http\Controllers\Katalog\KaryawanController;
use App\Http\Controllers\Katalog\KebijakanController;
use App\Http\Controllers\Katalog\ProdukController;
use App\Http\Controllers\KatalogController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\OtomasiController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\PercakapanController;
use App\Http\Controllers\PesananController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\RingkasanController;
use App\Http\Controllers\SosmedController;
use App\Http\Controllers\TimController;
use App\Http\Controllers\UjiAdivaController;
use App\Http\Controllers\WahaSesiController;
use App\Http\Middleware\EnsureDemoMode;
use Illuminate\Support\Facades\Route;

// GET /masuk dan POST /masuk, /keluar didaftarkan oleh Fortify (config/fortify.php).
Route::middleware('guest')->group(function () {
    Route::inertia('/daftar', 'auth/daftar')->name('register');
    Route::post('/daftar/kode', [DaftarController::class, 'kirimKode'])
        ->middleware('throttle:daftar-kode')
        ->name('register.kode');
    Route::post('/daftar/kode-email', [DaftarController::class, 'kirimKodeEmail'])
        ->middleware('throttle:daftar-kode')
        ->name('register.kode-email');
    Route::post('/daftar/verifikasi', [DaftarController::class, 'verifikasi'])
        ->middleware('throttle:daftar-verifikasi')
        ->name('register.verifikasi');
    Route::post('/masuk/kode', [MasukKodeController::class, 'kirimKode'])
        ->middleware('throttle:masuk-kode')
        ->name('login.kode');
    Route::post('/masuk/kode/verifikasi', [MasukKodeController::class, 'verifikasi'])
        ->middleware('throttle:masuk-verifikasi')
        ->name('login.kode.verifikasi');
    Route::inertia('/lupa-sandi', 'auth/lupa-sandi')->name('password.request');
    Route::post('/lupa-sandi/kode', [LupaSandiController::class, 'kirimKode'])
        ->middleware('throttle:reset-kode')
        ->name('password.kode');
    Route::post('/lupa-sandi/cek-kode', [LupaSandiController::class, 'cekKode'])
        ->middleware('throttle:reset-cek')
        ->name('password.cek');
    Route::post('/lupa-sandi/simpan', [LupaSandiController::class, 'simpan'])
        ->middleware('throttle:reset-cek')
        ->name('password.simpan');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [RingkasanController::class, 'index'])->name('home');
    Route::get('/ringkasan/ekspor', [RingkasanController::class, 'ekspor'])->name('ringkasan.ekspor');
    Route::get('/pesanan', [PesananController::class, 'index'])->name('pesanan');
    Route::get('/pesanan/ekspor', [PesananController::class, 'ekspor'])->name('pesanan.ekspor');
    // before /pesanan/{order}, so "baru" is not read as an order number
    Route::middleware('can:catat')->group(function () {
        Route::get('/pesanan/baru', [CatatOrderController::class, 'create'])->name('pesanan.catat');
        Route::post('/pesanan', [CatatOrderController::class, 'store'])->name('pesanan.simpan');
    });
    Route::get('/pesanan/{order:order_id}', DetailPesananController::class)->name('pesanan.detail');
    Route::get('/pesanan/{order:order_id}/invoice.pdf', [PesananController::class, 'invoicePdf'])->name('pesanan.invoice-pdf');
    Route::post('/pesanan/{order:order_id}/aksi', [PesananController::class, 'aksi'])->name('pesanan.aksi');
    // who may: catat, or order_karyawan for an employee order (UbahItemPesanan::alasanTidakBisa)
    Route::put('/pesanan/{order:order_id}/item', [PesananController::class, 'ubahItem'])->name('pesanan.item');
    Route::post('/pesanan/{order:order_id}/kirim-ulang-invoice', [PesananController::class, 'kirimUlangInvoice'])
        ->middleware('can:acc')->name('pesanan.kirim-ulang-invoice');
    // Katalog: every staff member may look; changing it is for supervisors ("Katalog, kebijakan, prompt")
    Route::get('/katalog', [KatalogController::class, 'produk'])->name('katalog.produk');
    Route::get('/katalog/paket', [KatalogController::class, 'paket'])->name('katalog.paket');
    Route::get('/katalog/gambar', [GambarController::class, 'index'])->name('katalog.gambar');
    Route::get('/katalog/kebijakan', [KebijakanController::class, 'index'])->name('katalog.kebijakan');
    Route::get('/katalog/karyawan', [KaryawanController::class, 'index'])->name('katalog.karyawan');
    Route::get('/katalog/karyawan/rekap', [KaryawanController::class, 'rekap'])->name('katalog.karyawan.rekap');

    Route::middleware('can:katalog')->group(function () {
        Route::post('/katalog/produk', [ProdukController::class, 'store'])->name('katalog.produk.tambah');
        Route::post('/katalog/produk/impor', [ProdukController::class, 'impor'])->name('katalog.produk.impor');
        Route::put('/katalog/produk/{produk}', [ProdukController::class, 'update'])->name('katalog.produk.simpan');
        Route::post('/katalog/produk/{produk}/arsip', [ProdukController::class, 'arsip'])->name('katalog.produk.arsip');
        Route::post('/katalog/produk/{produk}/pulihkan', [ProdukController::class, 'pulihkan'])->name('katalog.produk.pulihkan');
        Route::post('/katalog/paket', [KatalogController::class, 'tambahPaket'])->name('katalog.paket.tambah');
        Route::put('/katalog/paket/{paket}', [KatalogController::class, 'simpanPaket'])->name('katalog.paket.simpan');
        Route::post('/katalog/gambar', [GambarController::class, 'store'])->name('katalog.gambar.unggah');
        Route::patch('/katalog/gambar/{gambar}', [GambarController::class, 'update'])->name('katalog.gambar.ubah');
        Route::post('/katalog/gambar/{gambar}/file', [GambarController::class, 'gantiFile'])->name('katalog.gambar.file');
        Route::delete('/katalog/gambar/{gambar}', [GambarController::class, 'destroy'])->name('katalog.gambar.hapus');
        Route::post('/katalog/kebijakan', [KebijakanController::class, 'store'])->name('katalog.kebijakan.tambah');
        Route::put('/katalog/kebijakan/{kebijakan}', [KebijakanController::class, 'update'])->name('katalog.kebijakan.simpan');
        Route::post('/katalog/karyawan', [KaryawanController::class, 'store'])->name('katalog.karyawan.tambah');
        Route::put('/katalog/karyawan/harga', [KaryawanController::class, 'simpanHarga'])->name('katalog.karyawan.harga');
        Route::put('/katalog/karyawan/{karyawan}', [KaryawanController::class, 'update'])->name('katalog.karyawan.simpan');
    });

    Route::get('/pipeline', [PipelineController::class, 'index'])->name('pipeline');
    Route::middleware('can:balas')->group(function () {
        Route::patch('/pipeline/{customer}/tahap', [PipelineController::class, 'pindah'])->name('pipeline.pindah');
    });
    Route::get('/percakapan', [PercakapanController::class, 'index'])->name('percakapan');
    Route::get('/percakapan/pesan/{pesan}/lampiran', [PercakapanController::class, 'lampiran'])->name('percakapan.lampiran');
    Route::middleware('can:balas')->group(function () {
        Route::post('/percakapan/{customer}/balas', [PercakapanController::class, 'balas'])->name('percakapan.balas');
        Route::post('/percakapan/{customer}/jawab', [PercakapanController::class, 'jawab'])->name('percakapan.jawab');
        Route::post('/percakapan/{customer}/reset-memori', [PercakapanController::class, 'resetMemori'])->name('percakapan.reset-memori');
        Route::post('/percakapan/pesan/{pesan}/kirim-ulang', [PercakapanController::class, 'kirimUlang'])->name('percakapan.kirim-ulang');
        Route::post('/percakapan/template', [PercakapanController::class, 'simpanTemplate'])->name('percakapan.template.simpan');
        Route::delete('/percakapan/template/{template}', [PercakapanController::class, 'hapusTemplate'])->name('percakapan.template.hapus');
    });
    Route::get('/sosmed', [SosmedController::class, 'index'])->name('sosmed');
    Route::middleware('can:balas')->group(function () {
        Route::post('/sosmed/{kontak}/balas', [SosmedController::class, 'balas'])->name('sosmed.balas');
        Route::post('/sosmed/{kontak}/jeda', [SosmedController::class, 'jedakan'])->name('sosmed.jeda');
        Route::post('/sosmed/{kontak}/lanjut', [SosmedController::class, 'lanjut'])->name('sosmed.lanjut');
    });
    Route::middleware('can:pengaturan')->group(function () {
        Route::put('/sosmed/sambungan', [SosmedController::class, 'simpanSambungan'])->name('sosmed.sambungan');
        Route::post('/sosmed/sambungan/uji', [SosmedController::class, 'ujiSambungan'])->name('sosmed.sambungan.uji');
    });
    Route::get('/pelanggan', [PelangganController::class, 'index'])->name('pelanggan');
    Route::get('/pelanggan/ekspor', [PelangganController::class, 'ekspor'])->name('pelanggan.ekspor');
    Route::middleware('can:balas')->group(function () {
        Route::patch('/pelanggan/{customer}/optout', [PelangganController::class, 'optout'])->name('pelanggan.optout');
        Route::patch('/pelanggan/{customer}/penilaian', [PelangganController::class, 'penilaian'])->name('pelanggan.penilaian');
        Route::patch('/pelanggan/{customer}/tangani', [PelangganController::class, 'tangani'])->name('pelanggan.tangani');
        Route::patch('/pelanggan/{customer}/catatan', [PelangganController::class, 'catatan'])->name('pelanggan.catatan');
    });

    Route::middleware('can:balas')->group(function () {
        Route::get('/jeda', [JedaController::class, 'index'])->name('jeda');
        Route::post('/jeda', [JedaController::class, 'store'])->name('jeda.simpan');
        Route::post('/jeda/{jeda}/lanjut', [JedaController::class, 'lanjut'])->name('jeda.lanjut');
    });

    Route::middleware('can:monitoring')->group(function () {
        Route::get('/monitoring', MonitoringController::class)->name('monitoring');
    });

    Route::middleware('can:aktivitas')->group(function () {
        Route::get('/aktivitas', [AktivitasController::class, 'index'])->name('aktivitas');
        Route::get('/aktivitas/ekspor', [AktivitasController::class, 'ekspor'])->name('aktivitas.ekspor');
        Route::post('/aktivitas/{log}/kembalikan', [AktivitasController::class, 'kembalikan'])->name('aktivitas.kembalikan');
    });

    Route::get('/notifikasi', [NotifikasiController::class, 'index'])->name('notifikasi');
    Route::post('/notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua'])->name('notifikasi.baca-semua');
    Route::get('/notifikasi/{notifikasi}/buka', [NotifikasiController::class, 'buka'])->name('notifikasi.buka');

    Route::middleware('can:broadcast')->group(function () {
        Route::get('/broadcast', [BroadcastController::class, 'index'])->name('broadcast');
        Route::get('/broadcast/baru', [BuatBroadcastController::class, 'create'])->name('broadcast.baru');
        Route::get('/broadcast/perkiraan', [BuatBroadcastController::class, 'perkiraan'])->name('broadcast.perkiraan');
        Route::post('/broadcast', [BuatBroadcastController::class, 'store'])->name('broadcast.simpan');
        Route::post('/broadcast/uji', [BuatBroadcastController::class, 'uji'])->name('broadcast.uji');
        Route::post('/broadcast/{broadcast}/kirim-ulang', [BroadcastController::class, 'kirimUlang'])->name('broadcast.kirim-ulang');
        Route::delete('/broadcast/{broadcast}', [BroadcastController::class, 'destroy'])->name('broadcast.hapus');
        Route::get('/broadcast/{broadcast}/ekspor', [BroadcastController::class, 'ekspor'])->name('broadcast.ekspor');
    });

    Route::middleware('can:katalog')->group(function () {
        Route::get('/prompt', [PromptController::class, 'index'])->name('prompt');
        Route::post('/prompt/terbitkan', [PromptController::class, 'terbitkan'])->name('prompt.terbitkan');
        Route::get('/prompt/teks', [PromptController::class, 'teks'])->name('prompt.teks');
        Route::put('/prompt/bagian/{no}', [PromptController::class, 'ubahBagian'])->whereNumber('no')->name('prompt.bagian.ubah');
        Route::delete('/prompt/draf', [PromptController::class, 'buangDraf'])->name('prompt.draf.buang');
        Route::post('/prompt/{versi}/pulihkan', [PromptController::class, 'pulihkan'])->name('prompt.pulihkan');
        // Uji Adiva: answered by n8n in test mode, nothing goes to WhatsApp
        Route::post('/prompt/uji', [UjiAdivaController::class, 'prompt'])->name('prompt.uji');
        Route::post('/katalog/uji', [UjiAdivaController::class, 'katalog'])->name('katalog.uji');
    });

    Route::middleware('can:pengaturan')->group(function () {
        Route::get('/otomasi', [OtomasiController::class, 'index'])->name('otomasi');
        Route::patch('/otomasi/template/{template}', [OtomasiController::class, 'ubahTemplate'])->name('otomasi.template');
        Route::get('/pengaturan', [PengaturanController::class, 'index'])->name('pengaturan');
        Route::put('/pengaturan', [PengaturanController::class, 'simpan'])->name('pengaturan.simpan');
        Route::patch('/pengaturan/kabar-wa', [PengaturanController::class, 'kabarWa'])->name('pengaturan.kabar-wa');
        // Monitoring: "Lihat QR" and "Restart sesi" change the bot's WhatsApp link, so supervisors only
        Route::get('/monitoring/waha/qr', [WahaSesiController::class, 'qr'])->name('monitoring.qr');
        Route::post('/monitoring/waha/restart', [WahaSesiController::class, 'restart'])->name('monitoring.restart');
    });

    Route::middleware('can:tim')->group(function () {
        Route::get('/tim', [TimController::class, 'index'])->name('tim');
        Route::patch('/tim/keamanan', [TimController::class, 'simpanKeamanan'])->name('tim.keamanan');
        Route::delete('/tim/{user}/sesi', [TimController::class, 'keluarkan'])->name('tim.sesi.keluarkan');
        Route::delete('/tim/{user}/whatsapp', [TimController::class, 'keluarkanWhatsapp'])->name('tim.whatsapp.keluarkan');
        Route::post('/tim/staf', [TimController::class, 'undang'])->name('tim.staf.undang');
        Route::put('/tim/staf/{user}', [TimController::class, 'ubah'])->name('tim.staf.ubah');
        Route::post('/tim/staf/{user}/nonaktif', [TimController::class, 'nonaktifkan'])->name('tim.staf.nonaktif');
        Route::post('/tim/staf/{user}/aktif', [TimController::class, 'aktifkan'])->name('tim.staf.aktif');
        Route::post('/tim/staf/{user}/undangan', [TimController::class, 'kirimUlangUndangan'])->name('tim.staf.undangan');
    });
});

Route::middleware(EnsureDemoMode::class)->prefix('_demo')->name('demo.')->group(function () {
    Route::get('/login', [DemoController::class, 'login'])->name('login');
    Route::get('/komponen/{komponen}', [DemoController::class, 'komponen'])
        ->whereIn('komponen', ['sidebar', 'topbar'])
        ->name('komponen');
});
