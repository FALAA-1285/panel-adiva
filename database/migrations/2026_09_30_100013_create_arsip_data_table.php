<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same shape as the n8n data table ARSIP_DATA: archived rows copied whole into data.
     */
    public function up(): void
    {
        Schema::create('arsip_data', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 30);
            $table->string('kunci');
            $table->timestampTz('tanggal')->nullable();
            $table->jsonb('data');
            $table->timestampTz('diarsipkan');
            $table->timestampsTz();

            $table->index(['jenis', 'kunci']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arsip_data');
    }
};
