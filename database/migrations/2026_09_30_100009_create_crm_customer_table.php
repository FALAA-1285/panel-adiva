<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table CRM_CUSTOMER; sesi_wa (which bot number the
     * customer uses) and alasan_butuh_admin are panel additions.
     */
    public function up(): void
    {
        Schema::create('crm_customer', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->string('nomor_wa', 40)->nullable()->index();
            $table->string('nama')->nullable();
            $table->string('sesi_wa')->nullable();
            $table->string('tipe_customer', 20)->default('BELUM_JELAS');
            $table->string('tahap_funnel', 20)->default('BARU')->index();
            $table->string('skor_prospek', 10)->default('COLD');
            $table->text('minat_produk')->nullable();
            $table->string('lokasi_jualan')->nullable();
            $table->unsignedBigInteger('estimasi_modal')->nullable();
            $table->string('kota')->nullable();
            $table->text('ringkasan_terakhir')->nullable();
            $table->text('pesan_terakhir_customer')->nullable();
            $table->unsignedInteger('jumlah_followup')->default(0);
            $table->timestampTz('tgl_interaksi_terakhir')->nullable()->index();
            $table->timestampTz('tgl_followup_berikutnya')->nullable();
            $table->boolean('status_optout')->default(false);
            $table->boolean('butuh_admin')->default(false);
            $table->text('alasan_butuh_admin')->nullable();
            $table->text('catatan')->nullable();
            $table->text('catatan_tetap')->nullable();
            $table->unsignedInteger('mem_gen')->default(1);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_customer');
    }
};
