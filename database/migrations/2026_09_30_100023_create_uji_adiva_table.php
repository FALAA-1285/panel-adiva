<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Uji tanya Adiva" questions asked from the panel (Katalog, Kebijakan, Prompt).
     * Never sent to WhatsApp or written to the CRM.
     */
    public function up(): void
    {
        Schema::create('uji_adiva', function (Blueprint $table) {
            $table->id();
            $table->string('bagian', 20)->index();
            $table->text('pertanyaan');
            $table->text('jawaban')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uji_adiva');
    }
};
