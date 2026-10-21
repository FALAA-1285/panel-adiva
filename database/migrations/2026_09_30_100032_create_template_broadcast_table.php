<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * BroadcastNew.dc.html "Template": a preset for a new campaign (name, recipients,
     * message, photo and reply buttons). The first one fills the form on Buat kampanye.
     */
    public function up(): void
    {
        Schema::create('template_broadcast', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('nama_kampanye')->nullable();
            $table->jsonb('filter')->nullable();
            $table->text('pesan')->nullable();
            $table->foreignId('gambar_produk_id')->nullable()->constrained('gambar_produk')->nullOnDelete();
            $table->jsonb('tombol')->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_broadcast');
    }
};
