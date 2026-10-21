<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table KEBIJAKAN; kata_pemicu is a panel addition
     * (comma separated, like gambar_produk.tags).
     */
    public function up(): void
    {
        Schema::create('kebijakan', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('topik');
            $table->text('pertanyaan_customer_umum')->nullable();
            $table->text('jawaban_resmi')->nullable();
            $table->text('kata_pemicu')->nullable();
            $table->string('status_data')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kebijakan');
    }
};
