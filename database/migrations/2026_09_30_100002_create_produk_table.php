<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table PRODUK; target_pasar and harga_bertingkat
     * are panel additions for the Katalog page.
     */
    public function up(): void
    {
        Schema::create('produk', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_produk');
            $table->string('kategori_produk')->nullable();
            $table->string('brand')->nullable();
            $table->string('kategori_kemasan')->nullable();
            $table->string('gramasi')->nullable();
            $table->text('deskripsi')->nullable();
            $table->text('rasa')->nullable();
            $table->text('informasi_harga')->nullable();
            $table->string('target_pasar')->nullable();
            $table->jsonb('harga_bertingkat')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produk');
    }
};
