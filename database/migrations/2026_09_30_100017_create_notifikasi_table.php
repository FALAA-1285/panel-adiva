<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Copy of every notification the workflows send to WhatsApp (ADMIN_WA / SARAH_WA).
     * Read state is shared by the whole team.
     */
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('waktu')->index();
            $table->string('jenis', 40);
            $table->string('kategori', 20);
            $table->string('penerima', 20)->default('ADMIN_WA');
            $table->string('judul');
            $table->text('detail')->nullable();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->foreignId('log_order_id')->nullable()->constrained('log_order')->nullOnDelete();
            $table->foreignId('broadcast_id')->nullable()->constrained('broadcast')->nullOnDelete();
            $table->timestampTz('dibaca_at')->nullable()->index();
            $table->timestampTz('selesai_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifikasi');
    }
};
