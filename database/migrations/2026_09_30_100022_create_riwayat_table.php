<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Timeline lines shown on an order or a customer ("13:05 Ongkir dicek lewat
     * Mengantar…", "Tahap → Order dibuat"). Finer grained than activity_log.
     */
    public function up(): void
    {
        Schema::create('riwayat', function (Blueprint $table) {
            $table->id();
            $table->morphs('subjek');
            $table->timestampTz('waktu');
            $table->string('jenis', 20)->default('CATATAN');
            $table->text('teks');
            $table->string('nada', 10)->default('gray');
            $table->jsonb('data')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riwayat');
    }
};
