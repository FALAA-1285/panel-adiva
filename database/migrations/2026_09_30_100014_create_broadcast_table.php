<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A broadcast campaign. jumlah_target is stored because drafts have no recipient
     * rows yet; every other count comes from broadcast_penerima.
     */
    public function up(): void
    {
        Schema::create('broadcast', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->text('segmen_keterangan')->nullable();
            $table->jsonb('filter')->nullable();
            $table->jsonb('opsi')->nullable();
            $table->text('pesan');
            $table->foreignId('gambar_produk_id')->nullable()->constrained('gambar_produk')->nullOnDelete();
            $table->string('mode', 20)->default('SEKARANG');
            $table->string('kecepatan', 20)->default('AMAN');
            $table->string('status', 20)->default('DRAF')->index();
            $table->unsignedInteger('jumlah_target')->default(0);
            $table->timestampTz('jadwal_at')->nullable();
            $table->timestampTz('mulai_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast');
    }
};
