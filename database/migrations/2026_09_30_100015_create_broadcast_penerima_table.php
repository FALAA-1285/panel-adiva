<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per broadcast recipient: delivery status and what the customer did after.
     */
    public function up(): void
    {
        Schema::create('broadcast_penerima', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('broadcast')->cascadeOnDelete();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->string('chat_id');
            $table->string('sesi_wa')->nullable();
            $table->string('status', 20)->default('MENUNGGU');
            $table->timestampTz('terkirim_at')->nullable();
            $table->timestampTz('dibaca_at')->nullable();
            $table->timestampTz('dibalas_at')->nullable();
            $table->text('balasan')->nullable();
            $table->foreignId('log_order_id')->nullable()->constrained('log_order')->nullOnDelete();
            $table->boolean('naik_tahap')->default(false);
            $table->boolean('jadi_hot')->default(false);
            $table->boolean('minta_stop')->default(false);
            $table->text('keterangan_gagal')->nullable();
            $table->timestampsTz();

            $table->unique(['broadcast_id', 'chat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_penerima');
    }
};
