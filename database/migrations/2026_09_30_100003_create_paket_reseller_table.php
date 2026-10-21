<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table PAKET_RESELLER; isi_detail, berat and
     * tawarkan_ketika are panel additions for the Paket page.
     */
    public function up(): void
    {
        Schema::create('paket_reseller', function (Blueprint $table) {
            $table->id();
            $table->string('kode_paket')->unique();
            $table->string('nama_paket');
            $table->text('isi')->nullable();
            $table->jsonb('isi_detail')->nullable();
            $table->text('cocok_untuk')->nullable();
            $table->text('catatan')->nullable();
            $table->text('tawarkan_ketika')->nullable();
            $table->string('berat')->nullable();
            $table->unsignedBigInteger('harga')->nullable();
            $table->string('status_data')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paket_reseller');
    }
};
