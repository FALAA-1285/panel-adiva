<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The n8n workflows behind Adiva. pengaturan holds values that live in workflow
     * nodes rather than CONFIG_TOKO (model, temperature, memory, merge wait).
     */
    public function up(): void
    {
        Schema::create('workflow', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 40)->unique();
            $table->string('nama');
            $table->string('n8n_workflow_id')->nullable();
            $table->unsignedSmallInteger('jumlah_node')->nullable();
            $table->string('jadwal')->nullable();
            $table->string('jadwal_keterangan')->nullable();
            $table->text('deskripsi')->nullable();
            $table->string('status', 20)->default('AKTIF');
            $table->jsonb('pengaturan')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow');
    }
};
