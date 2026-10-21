<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table TEMPLATE_FOLLOWUP (status_aktif YA/TIDAK
     * becomes a boolean); judul, tahap and acuan_waktu are panel additions for Otomasi.
     */
    public function up(): void
    {
        Schema::create('template_followup', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('judul')->nullable();
            $table->text('kondisi_pemicu')->nullable();
            $table->string('tahap')->nullable();
            $table->string('acuan_waktu')->nullable();
            $table->text('pesan');
            $table->unsignedSmallInteger('jeda_hari')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_followup');
    }
};
