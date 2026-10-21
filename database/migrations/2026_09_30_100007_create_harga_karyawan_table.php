<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table HARGA_KARYAWAN; batas is a panel addition
     * ("4 pack / minggu").
     */
    public function up(): void
    {
        Schema::create('harga_karyawan', function (Blueprint $table) {
            $table->id();
            $table->string('varian_produk');
            $table->string('singkatan')->nullable();
            $table->string('jenis_produk')->nullable();
            $table->string('brand')->nullable();
            $table->string('gramasi')->nullable();
            $table->string('kategori_kemasan')->nullable();
            $table->text('rasa')->nullable();
            $table->string('satuan')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('batas')->nullable();
            $table->unsignedBigInteger('harga_karyawan')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('harga_karyawan');
    }
};
