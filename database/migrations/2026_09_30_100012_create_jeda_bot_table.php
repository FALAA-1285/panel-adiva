<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table JEDA_BOT. Rows are kept after LANJUT so the
     * table doubles as pause history: a pause is active while selesai_at is null.
     * sampai = null means "tanpa batas".
     */
    public function up(): void
    {
        Schema::create('jeda_bot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->string('nomor', 40)->nullable();
            $table->string('chat_id');
            $table->string('nama')->nullable();
            $table->string('sesi_wa')->nullable();
            $table->timestampTz('sejak');
            $table->timestampTz('sampai')->nullable();
            $table->string('oleh')->nullable();
            $table->text('alasan')->nullable();
            $table->boolean('sudah_diberi_tahu')->default(false);
            $table->timestampTz('selesai_at')->nullable();
            $table->text('catatan_selesai')->nullable();
            $table->string('diakhiri_oleh')->nullable();
            $table->timestampsTz();

            $table->index(['chat_id', 'selesai_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jeda_bot');
    }
};
