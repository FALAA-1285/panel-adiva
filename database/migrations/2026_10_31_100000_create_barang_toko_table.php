<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Toko Sawojajar's goods, read every hour from Accurate (accurate:sinkron) so Adiva can
     * answer with the real name and price. Accurate is the source: the panel never edits a row
     * here, and only goods with a barcode (sold at the shop) are kept. Stock is not read.
     */
    public function up(): void
    {
        Schema::create('barang_toko', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('accurate_id')->unique();
            $table->string('kode')->nullable();
            $table->string('nama');
            $table->string('barcode')->index();
            $table->string('kategori')->nullable()->index();
            $table->string('satuan')->nullable();
            $table->unsignedBigInteger('harga')->nullable();
            $table->timestampTz('disinkron_at');
            $table->timestampsTz();
        });

        // every run, shown on Katalog → Barang toko
        Schema::create('sinkron_accurate', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('mulai');
            $table->timestampTz('selesai')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('jumlah')->nullable();
            $table->text('galat')->nullable();
            $table->foreignId('oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sinkron_accurate');
        Schema::dropIfExists('barang_toko');
    }
};
